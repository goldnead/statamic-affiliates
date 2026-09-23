<?php

use Goldnead\Affiliates\Models\Click;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Support\Tracking;
use Illuminate\Http\Request;

it('writes the referral cookie for an active partner code', function () {
    $this->makePartner();

    $response = $this->get('/landing?ref=clara');

    $response->assertOk()->assertCookie('statamic_affiliate');
    $value = json_decode($response->getCookie('statamic_affiliate', true)->getValue(), true);
    expect($value['c'])->toBe('clara');
});

it('ignores a code that is not an active partner', function (string $status) {
    $this->makePartner(['status' => $status]);

    $this->get('/landing?ref=clara')->assertCookieMissing('statamic_affiliate');
    $this->get('/landing?ref=nobody')->assertCookieMissing('statamic_affiliate');
})->with([Partner::STATUS_PENDING, Partner::STATUS_SUSPENDED, Partner::STATUS_REJECTED]);

it('writes no cookie without consent, but remembers the visit in the session', function () {
    config()->set('affiliates.consent.mode', 'auto');
    $this->makePartner();

    $response = $this->get('/landing?ref=clara');

    $response->assertCookieMissing('statamic_affiliate');
    expect(session(Tracking::SESSION_KEY)['c'])->toBe('clara');
});

it('asks statamic-consent for the service handle', function () {
    config()->set('affiliates.consent.mode', 'auto');
    $this->makePartner();

    $consent = rawurlencode(json_encode(['v' => 1, 'granted' => ['affiliates']]));

    $this->withUnencryptedCookie('statamic_consent', $consent)
        ->get('/landing?ref=clara')
        ->assertCookie('statamic_affiliate');
});

it('keeps nothing at all when consent is never asked and the fallback is off', function () {
    config()->set('affiliates.consent.mode', 'never');
    config()->set('affiliates.consent.session_fallback', false);
    $this->makePartner();

    $this->get('/landing?ref=clara')->assertCookieMissing('statamic_affiliate');
    expect(session(Tracking::SESSION_KEY))->toBeNull();
});

it('turns a session referral into a cookie once consent arrives', function () {
    config()->set('affiliates.consent.mode', 'auto');
    $this->makePartner();

    $consent = rawurlencode(json_encode(['v' => 1, 'granted' => ['affiliates']]));

    $this->withSession([Tracking::SESSION_KEY => ['c' => 'clara', 't' => time() - 60]])
        ->withUnencryptedCookie('statamic_consent', $consent)
        ->get('/landing')
        ->assertCookie('statamic_affiliate');
});

it('gives the visitor to the last partner by default', function () {
    $this->makePartner();
    $this->makePartner(['name' => 'Ben', 'email' => 'ben@example.com', 'code' => 'ben']);

    $response = $this->withCookie('statamic_affiliate', $this->referralCookie('clara'))->get('/landing?ref=ben');

    expect(json_decode($response->getCookie('statamic_affiliate', true)->getValue(), true)['c'])->toBe('ben');
});

it('keeps the first partner when attribution is first click', function () {
    config()->set('affiliates.tracking.attribution', 'first');
    $this->makePartner();
    $this->makePartner(['name' => 'Ben', 'email' => 'ben@example.com', 'code' => 'ben']);

    $this->withCookie('statamic_affiliate', $this->referralCookie('clara'))
        ->get('/landing?ref=ben')
        ->assertCookieMissing('statamic_affiliate');
});

it('lets the cookie expire with the configured life, counted from the click', function () {
    $this->makePartner();
    config()->set('affiliates.tracking.cookie_days', 7);

    $response = $this->get('/landing?ref=clara');
    $cookie = $response->getCookie('statamic_affiliate', true);

    expect($cookie->getExpiresTime())->toBeGreaterThan(time() + 6 * 86400)->toBeLessThanOrEqual(time() + 7 * 86400 + 5);

    // A cookie from eight days ago no longer counts.
    $old = $this->referralCookie('clara', time() - 8 * 86400);
    $request = Request::create('/landing', 'GET', [], ['statamic_affiliate' => $old]);
    expect(app(Tracking::class)->current($request))->toBeNull();
});

it('counts a click once per visit and stores no address', function () {
    $partner = $this->makePartner();

    $this->get('/landing?ref=clara');
    $this->get('/landing?ref=clara');

    expect(Click::query()->where('partner_id', $partner->id)->count())->toBe(1);
    expect(Click::query()->first()->landing)->toBe('/landing');
});

it('redirects the short link to a page on this site only', function () {
    $this->get('/!/affiliates/go/clara?to=/kurse/stimme')->assertRedirect('/kurse/stimme?ref=clara');
    $this->get('/!/affiliates/go/clara?to=https://evil.example')->assertRedirect('/?ref=clara');
    $this->get('/!/affiliates/go/clara?to=//evil.example')->assertRedirect('/?ref=clara');
});
