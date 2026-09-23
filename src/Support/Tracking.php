<?php

namespace Goldnead\Affiliates\Support;

use Goldnead\Affiliates\Models\Click;
use Goldnead\Affiliates\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The referral a visitor carries: which partner sent them, and when.
 *
 * Stored as `{"c": code, "t": unix time}` in a cookie (only with consent,
 * see {@see self::mayUseCookie()}) and in the session (always, when the
 * fallback is on). The cookie is encrypted by Laravel's `EncryptCookies`
 * like every other cookie in the `web` group, so a visitor cannot write a
 * partner into it by hand; a code that is not an active partner is ignored
 * either way.
 */
class Tracking
{
    public const SESSION_KEY = 'affiliates.referral';

    public const CLICKED_KEY = 'affiliates.clicked';

    /**
     * Handle a page view: take a partner code from the query string, and
     * turn a session referral into a cookie once consent has arrived.
     */
    public function handle(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET')) {
            return;
        }

        $parameter = (string) config('affiliates.tracking.parameter', 'ref');
        $code = $request->query($parameter);

        if (is_string($code) && trim($code) !== '') {
            $this->capture($request, $response, trim($code));

            return;
        }

        $this->promote($request, $response);
    }

    /**
     * The referral this request carries, if its partner is still active.
     *
     * @return array{partner: Partner, clicked_at: Carbon|null, via: string}|null
     */
    public function current(Request $request): ?array
    {
        foreach (['cookie' => $this->fromCookie($request), 'session' => $this->fromSession($request)] as $via => $raw) {
            if ($raw === null) {
                continue;
            }

            $partner = $this->activePartner($raw['c']);

            if ($partner !== null) {
                return [
                    'partner' => $partner,
                    'clicked_at' => $raw['t'] > 0 ? Carbon::createFromTimestamp($raw['t']) : null,
                    'via' => $via,
                ];
            }
        }

        return null;
    }

    /** Whether the referral may go into a cookie for this visitor. */
    public function mayUseCookie(Request $request): bool
    {
        $mode = (string) config('affiliates.consent.mode', 'auto');

        if ($mode === 'always') {
            return true;
        }

        if ($mode !== 'auto') {
            return false;
        }

        $registry = '\Goldnead\StatamicConsent\Support\Registry';

        if (! class_exists($registry)) {
            return false;
        }

        try {
            return (bool) app($registry)->granted((string) config('affiliates.consent.service', 'affiliates'), $request);
        } catch (Throwable) {
            return false;
        }
    }

    public function activePartner(string $code): ?Partner
    {
        return Partner::query()
            ->acrossBrands()
            ->where('code', mb_strtolower($code))
            ->where('status', Partner::STATUS_ACTIVE)
            ->first();
    }

    protected function capture(Request $request, Response $response, string $code): void
    {
        $partner = $this->activePartner($code);

        if ($partner === null) {
            return;
        }

        $this->countClick($request, $partner);

        $existing = $this->current($request);

        if ($existing !== null
            && $existing['partner']->getKey() !== $partner->getKey()
            && config('affiliates.tracking.attribution', 'last') === 'first') {
            return;
        }

        if ($existing !== null && $existing['partner']->getKey() === $partner->getKey()) {
            // Same partner again: the first click keeps its time, so the
            // cookie's life is counted from the first visit.
            $this->store($request, $response, $partner->code, $existing['clicked_at']?->getTimestamp() ?? time());

            return;
        }

        $this->store($request, $response, $partner->code, time());
    }

    protected function promote(Request $request, Response $response): void
    {
        $session = $this->fromSession($request);

        if ($session === null || $this->fromCookie($request) !== null || ! $this->mayUseCookie($request)) {
            return;
        }

        if ($this->activePartner($session['c']) !== null) {
            $this->attachCookie($response, $session['c'], $session['t']);
        }
    }

    protected function store(Request $request, Response $response, string $code, int $clickedAt): void
    {
        if (config('affiliates.consent.session_fallback', true) && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, ['c' => $code, 't' => $clickedAt]);
        }

        if ($this->mayUseCookie($request)) {
            $this->attachCookie($response, $code, $clickedAt);
        }
    }

    protected function attachCookie(Response $response, string $code, int $clickedAt): void
    {
        $days = max(0, (int) config('affiliates.tracking.cookie_days', 30));

        if ($days === 0) {
            return;
        }

        // The cookie lives from the click, not from today: a promotion after
        // consent must not extend a referral that is already partly used up.
        $expires = $clickedAt + $days * 86400;

        if ($expires <= time()) {
            return;
        }

        $response->headers->setCookie(new Cookie(
            name: $this->cookieName(),
            value: (string) json_encode(['c' => $code, 't' => $clickedAt]),
            expire: $expires,
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }

    protected function countClick(Request $request, Partner $partner): void
    {
        if (! config('affiliates.tracking.count_clicks', true)) {
            return;
        }

        if ($request->hasSession()) {
            $seen = (array) $request->session()->get(self::CLICKED_KEY, []);

            if (in_array($partner->getKey(), $seen, true)) {
                return;
            }

            $request->session()->put(self::CLICKED_KEY, [...$seen, $partner->getKey()]);
        }

        Click::query()->create([
            'brand_id' => $partner->brand_id,
            'partner_id' => $partner->getKey(),
            'landing' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
        ]);
    }

    /** @return array{c: string, t: int}|null */
    protected function fromCookie(Request $request): ?array
    {
        return $this->decode($request->cookie($this->cookieName()));
    }

    /** @return array{c: string, t: int}|null */
    protected function fromSession(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get(self::SESSION_KEY);

        return is_array($value) ? $this->decode(json_encode($value)) : null;
    }

    /** @return array{c: string, t: int}|null */
    protected function decode(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! is_string($data['c'] ?? null) || $data['c'] === '') {
            return null;
        }

        $clickedAt = (int) ($data['t'] ?? 0);
        $days = (int) config('affiliates.tracking.cookie_days', 30);

        // A referral older than the configured life does not count, whichever
        // store it came from. The session one would otherwise outlive it on a
        // long-lived session.
        if ($days > 0 && $clickedAt > 0 && $clickedAt + $days * 86400 < time()) {
            return null;
        }

        return ['c' => $data['c'], 't' => $clickedAt];
    }

    public function cookieName(): string
    {
        return (string) config('affiliates.tracking.cookie_name', 'statamic_affiliate');
    }
}
