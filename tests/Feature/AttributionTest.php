<?php

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Tracking;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

it('notes the referral while the checkout request creates the payment', function () {
    $partner = $this->makePartner();

    $id = $this->withCookie('statamic_affiliate', $this->referralCookie('clara'))
        ->post('/test-checkout')
        ->json('id');

    $referral = Referral::query()->where('payment_id', $id)->first();

    expect($referral)->not->toBeNull()
        ->and($referral->partner_id)->toBe($partner->id)
        ->and($referral->source)->toBe(Referral::SOURCE_LINK);
});

it('takes the referral from the session when there is no cookie', function () {
    $partner = $this->makePartner();

    $id = $this->withSession([Tracking::SESSION_KEY => ['c' => 'clara', 't' => time()]])
        ->post('/test-checkout')
        ->json('id');

    expect(Referral::query()->where('payment_id', $id)->value('partner_id'))->toBe($partner->id);
});

it('never takes a partner from a request field', function () {
    $this->makePartner();

    $id = $this->post('/test-checkout', ['ref' => 'clara', 'affiliate' => 'clara', 'partner_id' => 1])->json('id');

    expect(Referral::query()->where('payment_id', $id)->exists())->toBeFalse();
});

it('books the commission on PaymentPaid for the referred payment', function () {
    $partner = $this->makePartner();

    $id = $this->withCookie('statamic_affiliate', $this->referralCookie('clara'))->post('/test-checkout')->json('id');

    $payment = Payment::query()->find($id);
    $payment->forceFill(['status' => Payment::STATUS_PAID, 'paid_at' => now()])->save();
    $this->pay($payment);

    $commission = Commission::query()->where('payment_id', $id)->first();

    expect($commission->partner_id)->toBe($partner->id)
        ->and($commission->amount_cent)->toBe(3000)
        ->and($commission->kind)->toBe(Commission::KIND_SALE);
});

it('does not attribute a renewal or an upsell to whatever cookie the buyer carries now', function () {
    $this->makePartner();
    $first = $this->makePayment();

    $this->app->instance('request', Request::create('/', 'POST', [], ['statamic_affiliate' => $this->referralCookie('clara')]));

    $upsell = $this->makePayment(['parent_payment_id' => $first->id]);
    $cycle = $this->makePayment(['meta' => ['cycle_of' => ['first_payment_id' => $first->id, 'subscription_id' => 1]]]);

    expect(Referral::query()->whereIn('payment_id', [$upsell->id, $cycle->id])->exists())->toBeFalse();
});

it('keeps the checkout working when the referral cannot be written', function () {
    $this->makePartner();
    Schema::drop('affiliate_referrals');

    $this->withCookie('statamic_affiliate', $this->referralCookie('clara'))
        ->post('/test-checkout')
        ->assertOk();

    expect(Payment::query()->count())->toBe(1);
});
