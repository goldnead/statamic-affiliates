<?php

namespace Goldnead\Affiliates\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Which partner a payment belongs to, and why.
 *
 * Written while the buyer's checkout request creates the payment (source
 * `link`, from the cookie or the session this addon set), or when the paid
 * payment carries a partner's coupon (source `coupon`). Never from a field
 * the buyer could fill in.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $partner_id
 * @property int $payment_id
 * @property string $source
 * @property string|null $coupon_code
 * @property Carbon|null $clicked_at
 * @property Carbon|null $created_at
 */
class Referral extends Model
{
    use HasBrand;

    public const SOURCE_LINK = 'link';

    public const SOURCE_COUPON = 'coupon';

    protected $table = 'affiliate_referrals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
