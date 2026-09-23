<?php

namespace Goldnead\Affiliates\Support;

use Goldnead\Affiliates\Events\CommissionEarned;
use Goldnead\Affiliates\Events\CommissionReversed;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\JvContract;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Rate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Books, reverses and releases commissions.
 *
 * Every write is keyed ({@see Commission::$dedupe_key}), so booking the same
 * payment twice books it once, and a refund applied twice takes back once.
 */
class Ledger
{
    public function __construct(protected Attribution $attribution) {}

    /**
     * Everything a paid sale earns: the referring partner's commission and
     * every joint venture that covers it.
     *
     * @return list<Commission> the rows booked now
     */
    public function book(Sale $sale): array
    {
        $booked = [];

        $referral = $this->attribution->forSale($sale);
        $partner = $referral ? Partner::query()->acrossBrands()->find($referral->partner_id) : null;

        if ($partner !== null && $partner->isActive() && ! $this->isSelfReferral($partner, $sale)) {
            $booked = [...$booked, ...Brands::runFor($partner->brand_id, fn () => $this->bookReferral($partner, $sale))];
        }

        $contracts = JvContract::query()->acrossBrands()->where('active', true)->get();

        foreach ($contracts as $contract) {
            if (! Brands::same($contract->brand_id, $sale->brandId)) {
                continue;
            }

            $jvPartner = Partner::query()->acrossBrands()->find($contract->partner_id);

            if ($jvPartner === null || ! $jvPartner->isActive()) {
                continue;
            }

            $row = Brands::runFor($contract->brand_id, fn () => $this->bookJv($jvPartner, $contract, $sale));

            if ($row !== null) {
                $booked[] = $row;
            }
        }

        foreach ($booked as $commission) {
            CommissionEarned::dispatch($commission);
        }

        return $booked;
    }

    /**
     * Take back what a refund or chargeback undid. `$refundedCent` is the
     * running total refunded on the payment, so applying the same refund twice
     * changes nothing.
     */
    public function reverse(int $paymentId, int $paymentAmountCent, int $refundedCent, string $reason): void
    {
        if ($paymentAmountCent <= 0) {
            return;
        }

        $share = min(1, max(0, $refundedCent / $paymentAmountCent));

        $rows = Commission::query()
            ->acrossBrands()
            ->where('payment_id', $paymentId)
            ->where('kind', '!=', Commission::KIND_CLAWBACK)
            ->get();

        foreach ($rows as $commission) {
            $target = (int) round($commission->amount_cent * $share);

            DB::transaction(function () use ($commission, $target, $share, $reason): void {
                $fresh = Commission::query()->acrossBrands()->lockForUpdate()->find($commission->getKey());

                if ($fresh === null) {
                    return;
                }

                if ($fresh->status === Commission::STATUS_PAID) {
                    $this->clawBack($fresh, $target, $reason);

                    return;
                }

                if ($target <= $fresh->reversed_cent) {
                    return;
                }

                $full = $share >= 1 || $target >= $fresh->amount_cent;

                $fresh->forceFill([
                    'reversed_cent' => min($fresh->amount_cent, $target),
                    'status' => $full ? Commission::STATUS_REVERSED : $fresh->status,
                    'reversed_at' => now(),
                    'reason' => $reason,
                ])->save();

                CommissionReversed::dispatch($fresh);
            });
        }
    }

    /** Cancel one commission by hand, from the Control Panel. */
    public function cancel(Commission $commission, string $reason = 'manual'): void
    {
        if ($commission->status === Commission::STATUS_PAID) {
            $this->clawBack($commission, $commission->amount_cent, $reason);

            return;
        }

        if ($commission->status === Commission::STATUS_REVERSED) {
            return;
        }

        $commission->forceFill([
            'reversed_cent' => $commission->amount_cent,
            'status' => Commission::STATUS_REVERSED,
            'reversed_at' => now(),
            'reason' => $reason,
            'payout_id' => null,
        ])->save();

        CommissionReversed::dispatch($commission);
    }

    /**
     * Move every commission whose hold period is over to approved.
     *
     * @return int how many
     */
    public function release(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        return Commission::query()
            ->acrossBrands()
            ->where('status', Commission::STATUS_PENDING)
            ->where('available_at', '<=', $now)
            ->update(['status' => Commission::STATUS_APPROVED, 'approved_at' => $now, 'updated_at' => $now]);
    }

