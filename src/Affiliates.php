<?php

namespace Goldnead\Affiliates;

use Goldnead\Affiliates\Events\PartnerApplied;
use Goldnead\Affiliates\Events\PartnerApproved;
use Goldnead\Affiliates\Mail\PartnerMail;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Brands;
use Goldnead\Affiliates\Support\Mailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;

/**
 * The partner programme's public API: sign-up, invitation, approval, the
 * tracking link and a partner's figures. The facade `Affiliates` resolves to
 * this class.
 */
class Affiliates
{
    public function __construct(protected Mailer $mailer) {}

    /** The partner record of a signed-in user, in the current brand. */
    public function partnerFor(?User $user): ?Partner
    {
        if ($user === null) {
            return null;
        }

        return Partner::query()->where('user_id', (string) $user->getAuthIdentifier())->first();
    }

    /**
     * A sign-up from the front end. Active at once when the brand approves
     * automatically, pending otherwise.
     *
     * @param  array{name?: string|null, website?: string|null, message?: string|null}  $data
     */
    public function apply(User $user, array $data): Partner
    {
        $existing = $this->partnerFor($user);

        if ($existing !== null) {
            return $existing;
        }

        $auto = config('affiliates.signup.approval', 'manual') === 'auto';
        $userName = method_exists($user, 'name') ? $user->name() : null;
        $name = trim((string) ($data['name'] ?? '')) ?: (string) ($userName ?: $user->email());

        $partner = Partner::query()->create([
            'user_id' => (string) $user->getAuthIdentifier(),
            'name' => $name,
            'email' => mb_strtolower((string) $user->email()),
            'code' => Partner::freshCode($name),
            'status' => $auto ? Partner::STATUS_ACTIVE : Partner::STATUS_PENDING,
            'approved_at' => $auto ? Carbon::now() : null,
            'website' => $data['website'] ?? null,
            'message' => $data['message'] ?? null,
        ]);

        PartnerApplied::dispatch($partner);

        if ($auto) {
            PartnerApproved::dispatch($partner);
        }

        return $partner;
    }

    /**
     * A partner created in the Control Panel, active from the start, with a
     * link that binds the record to whoever signs in and opens it.
     */
    public function invite(Partner $partner): Partner
    {
        $partner->forceFill([
            'status' => Partner::STATUS_ACTIVE,
            'approved_at' => $partner->approved_at ?? Carbon::now(),
            'invite_token' => Str::random(40),
            'invited_at' => Carbon::now(),
        ])->save();

        $this->mailer->send($partner, new PartnerMail($partner, PartnerMail::INVITATION, $this->inviteUrl($partner)));

        return $partner;
    }

    /** Bind an invitation to the signed-in user. Null when the token is unknown or used. */
    public function acceptInvite(string $token, User $user): ?Partner
    {
        if (strlen($token) < 20) {
            return null;
        }

        $partner = Partner::query()->acrossBrands()->where('invite_token', $token)->first();

        if ($partner === null) {
            return null;
        }

        // A link in a forwarded mail must not hand the record to whoever
        // clicks it: it binds only to the address it was sent to, and only
        // for as long as `signup.invite_days` says.
        $days = (int) Brands::runFor($partner->brand_id, fn () => config('affiliates.signup.invite_days', 14));

        if ($partner->invited_at === null || ($days > 0 && $partner->invited_at->copy()->addDays($days)->isPast())) {
            return null;
        }

        if (mb_strtolower(trim((string) $user->email())) !== mb_strtolower(trim($partner->email))) {
            return null;
        }

        // One user, one partner record per brand.
        $taken = Partner::query()->acrossBrands()
            ->where('brand_id', $partner->brand_id)
            ->where('user_id', (string) $user->getAuthIdentifier())
            ->whereKeyNot($partner->getKey())
            ->exists();

        if ($taken) {
            return null;
        }

        $partner->forceFill(['user_id' => (string) $user->getAuthIdentifier(), 'invite_token' => null])->save();

        return $partner;
    }

    public function approve(Partner $partner): Partner
    {
        if ($partner->isActive()) {
            return $partner;
        }

        $partner->forceFill(['status' => Partner::STATUS_ACTIVE, 'approved_at' => Carbon::now()])->save();

        PartnerApproved::dispatch($partner);

        Brands::runFor($partner->brand_id, function () use ($partner): void {
            if (config('affiliates.mail.approved', true)) {
                $this->mailer->send($partner, new PartnerMail($partner, PartnerMail::APPROVED, url('/')));
            }
        });

        return $partner;
    }

    public function setStatus(Partner $partner, string $status): Partner
    {
        if ($status === Partner::STATUS_ACTIVE) {
            return $this->approve($partner);
        }

        $partner->forceFill(['status' => $status])->save();

        return $partner;
    }

    /** The partner's tracking link to a page of this site. */
    public function link(Partner $partner, ?string $url = null): string
    {
        $url = $url === null || trim($url) === '' ? url('/') : url($url);
        $parameter = (string) config('affiliates.tracking.parameter', 'ref');
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.rawurlencode($parameter).'='.rawurlencode($partner->code);
    }

    /**
     * The short link `/!/affiliates/go/{code}`: notes the referral on a route
     * PHP always handles, so it also works behind full static caching.
     */
    public function goLink(Partner $partner, ?string $path = null): string
    {
        $link = url('/!/affiliates/go/'.rawurlencode($partner->code));

        return $path === null || trim($path) === '' ? $link : $link.'?to='.rawurlencode($path);
    }

    public function inviteUrl(Partner $partner): string
    {
        return url('/!/affiliates/invite/'.$partner->invite_token);
    }

    /**
     * A partner's figures, per currency where money is concerned.
     *
     * @return array{clicks: int, sales: int, conversion: float|null, money: list<array{currency: string, pending: int, approved: int, paid: int, reversed: int}>}
     */
    public function stats(Partner $partner): array
    {
        $clicks = $partner->clicks()->count();

        // Paid first payments only. A checkout that was started and never
        // paid leaves a referral without `paid_at`; it is not a sale.
        $paid = Referral::query()->acrossBrands()
            ->where('partner_id', $partner->getKey())
            ->whereNotNull('paid_at')
            ->whereNull('refunded_at')
            ->where('self_purchase', false);

        $sales = (clone $paid)->count();

        // Conversion is clicks becoming sales, so only sales that came through
        // a click count; a coupon sale needs no click. Never above 100 %.
        $linkSales = (clone $paid)->where('source', Referral::SOURCE_LINK)->count();

        $money = Commission::query()->acrossBrands()
            ->where('partner_id', $partner->getKey())
            ->get()
            ->groupBy('currency')
            ->map(function ($rows, $currency) {
                $sum = fn (string $status) => (int) $rows->where('status', $status)->sum(fn (Commission $c) => $c->payableCent());

                return [
                    'currency' => (string) $currency,
                    'pending' => $sum(Commission::STATUS_PENDING),
                    'approved' => $sum(Commission::STATUS_APPROVED),
                    'paid' => $sum(Commission::STATUS_PAID),
                    'reversed' => (int) $rows->sum('reversed_cent'),
                ];
            })
            ->values()
            ->all();

        return [
            'clicks' => $clicks,
            'sales' => $sales,
            'conversion' => $clicks > 0 ? min(100.0, round($linkSales / $clicks * 100, 1)) : null,
            'money' => $money,
        ];
    }
}
