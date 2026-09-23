<?php

namespace Goldnead\Affiliates\Support;

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The payout list: what each partner is owed, ready to pay by hand.
 *
 * The addon moves no money. ThriveCart pays through PayPal only, SamCart
 * exports a CSV; this does the second, for bank transfer and PayPal alike.
 */
class Payouts
{
    public function __construct(protected Ledger $ledger) {}

    /**
     * Gather every approved, unlisted commission of the current brand into
     * one payout per partner and currency. Partners below the minimum are
     * carried over.
     *
     * @return Collection<int, Payout>
     */
    public function build(): Collection
    {
        $this->ledger->release();

        $minimum = max(0, (int) config('affiliates.payouts.minimum_cent', 5000));
        $created = collect();

        $groups = Commission::query()
            ->where('status', Commission::STATUS_APPROVED)
            ->whereNull('payout_id')
            ->get()
            ->groupBy(fn (Commission $c) => $c->partner_id.'|'.$c->currency);

        foreach ($groups as $rows) {
            /** @var Commission $first */
            $first = $rows->first();
            $total = (int) $rows->sum(fn (Commission $c) => $c->payableCent());

            if ($total <= 0 || $total < $minimum) {
                continue;
            }

            $partner = Partner::query()->find($first->partner_id);

            if ($partner === null || in_array($partner->status, [Partner::STATUS_REJECTED], true)) {
                continue;
            }

            $created->push(DB::transaction(function () use ($rows, $first, $total, $partner): Payout {
                $payout = Payout::query()->create([
                    'brand_id' => $partner->brand_id,
                    'partner_id' => $partner->getKey(),
                    'amount_cent' => $total,
                    'currency' => $first->currency,
                    'commission_count' => $rows->count(),
                    'status' => Payout::STATUS_OPEN,
                    'reference' => 'AFF-'.Carbon::now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                ]);

                Commission::query()
                    ->whereIn('id', $rows->pluck('id'))
                    ->whereNull('payout_id')
                    ->update(['payout_id' => $payout->getKey(), 'updated_at' => Carbon::now()]);

                return $payout;
            }));
        }

        return $created;
    }

    public function markPaid(Payout $payout): Payout
    {
        if ($payout->status === Payout::STATUS_PAID) {
            return $payout;
        }

        // Summed afresh: what the list owes is what its commissions are worth
        // now, not what they were worth when the list was made.
        Payout::refreshTotals($payout->getKey());

        $payout = Payout::query()->acrossBrands()->find($payout->getKey());

        if ($payout === null) {
            // Everything on it was reversed in the meantime.
            return new Payout;
        }

        DB::transaction(function () use ($payout): void {
            $now = Carbon::now();

            $payout->forceFill(['status' => Payout::STATUS_PAID, 'paid_at' => $now])->save();

            Commission::query()
                ->where('payout_id', $payout->getKey())
                ->where('status', Commission::STATUS_APPROVED)
                ->update(['status' => Commission::STATUS_PAID, 'updated_at' => $now]);
        });

        return $payout;
    }

    /**
     * The CSV a bank upload or a PayPal mass payment starts from. Semicolon
     * separated with a BOM, so Excel in a German locale opens it as columns.
     *
     * @param  iterable<Payout>  $payouts
     */
    public function csv(iterable $payouts): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            __('affiliates::cp.csv_reference'),
            __('affiliates::cp.csv_partner'),
            __('affiliates::cp.csv_email'),
            __('affiliates::cp.csv_method'),
            __('affiliates::cp.csv_details'),
            __('affiliates::cp.csv_amount'),
            __('affiliates::cp.csv_currency'),
            __('affiliates::cp.csv_commissions'),
            __('affiliates::cp.csv_status'),
            __('affiliates::cp.csv_created'),
            __('affiliates::cp.csv_paid'),
        ], ';', '"', '\\');

        foreach ($payouts as $payout) {
            $partner = Partner::query()->acrossBrands()->find($payout->partner_id);

            fputcsv($handle, array_map([self::class, 'cell'], [
                $payout->reference,
                $partner?->name,
                $partner?->email,
                $partner?->payout_method ? __('affiliates::cp.method_'.$partner->payout_method) : '',
                str_replace(["\r\n", "\n", "\r"], ' ', (string) $partner?->payout_details),
                Money::decimal($payout->amount_cent),
                $payout->currency,
                $payout->commission_count,
                __('affiliates::cp.payout_'.$payout->status),
                $payout->created_at?->format('Y-m-d'),
                $payout->paid_at?->format('Y-m-d'),
            ]), ';', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * A cell a spreadsheet will not run as a formula. A partner's own name is
     * partner input, and `=HYPERLINK(...)` in it is the classic CSV injection.
     */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)
            ? "'".$value
            : $value;
    }
}
