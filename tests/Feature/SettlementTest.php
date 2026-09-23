<?php

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\StatamicPayments\Events\PaymentRefunded;

/*
 * Money that crosses a payout: what is taken back must be exactly what was
 * paid out, and an open list must always say what its commissions are worth.
 * Kritik Runde 2, Punkt 1.
 */

beforeEach(function () {
    config()->set('affiliates.commissions.hold_days', 0);
    config()->set('affiliates.payouts.minimum_cent', 0);

    $this->partner = $this->makePartner();
    $this->payment = $this->makePayment();
    Referral::query()->create(['partner_id' => $this->partner->id, 'payment_id' => $this->payment->id, 'source' => 'link']);
    $this->pay($this->payment);
});

function refund($payment, int $total, bool $full): void
{
    $payment->forceFill(['refunded_cent' => $total])->save();
    PaymentRefunded::dispatch($payment->fresh(), $total, $full);
}

it('claws back only what was actually paid out after a partial refund before the payout', function () {
    // 25 % refunded while payable: 3000 → 2250 paid out.
    refund($this->payment, 2500, false);

    $payouts = app(Payouts::class);
    $payout = $payouts->build()->sole();
    expect($payout->amount_cent)->toBe(2250);
    $payouts->markPaid($payout);

    // Then the rest is refunded. Only the 2250 that went out come back.
    refund($this->payment, 10000, true);

    expect(Commission::query()->where('kind', Commission::KIND_CLAWBACK)->sum('amount_cent'))->toBe(-2250);

    // And the same refund again takes nothing more.
    refund($this->payment, 10000, true);
    expect(Commission::query()->where('kind', Commission::KIND_CLAWBACK)->sum('amount_cent'))->toBe(-2250);
});

it('recomputes an open payout when a refund arrives before it is marked paid', function () {
    $payouts = app(Payouts::class);
    $payout = $payouts->build()->sole();
    expect($payout->amount_cent)->toBe(3000);

    refund($this->payment, 5000, false);

    expect($payout->fresh()->amount_cent)->toBe(1500);

    $payouts->markPaid($payout->fresh());
    expect($payout->fresh()->amount_cent)->toBe(1500)
        ->and(app(Payouts::class)->csv([$payout->fresh()]))->toContain('15.00');

    // Refunded in full after that: 1500 went out, 1500 comes back.
    refund($this->payment, 10000, true);
    expect(Commission::query()->where('kind', Commission::KIND_CLAWBACK)->sum('amount_cent'))->toBe(-1500);
});

it('drops a fully refunded commission from an open payout', function () {
    $payout = app(Payouts::class)->build()->sole();

    refund($this->payment, 10000, true);

    expect(Payout::query()->find($payout->id))->toBeNull()
        ->and(Commission::query()->where('kind', '!=', Commission::KIND_CLAWBACK)->value('payout_id'))->toBeNull()
        ->and(Commission::query()->where('kind', Commission::KIND_CLAWBACK)->count())->toBe(0);
});

it('recomputes an open payout when a commission in it is cancelled by hand', function () {
    $second = $this->makePayment();
    Referral::query()->create(['partner_id' => $this->partner->id, 'payment_id' => $second->id, 'source' => 'link']);
    $this->pay($second);

    $payout = app(Payouts::class)->build()->sole();
    expect($payout->amount_cent)->toBe(6000)->and($payout->commission_count)->toBe(2);

    app(Ledger::class)->cancel(Commission::query()->where('payment_id', $second->id)->sole());

    expect($payout->fresh()->amount_cent)->toBe(3000)
        ->and($payout->fresh()->commission_count)->toBe(1);
});

it('sums a payout afresh when it is marked paid', function () {
    $payout = app(Payouts::class)->build()->sole();

    // Somebody changed a row behind the list's back.
    Commission::query()->where('payout_id', $payout->id)->update(['reversed_cent' => 1000]);

    app(Payouts::class)->markPaid($payout);

    expect($payout->fresh()->amount_cent)->toBe(2000);
});
