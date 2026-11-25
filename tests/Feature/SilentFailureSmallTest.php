<?php

use Goldnead\Affiliates\Integrations\PaymentsBridge;
use Goldnead\Affiliates\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Affiliates\Integrations\WebhookManager\WebhookPayload;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\Affiliates\Support\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('affiliates.commissions.hold_days', 0);
    config()->set('affiliates.payouts.minimum_cent', 0);
    config()->set('affiliates.signup.invite_days', 14);
});

it('logs a referral to a partner who is not active, with the partner and the payment', function () {
    $partner = $this->makePartner(['status' => Partner::STATUS_SUSPENDED]);
    $payment = $this->makePayment(['amount_cent' => 10000]);
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    Log::spy();

    $this->pay($payment);

    expect(Commission::query()->count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $context['partner_id'] === $partner->id
        && $context['payment_id'] === $payment->id);
});

it('logs a plan switch whose first payment cannot be found, with the payment and the subscription', function () {
    Log::spy();
    $payment = $this->makePayment(['meta' => ['subscription_change' => ['subscription_id' => 987654]]]);

    $sale = PaymentsBridge::sale($payment);

    expect($sale->role)->toBe(Sale::ROLE_SWITCH)
        ->and($sale->originPaymentId)->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['payment_id'] === $payment->id
        && $context['subscription_id'] === 987654);
});

it('answers 404 for a payout id that does not exist, not an empty CSV', function () {
    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.payouts.csv').'?payout=987654')
        ->assertNotFound();
});

it('logs a payout in the CSV whose partner is missing, with the payout and the partner id', function () {
    $partner = $this->makePartner();
    $payment = $this->makePayment(['amount_cent' => 10000]);
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $this->pay($payment);
    $payout = app(Payouts::class)->build()->sole();
    $payout->forceFill(['partner_id' => 987654])->save();
    Log::spy();

    app(Payouts::class)->csv([$payout->fresh()]);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['payout_id'] === $payout->id
        && $context['partner_id'] === 987654);
});

it('logs a partner moment that the webhook manager has no trigger for, with the trigger name', function () {
    app()->instance('webhook-manager', new class
    {
        public function triggers(): object
        {
            return new class
            {
                public function get(string $name): mixed
                {
                    return null;
                }
            };
        }
    });
    Log::spy();
    $handle = WebhookPayload::PREFIX.'missing_trigger';
    $bridge = app(WebhookManagerBridge::class);

    (new ReflectionMethod($bridge, 'handOver'))->invoke($bridge, $handle, new stdClass);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['trigger'] === $handle);
});

it('does not show a rejected or suspended partner the applied-pending message', function () {
    $user = $this->member();
    $this->makePartner(['status' => Partner::STATUS_REJECTED, 'user_id' => (string) $user->getAuthIdentifier()]);

    $this->actingAs($user)
        ->post('/!/affiliates/apply', ['terms' => '1'])
        ->assertSessionHasErrors('affiliates', null, 'affiliates')
        ->assertSessionMissing('affiliates.success');
});

it('tells an invitation that has run out apart from a wrong link', function () {
    $partner = $this->makePartner([
        'invite_token' => Str::random(40),
        'invited_at' => Carbon::now()->subDays(30),
    ]);

    $this->actingAs($this->member())
        ->get('/!/affiliates/invite/'.$partner->invite_token)
        ->assertSessionHas('affiliates.success', __('affiliates::messages.invite_expired'));
});

it('tells an invitation sent to another address that it is not for this account', function () {
    $partner = $this->makePartner([
        'email' => 'anders@example.com',
        'invite_token' => Str::random(40),
        'invited_at' => Carbon::now(),
    ]);

    $this->actingAs($this->member())
        ->get('/!/affiliates/invite/'.$partner->invite_token)
        ->assertSessionHas('affiliates.success', __('affiliates::messages.invite_wrong_address'));
});

it('tells a user who already has a partner account in this brand that the invitation cannot be used', function () {
    $user = $this->member();
    $this->makePartner(['code' => 'andere', 'email' => 'andere@example.com', 'user_id' => (string) $user->getAuthIdentifier()]);
    $partner = $this->makePartner([
        'code' => 'einladung',
        'invite_token' => Str::random(40),
        'invited_at' => Carbon::now(),
    ]);

    $this->actingAs($user)
        ->get('/!/affiliates/invite/'.$partner->invite_token)
        ->assertSessionHas('affiliates.success', __('affiliates::messages.invite_taken'));
});