    /** @return list<Commission> */
    protected function bookReferral(Partner $partner, Sale $sale): array
    {
        $booked = [];

        if ($sale->role === Sale::ROLE_UPSELL) {
            $rate = $this->rateFor($sale->product);

            if (! $rate['upsells']) {
                return [];
            }

            $amount = $this->amount($rate, $partner, $this->net($sale->amountCent), $rate['percent']);
            $row = $this->write($partner, $sale, Commission::KIND_UPSELL, null, $sale->product, $this->net($sale->amountCent), $amount, null, null, $this->describe($rate, $partner, $rate['percent']));

            return $row ? [$row] : [];
        }

        if ($sale->role === Sale::ROLE_CYCLE) {
            $rate = $this->rateFor($sale->product);
            $cycle = $this->nextCycle($partner, $sale);

            if ($rate['recurring'] === Rate::RECURRING_NONE
                || ($rate['recurring'] === Rate::RECURRING_LIMITED && $cycle > (int) ($rate['recurring_times'] ?? 0))) {
                return [];
            }

            $percent = $rate['recurring_percent'] ?? $rate['percent'];
            $amount = $this->amount($rate, $partner, $this->net($sale->amountCent), $percent);
            $row = $this->write($partner, $sale, Commission::KIND_RECURRING, null, $sale->product, $this->net($sale->amountCent), $amount, $cycle, null, $this->describe($rate, $partner, $percent));

            return $row ? [$row] : [];
        }

        $fixedTaken = false;

        foreach ($sale->lines as $line) {
            $rate = $this->rateFor($line['product']);
            $base = $this->net($line['total_cent']);

            if ($line['kind'] === 'bump') {
                if (! $rate['bumps']) {
                    continue;
                }

                $percent = $rate['bump_percent'] ?? $rate['percent'];
                $amount = $this->amount($rate, $partner, $base, $percent);
                $row = $this->write($partner, $sale, Commission::KIND_BUMP, $line['id'], $line['product'], $base, $amount, null, null, $this->describe($rate, $partner, $percent));
            } else {
                // A fixed commission is paid once per sale, not once per line.
                if ($rate['type'] === Rate::TYPE_FIXED && $fixedTaken) {
                    continue;
                }

                $fixedTaken = $fixedTaken || $rate['type'] === Rate::TYPE_FIXED;
                $amount = $this->amount($rate, $partner, $base, $rate['percent']);
                $row = $this->write($partner, $sale, Commission::KIND_SALE, $line['id'], $line['product'], $base, $amount, null, null, $this->describe($rate, $partner, $rate['percent']));
            }

            if ($row !== null) {
                $booked[] = $row;
            }
        }

        return $booked;
    }

    protected function bookJv(Partner $partner, JvContract $contract, Sale $sale): ?Commission
    {
        if ($sale->role === Sale::ROLE_CYCLE && ! $contract->recurring) {
            return null;
        }

        $base = 0;
        $amount = 0;

        foreach ($sale->lines as $line) {
            if (! $contract->covers($line['product'], $sale->paidAt)) {
                continue;
            }

            $percent = match (true) {
                $sale->role === Sale::ROLE_UPSELL => $contract->upsell_percent ?? $contract->percent,
                $line['kind'] === 'bump' => $contract->bump_percent ?? $contract->percent,
                default => $contract->percent,
            };

            $net = $this->net($line['total_cent']);
            $base += $net;
            $amount += (int) round($net * (float) $percent / 100);
        }

        if ($base === 0) {
            return null;
        }

        return $this->write($partner, $sale, Commission::KIND_JV, null, $sale->product, $base, $amount, null, $contract, rtrim(rtrim((string) $contract->percent, '0'), '.').' %');
    }

    /**
     * The rate that applies to a product, as a plain array: its own row when
     * there is an active one, the configured default otherwise.
     *
     * @return array{type: string, percent: float|null, amount_cent: int|null, recurring: string, recurring_times: int|null, recurring_percent: float|null, bumps: bool, bump_percent: float|null, upsells: bool}
     */
    public function rateFor(string $product): array
    {
        $row = Rate::query()->where('product', $product)->where('active', true)->first();

        if ($row !== null) {
            return [
                'type' => $row->type,
                'percent' => $row->percent !== null ? (float) $row->percent : null,
                'amount_cent' => $row->amount_cent,
                'recurring' => $row->recurring,
                'recurring_times' => $row->recurring_times,
                'recurring_percent' => $row->recurring_percent !== null ? (float) $row->recurring_percent : null,
                'bumps' => $row->bumps,
                'bump_percent' => $row->bump_percent !== null ? (float) $row->bump_percent : null,
                'upsells' => $row->upsells,
            ];
        }

        $default = (array) config('affiliates.commissions.default', []);

        return [
            'type' => ($default['type'] ?? Rate::TYPE_PERCENT) === Rate::TYPE_FIXED ? Rate::TYPE_FIXED : Rate::TYPE_PERCENT,
            'percent' => isset($default['percent']) && is_numeric($default['percent']) ? (float) $default['percent'] : null,
            'amount_cent' => isset($default['amount_cent']) && is_numeric($default['amount_cent']) ? (int) $default['amount_cent'] : null,
            'recurring' => in_array($default['recurring'] ?? null, [Rate::RECURRING_LIMITED, Rate::RECURRING_ALWAYS], true) ? $default['recurring'] : Rate::RECURRING_NONE,
            'recurring_times' => isset($default['recurring_times']) && is_numeric($default['recurring_times']) ? (int) $default['recurring_times'] : null,
            'recurring_percent' => null,
            'bumps' => (bool) ($default['bumps'] ?? false),
            'bump_percent' => null,
            'upsells' => (bool) ($default['upsells'] ?? false),
        ];
    }

