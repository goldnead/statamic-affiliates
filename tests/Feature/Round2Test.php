<?php

use Goldnead\Affiliates\Affiliates;
use Goldnead\Affiliates\Models\Click;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\JvContract;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Rate;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Attribution;
use Goldnead\Affiliates\Support\Money;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/*
 * Kritik Runde 2, Punkte 2 bis 7.
 */

// 2 ------------------------------------------------------------------------

it('counts only paid referred payments as sales and caps the conversion', function () {
    $partner = $this->makePartner();
    Click::query()->create(['partner_id' => $partner->id]);

    // Referred at checkout, never paid: not a sale.
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => 999, 'source' => 'link']);

    // Two paid through the link, one paid through a coupon.
    foreach (['link', 'link', 'coupon'] as $source) {
        $payment = $this->makePayment($source === 'coupon' ? ['discount_code' => 'CLARA10'] : []);
        if ($source === 'link') {
            Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
        } else {
            $partner->forceFill(['coupon_codes' => ['CLARA10']])->save();
        }
        $this->pay($payment);
    }

    $stats = app(Affiliates::class)->stats($partner->fresh());

    expect($stats['sales'])->toBe(3)
        ->and($stats['clicks'])->toBe(1)
        // Two link sales from one click would be 200 %; a coupon sale needs no click at all.
        ->and($stats['conversion'])->toBe(100.0);
});

// 3 ------------------------------------------------------------------------

it('refuses a coupon code another partner of the brand already owns', function () {
    $this->makePartner(['coupon_codes' => ['CLARA10']]);

    $this->actingAs($this->superUser())->postJson(cp_route('affiliates.partners.store'), [
        'name' => 'Ben', 'email' => 'ben@example.com', 'status' => 'active', 'coupon_codes' => ['clara10'],
    ])->assertJsonValidationErrors('coupon_codes');
});

it('attributes an ambiguous coupon to nobody rather than to the first match', function () {
    // Two owners, as data from before the validation existed.
    $this->makePartner(['coupon_codes' => ['DOPPELT']]);
    $this->makePartner(['name' => 'Ben', 'email' => 'ben@example.com', 'code' => 'ben', 'coupon_codes' => ['DOPPELT']]);

    expect(app(Attribution::class)->couponOwner('doppelt', 0))->toBeNull();

    $this->pay($this->makePayment(['discount_code' => 'DOPPELT']));
    expect(Commission::query()->count())->toBe(0);
});

// 4 ------------------------------------------------------------------------

it('books a plan switch as a renewal of the subscription\'s first payment', function () {
    $partner = $this->makePartner();
    Rate::query()->create(['product' => 'abo-plus', 'type' => 'percent', 'percent' => 30, 'recurring' => 'always', 'recurring_percent' => 10]);

    $first = $this->makePayment(['product' => 'abo', 'subscription_id' => 7]);
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $first->id, 'source' => 'link']);

    $switch = $this->makePayment([
        'product' => 'abo-plus',
        'amount_cent' => 2000,
        'meta' => ['proration' => true, 'subscription_change' => ['subscription_id' => 7, 'from' => 'abo', 'to' => 'abo-plus']],
    ]);
    $this->pay($switch);

    $c = Commission::query()->sole();
    expect($c->kind)->toBe(Commission::KIND_RECURRING)
        ->and($c->origin_payment_id)->toBe($first->id)
        ->and($c->amount_cent)->toBe(200);
});

it('never attributes a plan switch by the cookie the buyer carries now', function () {
    $this->makePartner();
    $this->app->instance('request', Request::create('/', 'POST', [], ['statamic_affiliate' => $this->referralCookie('clara')]));

    $switch = $this->makePayment(['meta' => ['subscription_change' => ['subscription_id' => 7]]]);
    $this->pay($switch);

    expect(Referral::query()->count())->toBe(0)->and(Commission::query()->count())->toBe(0);
});

// 5 ------------------------------------------------------------------------

it('lets a partner\'s own rate replace only the main rate by default', function () {
    $partner = $this->makePartner(['commission_percent' => 50]);
    Rate::query()->create(['product' => 'workbook', 'type' => 'percent', 'percent' => 30, 'bumps' => true, 'bump_percent' => 10]);

    $payment = $this->makePayment(['amount_cent' => 12000], [
        ['product' => 'kurs', 'amount_cent' => 10000],
        ['product' => 'workbook', 'amount_cent' => 2000, 'kind' => PaymentItem::KIND_BUMP],
    ]);
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $this->pay($payment);

    expect(Commission::query()->where('kind', 'sale')->value('amount_cent'))->toBe(5000)
        ->and(Commission::query()->where('kind', 'bump')->value('amount_cent'))->toBe(200);

    // Switched to "all": the partner's rate covers the bump too.
    Commission::query()->delete();
    config()->set('affiliates.commissions.partner_rate', 'all');
    $this->pay($payment);
    expect(Commission::query()->where('kind', 'bump')->value('amount_cent'))->toBe(1000);
});

