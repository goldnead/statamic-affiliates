<?php

namespace Goldnead\Affiliates\Models;

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
