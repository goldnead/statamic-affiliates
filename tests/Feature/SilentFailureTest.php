<?php

use Goldnead\Affiliates\Affiliates;
use Goldnead\Affiliates\Integrations\PaymentsBridge;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Mailer;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\Affiliates\Support\Tracking;
use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Illuminate\Mail\Mailable;
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

function silentFailingMailer(): void
{
    app()->instance(Mailer::class, new class extends Mailer
    {
        public function send(Partner $partner, Mailable $mailable): bool
        {
            return false;
        }
    });
}

it('tells the Control Panel when the invitation mail did not go out, and logs it without the address', function () {
    silentFailingMailer();
    Log::spy();
    $partner = $this->makePartner(['status' => Partner::STATUS_PENDING]);

    $this->actingAs($this->superUser())
        ->from('/cp')
        ->post(cp_route('affiliates.partners.invite', $partner->id))
        ->assertSessionHas('error')
        ->assertSessionMissing('success');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['partner_id'] === $partner->id
        && ! str_contains(json_encode($context), 'clara@example.com'));
});

it('tells the Control Panel when the approval mail did not go out', function () {
    silentFailingMailer();
    $partner = $this->makePartner(['status' => Partner::STATUS_PENDING]);

    $this->actingAs($this->superUser())
        ->from('/cp')
        ->post(cp_route('affiliates.partners.status', $partner->id), ['status' => Partner::STATUS_ACTIVE])
        ->assertSessionHas('error')
        ->assertSessionMissing('success');
});

it('logs a partner whose payout is held back below the minimum, with the amount and the reason', function () {
    config()->set('affiliates.commissions.hold_days', 0);
    config()->set('affiliates.payouts.minimum_cent', 5000);
    $partner = $this->makePartner();
    silentEarn($this, $partner);

    Log::spy();
    expect(app(Payouts::class)->build())->toHaveCount(0);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $context['partner_id'] === $partner->id
        && $context['amount_cent'] === 3000
        && $context['reason'] === 'below_minimum');
});

it('logs a rejected partner whose commissions are held back, with the amount and the reason', function () {
    $partner = $this->makePartner();
    silentEarn($this, $partner);
    $partner->forceFill(['status' => Partner::STATUS_REJECTED])->save();

    Log::spy();
    expect(app(Payouts::class)->build())->toHaveCount(0);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['partner_id'] === $partner->id
        && $context['amount_cent'] === 3000
        && $context['reason'] === 'rejected');
});

it('logs a failing consent check instead of silently refusing the cookie', function () {
    config()->set('affiliates.consent.mode', 'auto');
    // Bound under the same string Tracking resolves (with its leading backslash).
    $this->mock('\Goldnead\StatamicConsent\Support\Registry', function ($mock): void {
        $mock->shouldReceive('granted')->andThrow(new RuntimeException('consent store down'));
    });

    Log::spy();

    expect(app(Tracking::class)->mayUseCookie(request()))->toBeFalse();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['exception'] === 'consent store down');
});

it('does not carry an earlier mail failure over to a status change without a mail', function () {
    silentFailingMailer();
    $pending = $this->makePartner(['status' => Partner::STATUS_PENDING]);
    $other = $this->makePartner(['status' => Partner::STATUS_ACTIVE, 'email' => 'zweite@example.com', 'code' => 'ZWEI']);

    app(Affiliates::class)->invite($pending);
    expect(app(Affiliates::class)->mailFailed())->toBeTrue();

    app(Affiliates::class)->setStatus($other, Partner::STATUS_REJECTED);

    expect(app(Affiliates::class)->mailFailed())->toBeFalse();
});
