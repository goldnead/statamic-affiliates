<?php

namespace Goldnead\Affiliates\Support;

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                Log::info('statamic-affiliates: payout held back, nothing payable yet or below the minimum.', [
                    'partner_id' => $first->partner_id,
                    'amount_cent' => $total,
                    'reason' => $total <= 0 ? 'not_payable' : 'below_minimum',
                ]);

                continue;
            }

            $partner = Partner::query()->find($first->partner_id);

            if ($partner === null || in_array($partner->status, [Partner::STATUS_REJECTED], true)) {
                Log::warning('statamic-affiliates: payout held back, the partner is missing or rejected.', [
                    'partner_id' => $first->partner_id,
                    'amount_cent' => $total,
                    'reason' => $partner === null ? 'partner_missing' : 'rejected',
                ]);

                continue;
            }

            $payout = $this->claim($rows, $first, $total, $partner);

            if ($payout !== null) {
                $created->push($payout);
            }
        }

        return $created;
    }

    /**
     * Lists the commissions under a new payout, atomically: the rows are
     * claimed with a conditional update and the payout only stays when every
     * one of them was ours. A concurrent build() that got there first leaves
     * nothing for this one, so no phantom payout with the full amount is made.
     *
     * @param  Collection<int, Commission>  $rows
     */
    protected function claim(Collection $rows, Commission $first, int $total, Partner $partner): ?Payout
    {
        return DB::transaction(function () use ($rows, $first, $total, $partner): ?Payout {
            $payout = Payout::query()->create([
                'brand_id' => $partner->brand_id,
                'partner_id' => $partner->getKey(),
                'amount_cent' => $total,
                'currency' => $first->currency,
                'commission_count' => $rows->count(),
                'status' => Payout::STATUS_OPEN,
                'reference' => 'AFF-'.Carbon::now()->format('Ymd').'-'.Str::upper(Str::random(5)),
            ]);

            $claimed = Commission::query()
                ->whereIn('id', $rows->pluck('id'))
                ->whereNull('payout_id')
                ->where('status', Commission::STATUS_APPROVED)
                ->update(['payout_id' => $payout->getKey(), 'updated_at' => Carbon::now()]);

            if ($claimed === $rows->count()) {
                // The amount was summed from rows read before the claim. A
                // partial refund in between raises `reversed_cent` but leaves
                // the row approved, so the claim still took it. Sum again from
                // what is claimed now: the payout is corrected, or dissolved
                // when nothing (or less than the minimum) is left.
                Payout::refreshTotals($payout->getKey());

                $payout = Payout::query()->acrossBrands()->find($payout->getKey());

                if ($payout === null) {
                    Log::info('statamic-affiliates: payout dissolved, a partial refund left it at zero or below the minimum.', [
                        'partner_id' => $first->partner_id,
                        'expected_cent' => $total,
                    ]);
                }

                return $payout;
            }

            // Not all of them were ours: give back what was claimed and drop
            // the payout. The next build() sees what is still free.
            Commission::query()->where('payout_id', $payout->getKey())->update(['payout_id' => null]);
            $payout->delete();

            Log::warning('statamic-affiliates: payout skipped, the commissions could not all be claimed (another run or a cancel got there first).', [
                'partner_id' => $first->partner_id,
                'expected' => $rows->count(),
                'claimed' => $claimed,
            ]);

            return null;
        });
    }

    /**
     * Null when there is nothing to mark: the list was dissolved in the
     * meantime (all reversed, or a claw-back left it at zero or below).
     */
    public function markPaid(Payout $payout): ?Payout
    {
        if ($payout->status === Payout::STATUS_PAID) {
            return $payout;
        }

        // Summed afresh: what the list owes is what its commissions are worth
        // now, not what they were worth when the list was made.
        Payout::refreshTotals($payout->getKey());

        $payout = Payout::query()->acrossBrands()->find($payout->getKey());

        // Only money that actually goes out can be marked as gone out.
        if ($payout === null || $payout->amount_cent <= 0) {
            return null;
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

            if ($partner === null) {
                Log::warning('statamic-affiliates: a payout in the CSV has no partner; its partner columns stay empty.', [
                    'payout_id' => $payout->getKey(),
                    'partner_id' => $payout->partner_id,
                ]);
            }

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
