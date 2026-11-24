<?php

use Goldnead\Affiliates\Integrations\PaymentsBridge;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

function silentEarn($test, $partner, int $amount = 10000)
{
    $payment = $test->makePayment(['amount_cent' => $amount]);
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $test->pay($payment);

    return $payment;
}

beforeEach(function () {
    config()->set('affiliates.commissions.hold_days', 0);
    config()->set('affiliates.payouts.minimum_cent', 0);
});

it('creates no payout when another run claimed the commissions in the meantime', function () {
    silentEarn($this, $this->makePartner());

    // The race: between reading the commissions and claiming them, a second
    // build() lists them under another payout.
    Payout::creating(function (): void {
        DB::table('affiliate_commissions')->update(['payout_id' => 9999]);
    });

    $created = app(Payouts::class)->build();

    expect($created)->toHaveCount(0)
        ->and(Payout::query()->acrossBrands()->count())->toBe(0);
});

it('does not cancel a claw-back row', function () {
    config()->set('affiliates.payouts.minimum_cent', 0);
    $partner = $this->makePartner();
    $payment = silentEarn($this, $partner);

    $payouts = app(Payouts::class);
    $payouts->markPaid($payouts->build()->sole());
    PaymentChargedBack::dispatch($payment->fresh(), 'chb_1', 10000, 'fraud');

    $clawback = Commission::query()->where('kind', Commission::KIND_CLAWBACK)->sole();

    expect(app(Ledger::class)->cancel($clawback))->toBeFalse();

    $fresh = $clawback->fresh();
    expect($fresh->status)->toBe(Commission::STATUS_APPROVED)
        ->and($fresh->amount_cent)->toBe(-3000);
});

it('tells the Control Panel when a cancel changed nothing', function () {
    $partner = $this->makePartner();
    $payment = silentEarn($this, $partner);

    $payouts = app(Payouts::class);
    $payouts->markPaid($payouts->build()->sole());
    PaymentChargedBack::dispatch($payment->fresh(), 'chb_1', 10000, 'fraud');
    $clawback = Commission::query()->where('kind', Commission::KIND_CLAWBACK)->sole();

    $this->actingAs($this->superUser())
        ->from('/cp')
        ->post(cp_route('affiliates.commissions.cancel', $clawback->id))
        ->assertSessionHas('error')
        ->assertSessionMissing('success');
});

it('does not book a refunded payment from the command', function () {
    $payment = $this->makePayment(['amount_cent' => 10000]);
    Referral::query()->create(['partner_id' => $this->makePartner()->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $payment->forceFill(['refunded_cent' => 10000])->save();

    $this->artisan('affiliates:book', ['payment' => $payment->id])->assertFailed();

    expect(Commission::query()->count())->toBe(0);
});

it('catches up a missed refund with affiliates:book --reverse', function () {
    $partner = $this->makePartner();
    $payment = silentEarn($this, $partner);

    // The refund event was lost: the payment says refunded, the ledger not.
    $payment->forceFill(['refunded_cent' => 10000])->save();
    expect(Commission::query()->sole()->status)->not->toBe(Commission::STATUS_REVERSED);

    $this->artisan('affiliates:book', ['payment' => $payment->id, '--reverse' => true])->assertSuccessful();

    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_REVERSED);
});

it('logs a failed refund with the payment, the event and the exception', function () {
    $payment = $this->makePayment();

    $this->mock(Ledger::class)->shouldReceive('reverse')->andThrow(new RuntimeException('db gone'));

    Log::spy();

    PaymentsBridge::refunded((object) ['payment' => $payment, 'isFull' => true]);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['payment_id'] === $payment->getKey()
        && $context['event'] === 'refund'
        && $context['exception'] instanceof RuntimeException
        && str_contains($message, 'affiliates:book'));
});

it('says false when cancelling a paid commission that was already clawed back', function () {
    silentEarn($this, $this->makePartner());
    $payouts = app(Payouts::class);
    $payouts->markPaid($payouts->build()->sole());
    $paid = Commission::query()->where('kind', Commission::KIND_SALE)->sole();

    expect(app(Ledger::class)->cancel($paid))->toBeTrue()
        ->and(app(Ledger::class)->cancel($paid->fresh()))->toBeFalse()
        ->and(Commission::query()->where('kind', Commission::KIND_CLAWBACK)->count())->toBe(1);
});

it('hands back what was claimed when only part of the commissions were still free', function () {
    $partner = $this->makePartner();
    silentEarn($this, $partner);
    silentEarn($this, $partner);
    $taken = Commission::query()->orderBy('id')->first()->id;

    Payout::creating(function () use ($taken): void {
        DB::table('affiliate_commissions')->where('id', $taken)->update(['payout_id' => 9999]);
    });

    expect(app(Payouts::class)->build())->toHaveCount(0)
        ->and(Payout::query()->acrossBrands()->count())->toBe(0)
        ->and(Commission::query()->whereNotNull('payout_id')->pluck('id')->all())->toBe([$taken]);
});

it('does not report success when --reverse has nothing to reverse', function () {
    $payment = silentEarn($this, $this->makePartner());

    $this->artisan('affiliates:book', ['payment' => $payment->id, '--reverse' => true])->assertFailed();
});

it('reverses the refunded share right after booking a partly refunded payment', function () {
    $payment = $this->makePayment(['amount_cent' => 10000]);
    Referral::query()->create(['partner_id' => $this->makePartner()->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $payment->forceFill(['refunded_cent' => 2500])->save();

    $this->artisan('affiliates:book', ['payment' => $payment->id])->assertSuccessful();

    expect(Commission::query()->sole()->payableCent())->toBe(2250);
});