    /** The amount less VAT at the configured rate. */
    public function net(int $grossCent): int
    {
        $vat = (float) config('affiliates.commissions.vat_percent', 0);

        return $vat > 0 ? (int) round($grossCent * 100 / (100 + $vat)) : $grossCent;
    }

    /** @param  array<string, mixed>  $rate */
    protected function amount(array $rate, Partner $partner, int $base, ?float $percent): int
    {
        if ($rate['type'] === Rate::TYPE_FIXED) {
            return max(0, (int) ($rate['amount_cent'] ?? 0));
        }

        $percent = $partner->commission_percent !== null ? (float) $partner->commission_percent : $percent;

        return max(0, (int) round($base * (float) $percent / 100));
    }

    /** @param  array<string, mixed>  $rate */
    protected function describe(array $rate, Partner $partner, ?float $percent): string
    {
        if ($rate['type'] === Rate::TYPE_FIXED) {
            return 'fixed';
        }

        $percent = $partner->commission_percent !== null ? (float) $partner->commission_percent : (float) $percent;

        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').' %';
    }

    protected function isSelfReferral(Partner $partner, Sale $sale): bool
    {
        if (Brands::runFor($partner->brand_id, fn () => (bool) config('affiliates.commissions.self_referral', false))) {
            return false;
        }

        return $sale->email !== null && mb_strtolower(trim($sale->email)) === mb_strtolower(trim($partner->email));
    }

    /** Which renewal this is, counted per partner and first payment. */
    protected function nextCycle(Partner $partner, Sale $sale): int
    {
        $existing = Commission::query()->acrossBrands()
            ->where('partner_id', $partner->getKey())
            ->where('origin_payment_id', $sale->originId())
            ->where('kind', Commission::KIND_RECURRING)
            ->where('payment_id', '!=', $sale->paymentId)
            ->count();

        return $existing + 1;
    }

    protected function write(
        Partner $partner,
        Sale $sale,
        string $kind,
        ?int $itemId,
        string $product,
        int $base,
        int $amount,
        ?int $cycle,
        ?JvContract $contract,
        string $rateLabel,
    ): ?Commission {
        if ($amount <= 0) {
            return null;
        }

        $key = implode(':', [$sale->paymentId, $partner->getKey(), $kind, $itemId ?? 0, $contract?->getKey() ?? 0]);

        if (Commission::query()->acrossBrands()->where('dedupe_key', $key)->exists()) {
            return null;
        }

        $hold = max(0, (int) config('affiliates.commissions.hold_days', 30));
        $available = $sale->paidAt->copy()->addDays($hold);
        $due = $available->lte(Carbon::now());

        try {
            return Commission::query()->create([
                'brand_id' => $partner->brand_id,
                'partner_id' => $partner->getKey(),
                'payment_id' => $sale->paymentId,
                'payment_item_id' => $itemId,
                'origin_payment_id' => $sale->originId(),
                'jv_contract_id' => $contract?->getKey(),
                'kind' => $kind,
                'product' => $product,
                'cycle' => $cycle,
                'base_cent' => $base,
                'amount_cent' => $amount,
                'currency' => $sale->currency,
                'status' => $due ? Commission::STATUS_APPROVED : Commission::STATUS_PENDING,
                'approved_at' => $due ? Carbon::now() : null,
                'rate' => $rateLabel,
                'available_at' => $available,
                'dedupe_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * A commission already paid out cannot be edited; what the refund takes
     * back becomes a negative row that the next payout list deducts.
     */
    protected function clawBack(Commission $paid, int $target, string $reason): void
    {
        $already = (int) Commission::query()->acrossBrands()
            ->where('reverses_id', $paid->getKey())
            ->sum('amount_cent');

        $delta = $target + $already;

        if ($delta <= 0) {
            return;
        }

        $row = Commission::query()->create([
            'brand_id' => $paid->brand_id,
            'partner_id' => $paid->partner_id,
            'payment_id' => $paid->payment_id,
            'origin_payment_id' => $paid->origin_payment_id,
            'jv_contract_id' => $paid->jv_contract_id,
            'reverses_id' => $paid->getKey(),
            'kind' => Commission::KIND_CLAWBACK,
            'product' => $paid->product,
            'base_cent' => 0,
            'amount_cent' => -$delta,
            'currency' => $paid->currency,
            'status' => Commission::STATUS_APPROVED,
            'approved_at' => now(),
            'available_at' => now(),
            'reason' => $reason,
            'dedupe_key' => 'clawback:'.$paid->getKey().':'.$target,
        ]);

        CommissionReversed::dispatch($row);
    }
}
