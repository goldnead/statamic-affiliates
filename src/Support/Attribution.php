<?php

namespace Goldnead\Affiliates\Support;

use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Referral;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Which partner a payment belongs to.
 *
 * Two ways in, and neither takes a partner from anything the buyer typed:
 *
 * - **the link**: while the buyer's own checkout request creates the payment,
 *   the referral this addon stored earlier (cookie or session) is written
 *   down against the payment id;
 * - **the coupon**: when the paid payment carries a code that belongs to a
 *   partner. offers looked the code up; this looks the owner up.
 *
 * A renewal and a follow-up offer have neither: they inherit the referral of
 * the payment they follow ({@see self::forSale()}).
 */
class Attribution
{
    public function __construct(protected Tracking $tracking) {}

    public function fromRequest(int $paymentId, int $paymentBrand, Request $request): ?Referral
    {
        $current = $this->tracking->current($request);

        if ($current === null || ! Brands::same($current['partner']->brand_id, $paymentBrand)) {
            return null;
        }

        return $this->write($paymentId, $current['partner'], Referral::SOURCE_LINK, null, $current['clicked_at']);
    }

    /**
     * The referral that counts for a paid sale, after the coupon has had its
     * say. Null when nobody referred it.
     */
    public function forSale(Sale $sale): ?Referral
    {
        $existing = Referral::query()->acrossBrands()->where('payment_id', $sale->originId())->first();

        if ($sale->role !== Sale::ROLE_FIRST) {
            return $existing;
        }

        $owner = $sale->couponCode !== null ? $this->couponOwner($sale->couponCode, $sale->brandId) : null;

        if ($owner === null) {
            return $existing;
        }

        $couponWins = (bool) Brands::runFor($owner->brand_id, fn () => config('affiliates.commissions.coupon_wins', true));

        if ($existing === null) {
            return $this->write($sale->paymentId, $owner, Referral::SOURCE_COUPON, $sale->couponCode, null)
                ?? Referral::query()->acrossBrands()->where('payment_id', $sale->paymentId)->first();
        }

        if ($couponWins && $existing->partner_id !== $owner->getKey()) {
            $existing->forceFill([
                'partner_id' => $owner->getKey(),
                'brand_id' => $owner->brand_id,
                'source' => Referral::SOURCE_COUPON,
                'coupon_code' => mb_strtoupper($sale->couponCode),
                'clicked_at' => null,
            ])->save();
        }

        return $existing;
    }

    /** The active partner a coupon code belongs to, in the payment's brand. */
    public function couponOwner(string $code, int $paymentBrand): ?Partner
    {
        $code = mb_strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        return Partner::query()
            ->acrossBrands()
            ->where('status', Partner::STATUS_ACTIVE)
            ->whereNotNull('coupon_codes')
            ->get()
            ->first(fn (Partner $partner) => in_array($code, $partner->couponCodes(), true)
                && Brands::same($partner->brand_id, $paymentBrand));
    }

    protected function write(int $paymentId, Partner $partner, string $source, ?string $coupon, mixed $clickedAt): ?Referral
    {
        try {
            return Referral::query()->create([
                'brand_id' => $partner->brand_id,
                'partner_id' => $partner->getKey(),
                'payment_id' => $paymentId,
                'source' => $source,
                'coupon_code' => $coupon !== null ? mb_strtoupper($coupon) : null,
                'clicked_at' => $clickedAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already written: a redelivered webhook, or the checkout retried.
            return null;
        }
    }
}
