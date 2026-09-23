<?php

namespace Goldnead\Affiliates\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one payment earned one partner.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $partner_id
 * @property int $payment_id
 * @property int|null $payment_item_id
 * @property int|null $origin_payment_id
 * @property int|null $jv_contract_id
 * @property int|null $reverses_id
 * @property string $kind
 * @property string|null $product
 * @property int|null $cycle
 * @property int $base_cent
 * @property int $amount_cent
 * @property int $reversed_cent
 * @property string $currency
 * @property string $status
 * @property string|null $rate
 * @property Carbon|null $available_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $reversed_at
 * @property string|null $reason
 * @property int|null $payout_id
 * @property string $dedupe_key
 * @property Carbon|null $created_at
 */
class Commission extends Model
{
    use HasBrand;

    /** The main product of a first payment. */
    public const KIND_SALE = 'sale';

    /** A renewal of a subscription the partner brought in. */
    public const KIND_RECURRING = 'recurring';

    /** An order bump on a referred payment. */
    public const KIND_BUMP = 'bump';

    /** A follow-up offer accepted after a referred payment. */
    public const KIND_UPSELL = 'upsell';

    /** A joint-venture share. */
    public const KIND_JV = 'jv';

    /** Taking back part of a commission that was already paid out. Negative. */
    public const KIND_CLAWBACK = 'clawback';

    /** Inside the hold period. */
    public const STATUS_PENDING = 'pending';

    /** Payable. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    /** Refunded or cancelled in full. Earns nothing. */
    public const STATUS_REVERSED = 'reversed';

    protected $table = 'affiliate_commissions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'base_cent' => 'integer',
            'amount_cent' => 'integer',
            'reversed_cent' => 'integer',
            'cycle' => 'integer',
            'available_at' => 'datetime',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PAID, self::STATUS_REVERSED];
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_SALE, self::KIND_RECURRING, self::KIND_BUMP, self::KIND_UPSELL, self::KIND_JV, self::KIND_CLAWBACK];
    }

    /** What is still owed for this row, after refunds. */
    public function payableCent(): int
    {
        return $this->amount_cent - $this->reversed_cent;
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