it('does not pay a JV partner a referral commission on the same sale unless stacking is on', function () {
    $partner = $this->makePartner();
    JvContract::query()->create(['partner_id' => $partner->id, 'name' => 'JV', 'products' => [], 'percent' => 40]);

    $payment = $this->makePayment();
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $this->pay($payment);

    expect(Commission::query()->pluck('kind')->all())->toBe(['jv']);

    Commission::query()->delete();
    config()->set('affiliates.jv.stack_with_referral', true);
    $this->pay($payment);
    expect(Commission::query()->pluck('kind')->sort()->values()->all())->toBe(['jv', 'sale']);
});

// 6 ------------------------------------------------------------------------

it('dates a commission by the sale, keeping the booking date apart', function () {
    $partner = $this->makePartner();
    $payment = $this->makePayment(['paid_at' => now()->subDays(10)]);
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $this->pay($payment);

    $c = Commission::query()->sole();
    expect($c->sold_at->toDateString())->toBe(now()->subDays(10)->toDateString())
        ->and($c->created_at->toDateString())->toBe(now()->toDateString());

    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.commissions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.0.sold_at', $c->sold_at->toIso8601String())
            ->where('columns.0.field', 'sold_at'));
});

it('writes CSV amounts with a decimal comma in German', function () {
    config()->set('affiliates.commissions.hold_days', 0);
    config()->set('affiliates.payouts.minimum_cent', 0);
    $partner = $this->makePartner();
    $payment = $this->makePayment();
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $this->pay($payment);

    app()->setLocale('de');
    expect(app(Payouts::class)->csv(app(Payouts::class)->build()))->toContain(';30,00;EUR;');

    app()->setLocale('en');
    expect(Money::decimal(3000))->toBe('30.00');
});

it('keeps the payout table to six columns', function () {
    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.payouts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('columns', 6));
});

// 7 ------------------------------------------------------------------------

it('lets an invitation expire and binds it to the invited address', function () {
    Mail::fake();
    $partner = $this->makePartner();
    app(Affiliates::class)->invite($partner);
    $token = $partner->fresh()->invite_token;

    // Somebody else holding the link cannot take the record.
    $this->actingAs($this->member('fremd@example.com'))->get('/!/affiliates/invite/'.$token);
    expect($partner->fresh()->user_id)->toBeNull();

    // Past its life it no longer works, even for the right person.
    $user = $this->member('clara@example.com');
    $this->travel(15)->days();
    $this->actingAs($user)->get('/!/affiliates/invite/'.$token);
    expect($partner->fresh()->user_id)->toBeNull();
    $this->travelBack();

    $this->actingAs($user)->get('/!/affiliates/invite/'.$token);
    expect($partner->fresh()->user_id)->toBe((string) $user->id());
});

it('shows payout details in the edit form only to those who manage payouts', function () {
    $partner = $this->makePartner(['payout_details' => 'DE02120300000000202051']);

    $this->actingAs($this->cpUser('view affiliates', 'manage affiliates'))
        ->getJson(cp_route('affiliates.partners.edit', $partner->id))
        ->assertOk()
        ->assertJsonMissing(['payout_details' => 'DE02120300000000202051'])
        ->assertJsonPath('values.payout_details_masked', '•••• 2051');

    // Saving that form leaves the stored details alone.
    $this->actingAs($this->cpUser('view affiliates', 'manage affiliates'))
        ->patchJson(cp_route('affiliates.partners.update', $partner->id), [
            'name' => 'Clara Chor', 'email' => 'clara@example.com', 'code' => 'clara', 'status' => 'active',
        ])->assertOk();
    expect($partner->fresh()->payout_details)->toBe('DE02120300000000202051');

    $this->actingAs($this->superUser())
        ->getJson(cp_route('affiliates.partners.edit', $partner->id))
        ->assertJsonPath('values.payout_details', 'DE02120300000000202051');
});

it('warns on the partner screen about a coupon offers does not know', function () {
    $partner = $this->makePartner(['coupon_codes' => ['GIBTSNICHT']]);

    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.partners.show', $partner->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('partner.unknown_coupons', class_exists('\Goldnead\StatamicOffers\Models\Coupon') ? ['GIBTSNICHT'] : []));
});
