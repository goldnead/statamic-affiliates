<?php

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Rate;
use Goldnead\Affiliates\Models\Referral;

it('attributes a payment to the partner who owns its coupon, without any cookie', function () {
    $partner = $this->makePartner(['coupon_codes' => ['CLARA10']]);
    $payment = $this->makePayment(['discount_code' => 'clara10', 'amount_cent' => 9000, 'discount_cent' => 1000]);

    $this->pay($payment);

    $referral = Referral::query()->where('payment_id', $payment->id)->sole();
    expect($referral->partner_id)->toBe($partner->id)
        ->and($referral->source)->toBe(Referral::SOURCE_COUPON)
        ->and($referral->coupon_code)->toBe('CLARA10')
        ->and(Commission::query()->value('amount_cent'))->toBe(2700);
});

it('reads the code from the coupon terms offers leaves in meta', function () {
    $partner = $this->makePartner(['coupon_codes' => ['WINTER']]);
    $payment = $this->makePayment(['meta' => ['coupon' => ['code' => 'WINTER', 'percent' => 10, 'duration' => 'forever']]]);

    $this->pay($payment);

    expect(Referral::query()->where('payment_id', $payment->id)->value('partner_id'))->toBe($partner->id);
});

it('lets the coupon beat another partner\'s link, unless switched off', function (bool $couponWins, string $expected) {
    config()->set('affiliates.commissions.coupon_wins', $couponWins);
    $clara = $this->makePartner(['coupon_codes' => ['CLARA10']]);
    $ben = $this->makePartner(['name' => 'Ben', 'email' => 'ben@example.com', 'code' => 'ben']);

    $payment = $this->makePayment(['discount_code' => 'CLARA10']);
    Referral::query()->create(['partner_id' => $ben->id, 'payment_id' => $payment->id, 'source' => 'link']);

    $this->pay($payment);

    $owner = $expected === 'clara' ? $clara : $ben;
    expect(Commission::query()->sole()->partner_id)->toBe($owner->id);
})->with([
    'coupon wins' => [true, 'clara'],
    'link wins' => [false, 'ben'],
]);

it('ignores a coupon of a partner who is not active', function () {
    $this->makePartner(['coupon_codes' => ['CLARA10'], 'status' => 'suspended']);
    $payment = $this->makePayment(['discount_code' => 'CLARA10']);

    $this->pay($payment);

    expect(Referral::query()->count())->toBe(0)->and(Commission::query()->count())->toBe(0);
});

it('does not re-attribute a renewal by the coupon it inherited', function () {
    $clara = $this->makePartner(['coupon_codes' => ['CLARA10']]);
    $ben = $this->makePartner(['name' => 'Ben', 'email' => 'ben@example.com', 'code' => 'ben']);
    Rate::query()->create(['product' => 'abo', 'type' => 'percent', 'percent' => 30, 'recurring' => 'always']);

    $first = $this->makePayment(['product' => 'abo']);
    Referral::query()->create(['partner_id' => $ben->id, 'payment_id' => $first->id, 'source' => 'link']);

    $cycle = $this->makePayment(['product' => 'abo', 'meta' => ['coupon' => ['code' => 'CLARA10'], 'cycle_of' => ['first_payment_id' => $first->id]]]);
    $this->pay($cycle);

    expect(Commission::query()->sole()->partner_id)->toBe($ben->id);
});
