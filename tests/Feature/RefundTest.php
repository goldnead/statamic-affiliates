<?php

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Events\PaymentRefunded;

function referredAndPaid($test): array
{
    $partner = $test->makePartner();
    $payment = $test->makePayment();
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $test->pay($payment);

    return [$partner, $payment];
}

it('reverses the commission on a full refund', function () {
    [, $payment] = referredAndPaid($this);

    $payment->forceFill(['refunded_cent' => 10000])->save();
    PaymentRefunded::dispatch($payment->fresh(), 10000, true);

    $c = Commission::query()->sole();
    expect($c->status)->toBe(Commission::STATUS_REVERSED)
        ->and($c->payableCent())->toBe(0)
        ->and($c->reason)->toBe('refund');
});

it('takes back a share on a partial refund, once', function () {
    [, $payment] = referredAndPaid($this);

    $payment->forceFill(['refunded_cent' => 2500])->save();
    PaymentRefunded::dispatch($payment->fresh(), 2500, false);
    PaymentRefunded::dispatch($payment->fresh(), 2500, false);

    $c = Commission::query()->sole();
    expect($c->status)->toBe(Commission::STATUS_PENDING)
        ->and($c->reversed_cent)->toBe(750)
        ->and($c->payableCent())->toBe(2250);
});

it('claws back from the next payout when the commission was already paid', function () {
    config()->set('affiliates.commissions.hold_days', 0);
    config()->set('affiliates.payouts.minimum_cent', 0);
    [$partner, $payment] = referredAndPaid($this);

    $payouts = app(Payouts::class);
    $payout = $payouts->build()->sole();
    $payouts->markPaid($payout);

    PaymentChargedBack::dispatch($payment->fresh(), 'chb_1', 10000, 'fraud');

    $clawback = Commission::query()->where('kind', Commission::KIND_CLAWBACK)->sole();
    expect($clawback->amount_cent)->toBe(-3000)
        ->and($clawback->status)->toBe(Commission::STATUS_APPROVED)
        ->and(Commission::query()->where('kind', Commission::KIND_SALE)->value('status'))->toBe(Commission::STATUS_PAID);

    // The same chargeback again changes nothing.
    PaymentChargedBack::dispatch($payment->fresh(), 'chb_1', 10000, 'fraud');
    expect(Commission::query()->where('kind', Commission::KIND_CLAWBACK)->count())->toBe(1);
});

it('cancels a commission by hand', function () {
    referredAndPaid($this);

    app(Ledger::class)->cancel(Commission::query()->sole());

    expect(Commission::query()->value('status'))->toBe(Commission::STATUS_REVERSED);
});
