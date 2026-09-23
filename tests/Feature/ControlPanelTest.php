<?php

use Goldnead\Affiliates\Mail\PartnerMail;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\JvContract;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Models\Rate;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Settings;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

it('renders every screen for a super user', function (string $route) {
    $this->makePartner();

    $this->actingAs($this->superUser())
        ->get(cp_route($route))
        ->assertOk();
})->with([
    'affiliates.partners.index',
    'affiliates.partners.create',
    'affiliates.commissions.index',
    'affiliates.payouts.index',
    'affiliates.rates.index',
    'affiliates.rates.create',
    'affiliates.jv.index',
    'affiliates.jv.create',
]);

it('never hands the listing a row key named actions', function () {
    // Core's <Listing> reads `actions` as server-side Statamic actions; our
    // own menu entries under that key blanked the whole page in the browser.
    $this->makePartner();

    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.partners.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows.0.row_actions')
            ->missing('rows.0.actions'));
});

it('shows the setup screen instead of a 500 when the migrations have not run', function () {
    Schema::drop('affiliate_payouts');

    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.payouts.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('setupRequired', true));
});

it('renders the partner detail', function () {
    $partner = $this->makePartner();

    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.partners.show', $partner->id))
        ->assertOk()
        ->assertSee('clara');
});

it('refuses every write route without the permission', function (string $method, string $route, array $params) {
    $partner = $this->makePartner();
    $rate = Rate::query()->create(['product' => 'kurs', 'type' => 'percent', 'percent' => 10]);
    $contract = JvContract::query()->create(['partner_id' => $partner->id, 'name' => 'JV', 'percent' => 10]);
    $payout = Payout::query()->create(['partner_id' => $partner->id, 'amount_cent' => 100, 'currency' => 'EUR', 'reference' => 'AFF-1']);
    $commission = Commission::query()->create(['partner_id' => $partner->id, 'payment_id' => 1, 'kind' => 'sale', 'base_cent' => 1, 'amount_cent' => 1, 'currency' => 'EUR', 'dedupe_key' => 'x']);

    $ids = ['partner' => $partner->id, 'rate' => $rate->id, 'contract' => $contract->id, 'payout' => $payout->id, 'commission' => $commission->id];
    $url = cp_route($route, array_intersect_key($ids, array_flip($params)));

    // Can see the programme, but not manage it or its money. As JSON: for a
    // browser request core turns the 403 into a redirect with a flash error.
    $this->actingAs($this->cpUser('view affiliates'))
        ->json($method, $url, ['status' => 'active', 'name' => 'x', 'email' => 'x@example.com'])
        ->assertForbidden();

    expect(Partner::query()->count())->toBe(1)
        ->and(Rate::query()->count())->toBe(1)
        ->and(JvContract::query()->count())->toBe(1)
        ->and($partner->fresh()->status)->toBe('active')
        ->and($payout->fresh()->status)->toBe('open')
        ->and($commission->fresh()->status)->toBe('pending');
})->with([
    ['POST', 'affiliates.partners.store', []],
    ['PATCH', 'affiliates.partners.update', ['partner']],
    ['POST', 'affiliates.partners.status', ['partner']],
    ['POST', 'affiliates.partners.invite', ['partner']],
    ['POST', 'affiliates.commissions.cancel', ['commission']],
    ['POST', 'affiliates.payouts.build', []],
    ['POST', 'affiliates.payouts.paid', ['payout']],
    ['GET', 'affiliates.payouts.csv', []],
    ['POST', 'affiliates.rates.store', []],
    ['PATCH', 'affiliates.rates.update', ['rate']],
    ['DELETE', 'affiliates.rates.destroy', ['rate']],
    ['POST', 'affiliates.jv.store', []],
    ['PATCH', 'affiliates.jv.update', ['contract']],
    ['DELETE', 'affiliates.jv.destroy', ['contract']],
]);

it('refuses the screens to a user without any affiliate permission', function () {
    $this->actingAs($this->cpUser())
        ->getJson(cp_route('affiliates.partners.index'))
        ->assertForbidden();

    $this->actingAs($this->cpUser('view affiliates'))
        ->getJson(cp_route('affiliates.payouts.index'))
        ->assertForbidden();
});

