<?php

namespace Goldnead\Affiliates\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A partner: somebody who sends buyers and earns for it.
 *
 * @property int $id
 * @property int $brand_id
 * @property string|null $user_id
 * @property string $name
 * @property string $email
 * @property string $code
 * @property string $status
 * @property string|null $commission_percent
 * @property string|null $payout_method
 * @property string|null $payout_details
 * @property list<string>|null $coupon_codes
 * @property string|null $website
 * @property string|null $message
 * @property string|null $notes
 * @property bool $notify
 * @property string|null $invite_token
 * @property Carbon|null $invited_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 */
class Partner extends Model
{
    use HasBrand;

    /** Applied, not yet approved. Earns nothing; the link does not count. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REJECTED = 'rejected';

    /** Was active. Old commissions stay payable, new clicks do not count. */
    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'affiliate_partners';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'coupon_codes' => 'array',
            'payout_details' => 'encrypted',
            'notify' => 'boolean',
            'invited_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_REJECTED, self::STATUS_SUSPENDED];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'partner_id');
    }

    /** @return HasMany<Payout, $this> */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'partner_id');
    }

    /** @return HasMany<Click, $this> */
    public function clicks(): HasMany
    {
        return $this->hasMany(Click::class, 'partner_id');
    }

    /** @return HasMany<Referral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'partner_id');
    }

    /**
     * Coupon codes, upper-cased and without blanks. offers compares codes
     * case-insensitively; so does this.
     *
     * @return list<string>
     */
    public function couponCodes(): array
    {
        return collect($this->coupon_codes ?? [])
            ->filter(fn ($code) => is_string($code) && trim($code) !== '')
            ->map(fn (string $code) => mb_strtoupper(trim($code)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A fresh link code: lower-case, readable, not guessable in sequence.
     * Taken from the name when that gives something usable.
     */
    public static function freshCode(?string $name = null): string
    {
        $base = Str::of((string) $name)->slug('')->lower()->substr(0, 12)->toString();

        do {
            $code = ($base !== '' ? $base : 'p').Str::lower(Str::random(4));
        } while (static::query()->acrossBrands()->where('code', $code)->exists());

        return $code;
    }
}
