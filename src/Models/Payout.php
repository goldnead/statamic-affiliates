<?php

namespace Goldnead\Affiliates\Models;

use Goldnead\Affiliates\Support\Brands;
use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One line of a payout list: what one partner is owed in one currency.
 * The addon moves no money; somebody pays and marks it as paid.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $partner_id
 * @property int $amount_cent
 * @property string $currency
 * @property int $commission_count
 * @property string $status
 * @property string $reference
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 */
class Payout extends Model
{
    use HasBrand;

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    protected $table = 'affiliate_payouts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_cent' => 'integer',
            'commission_count' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Sum an open payout from its commissions as they are now.
     *
     * A refund or a hand cancellation between "create list" and "mark as
     * paid" changes what the list owes; the stored total must follow, or the
     * CSV pays out money the ledger already took back. Fully reversed rows
     * leave the list; an open list left with nothing is deleted. A paid list
     * is history and is never touched.
     */
    public static function refreshTotals(?int $payoutId): void
    {
        if ($payoutId === null) {
            return;
        }

        $payout = static::query()->acrossBrands()->find($payoutId);

        if ($payout === null || $payout->status !== self::STATUS_OPEN) {
            return;
        }

        Commission::query()->acrossBrands()
            ->where('payout_id', $payout->getKey())
            ->where('status', Commission::STATUS_REVERSED)
            ->update(['payout_id' => null]);

        $rows = Commission::query()->acrossBrands()->where('payout_id', $payout->getKey())->get();
        $total = (int) $rows->sum(fn (Commission $c) => $c->payableCent());

        // Nothing left to pay, or less than the brand pays out at all: the
        // list is dissolved and its rows wait, unlisted, for the next one. A
        // claw-back on it would otherwise turn it negative, and a negative
        // list marked "paid" books money that never moved.
        $minimum = max(0, (int) Brands::runFor($payout->brand_id, fn () => config('affiliates.payouts.minimum_cent', 5000)));

        if ($rows->isEmpty() || $total <= 0 || $total < $minimum) {
            Commission::query()->acrossBrands()
                ->where('payout_id', $payout->getKey())
                ->update(['payout_id' => null]);

            $payout->delete();

            return;
        }

        $payout->forceFill([
            'amount_cent' => $total,
            'commission_count' => $rows->count(),
        ])->save();
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'payout_id');
    }
}