it('creates a partner through the publish form and sends the invitation', function () {
    Mail::fake();

    $response = $this->actingAs($this->superUser())->postJson(cp_route('affiliates.partners.store'), [
        'name' => 'Nora Neu',
        'email' => 'Nora@Example.com',
        'code' => '',
        'status' => 'active',
        'send_invitation' => true,
        'coupon_codes' => ['nora15'],
        'notify' => true,
    ]);

    $partner = Partner::query()->sole();
    $response->assertOk()->assertJson(['saved' => true, 'redirect' => cp_route('affiliates.partners.show', $partner->id)]);

    expect($partner->email)->toBe('nora@example.com')
        ->and($partner->code)->toStartWith('noraneu')
        ->and($partner->couponCodes())->toBe(['NORA15'])
        ->and($partner->invite_token)->not->toBeNull();
    Mail::assertSent(PartnerMail::class);
});

it('refuses a second partner with the same email or link code', function () {
    $this->makePartner();

    $this->actingAs($this->superUser())->postJson(cp_route('affiliates.partners.store'), [
        'name' => 'Clara 2', 'email' => 'clara@example.com', 'code' => 'clara', 'status' => 'active',
    ])->assertJsonValidationErrors(['email', 'code']);
});

it('approves a pending partner from the detail screen', function () {
    Mail::fake();
    $partner = $this->makePartner(['status' => 'pending', 'approved_at' => null]);

    $this->actingAs($this->superUser())
        ->post(cp_route('affiliates.partners.status', $partner->id), ['status' => 'active'])
        ->assertRedirect();

    expect($partner->fresh()->status)->toBe('active');
});

it('creates and edits a product rate', function () {
    $this->actingAs($this->superUser());

    $this->postJson(cp_route('affiliates.rates.store'), [
        'product' => 'kurs', 'type' => 'percent', 'percent' => 25, 'recurring' => 'limited', 'recurring_times' => 3, 'bumps' => true, 'upsells' => false, 'active' => true,
    ])->assertOk();

    $rate = Rate::query()->sole();
    expect((float) $rate->percent)->toBe(25.0)->and($rate->recurring_times)->toBe(3)->and($rate->bumps)->toBeTrue();

    $this->patchJson(cp_route('affiliates.rates.update', $rate->id), [
        'product' => 'kurs', 'type' => 'fixed', 'amount' => 19.9, 'recurring' => 'none', 'active' => true,
    ])->assertOk();

    expect($rate->fresh()->amount_cent)->toBe(1990)->and($rate->fresh()->percent)->toBeNull();

    $this->postJson(cp_route('affiliates.rates.store'), ['product' => 'kurs', 'type' => 'percent', 'percent' => 10])
        ->assertJsonValidationErrors('product');
});

it('creates a joint-venture contract', function () {
    $partner = $this->makePartner();

    $this->actingAs($this->superUser())->postJson(cp_route('affiliates.jv.store'), [
        'name' => 'Co-Workshop', 'partner_id' => (string) $partner->id, 'products' => ['workshop'], 'percent' => 40, 'active' => true, 'recurring' => true,
        'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31',
    ])->assertOk();

    $contract = JvContract::query()->sole();
    expect($contract->products)->toBe(['workshop'])
        ->and($contract->starts_on->toDateString())->toBe('2026-10-01');
});

it('builds a payout list, exports it and marks it paid', function () {
    config()->set('affiliates.commissions.hold_days', 0);
    config()->set('affiliates.payouts.minimum_cent', 0);
    $partner = $this->makePartner();
    $payment = $this->makePayment();
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $this->pay($payment);

    $this->actingAs($this->superUser());
    $this->post(cp_route('affiliates.payouts.build'))->assertRedirect()->assertSessionHas('success');

    $payout = Payout::query()->sole();

    $csv = $this->get(cp_route('affiliates.payouts.csv'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($csv->getContent())->toContain($payout->reference);

    $this->post(cp_route('affiliates.payouts.paid', $payout->id))->assertRedirect();
    expect($payout->fresh()->status)->toBe('paid');
});

it('registers the settings with the suite settings screen', function () {
    expect(app(SettingsRegistry::class)->has(Settings::settingsNamespace()))->toBeTrue();
});

it('registers the nav under one parent with the screens as children', function () {
    expect($this->navCallbacks)->not->toBeEmpty();
});
