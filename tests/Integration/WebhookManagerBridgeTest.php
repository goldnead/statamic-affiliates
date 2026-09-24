<?php

/*
 * The partner moments as triggers of the real webhook manager, booted with its
 * own migrations and dispatch pipeline. The install without it is proved in a
 * separate process (tests/Unit/BootWithoutWebhookManagerTest.php).
 */

use Goldnead\Affiliates\Affiliates;
use Goldnead\Affiliates\Events\PartnerApproved;
use Goldnead\Affiliates\Integrations\WebhookManager\AffiliatesTrigger;
use Goldnead\Affiliates\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Tests\WebhookManagerTestCase;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(WebhookManagerTestCase::class);

function wmDetected(string $handle): TriggerDetected
{
    $found = collect(Event::dispatched(TriggerDetected::class))
        ->map(fn ($call) => $call[0])
        ->first(fn (TriggerDetected $d) => $d->trigger->triggerHandle === $handle);

    expect($found)->not->toBeNull("No TriggerDetected for [{$handle}].");

    return $found;
}

function wmHook(string $trigger, string $handle = 'hook'): OutboundWebhook
{
    return OutboundWebhook::create([
        'uuid' => (string) Str::uuid(), 'name' => $handle, 'handle' => $handle, 'enabled' => true,
        'trigger_type' => $trigger, 'url' => 'https://example.test/hook', 'method' => 'POST',
        'payload_type' => 'raw_json', 'queue_enabled' => true,
    ]);
}

function wmReferredAndPaid($test): array
{
    $partner = $test->makePartner([
        'payout_method' => 'iban',
        'payout_details' => 'DE89370400440532013000',
        'invite_token' => 'einladung-geheim',
        'notes' => 'interne Notiz',
    ]);
    $payment = $test->makePayment();
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $test->pay($payment);

    return [$partner, $payment];
}

it('offers the four moments under the automations handles, labelled in the viewers language', function () {
    $registered = array_values(array_filter(
        array_keys(WebhookManager::triggers()->all()),
        fn (string $handle) => str_starts_with($handle, 'affiliates.'),
    ));
    sort($registered);

    expect($registered)->toBe([
        'affiliates.commission_earned', 'affiliates.commission_reversed',
        'affiliates.partner_applied', 'affiliates.partner_approved',
    ])->and(WebhookManager::triggers()->get('affiliates.commission_earned'))->toBeInstanceOf(AffiliatesTrigger::class);

    app()->setLocale('de');
    expect(WebhookManager::triggers()->options()['affiliates.commission_earned'])->toBe('Partner: Provision verdient');
    app()->setLocale('en');
    expect(WebhookManager::triggers()->options()['affiliates.commission_earned'])->toBe('Affiliates: commission earned');
});

it('tells a receiver about an earned commission without the payout details', function () {
    Event::fake([TriggerDetected::class]);

    [$partner, $payment] = wmReferredAndPaid($this);
    $commission = Commission::query()->sole();

    $detected = wmDetected('affiliates.commission_earned');
    $body = $detected->trigger->payload;

    expect($detected->trigger->sourceType)->toBe('affiliates')
        ->and($detected->trigger->sourceReference)->toBe((string) $commission->id)
        ->and(array_keys($body))->toBe(['event', 'occurred_at', 'brand', 'subject_type', 'subject_id', 'commission', 'partner'])
        ->and([$body['subject_type'], $body['subject_id']])->toBe(['commission', $commission->id])
        ->and($body['commission']['amount_cent'])->toBe(3000)
        ->and($body['commission']['base_cent'])->toBe(10000)
        ->and($body['commission']['currency'])->toBe('EUR')
        ->and($body['commission']['payment_id'])->toBe($payment->id)
        ->and(array_keys($body['partner']))->toBe(['id', 'name', 'email', 'code', 'status', 'commission_percent', 'website', 'created_at', 'approved_at'])
        ->and($body['partner']['email'])->toBe('clara@example.com');

    $json = json_encode($body);
    foreach (['DE89370400440532013000', 'iban', 'einladung-geheim', 'interne Notiz', 'payout'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

it('reaches an outbound hook when a commission is reversed', function () {
    Queue::fake();
    wmHook('affiliates.commission_reversed');

    [, $payment] = wmReferredAndPaid($this);
    $payment->forceFill(['refunded_cent' => 10000])->save();
    PaymentRefunded::dispatch($payment->fresh(), 10000, true);

    Queue::assertPushed(ProcessOutboundDeliveryJob::class);
    $delivery = DB::table('webhook_deliveries')->where('trigger_type', 'affiliates.commission_reversed')->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->subject_type)->toBe('commission')
        ->and((string) $delivery->request_body)->toContain('"reason":"refund"')
        ->not->toContain('DE89370400440532013000');
});

it('carries the application message when somebody applies', function () {
    Event::fake([TriggerDetected::class]);

    app(Affiliates::class)->apply($this->member('neu@example.com'), ['name' => 'Neu', 'message' => 'Ich leite drei Chöre.']);

    $body = wmDetected('affiliates.partner_applied')->trigger->payload;

    expect($body['subject_type'])->toBe('partner')
        ->and($body['partner']['email'])->toBe('neu@example.com')
        ->and($body['partner']['message'])->toBe('Ich leite drei Chöre.')
        ->and($body['partner']['status'])->toBe('pending');
});

it('delivers through the hook of the rows brand when no brand is current', function () {
    config(['brand-context.multi_brand' => true]);
    Queue::fake();

    $zweite = (int) DB::table('brands')->insertGetId([
        'handle' => 'zweite', 'name' => 'Zweite', 'is_default' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $default = (int) DB::table('brands')->where('is_default', true)->value('id');

    app('brand-context')->runFor($default, fn () => wmHook('affiliates.partner_approved', 'default-hook'));
    app('brand-context')->runFor($zweite, fn () => wmHook('affiliates.partner_approved', 'zweite-hook'));
    app('brand-context')->forget();

    Event::listen(TriggerDetected::class, function (TriggerDetected $d) use (&$body) {
        $body = $d->trigger->payload;
    });

    $partner = $this->makePartner(['brand_id' => $zweite]);
    PartnerApproved::dispatch($partner);

    $hooks = DB::table('webhook_deliveries')
        ->join('webhook_outbounds', 'webhook_outbounds.id', '=', 'webhook_deliveries.outbound_webhook_id')
        ->pluck('webhook_outbounds.handle')->all();

    expect($body['brand'])->toBe(['id' => $zweite, 'handle' => 'zweite'])
        ->and($hooks)->toBe(['zweite-hook'])
        ->and(app('brand-context')->hasCurrent())->toBeFalse();
});

it('never lets a failing manager cost the commission', function () {
    Event::listen(TriggerDetected::class, fn () => throw new RuntimeException('manager down'));

    wmReferredAndPaid($this);

    expect(Commission::query()->count())->toBe(1);
});

it('registers no second listener on a second boot, and the switch turns it off', function () {
    $this->app->make(WebhookManagerBridge::class)->boot($this->app->make('events'));

    $heard = [];
    Event::listen(TriggerDetected::class, function (TriggerDetected $d) use (&$heard) {
        $heard[] = $d->trigger->triggerHandle;
    });

    wmReferredAndPaid($this);
    expect($heard)->toBe(['affiliates.commission_earned']);

    config(['affiliates.webhook_manager.enabled' => false]);
    expect(WebhookManagerBridge::available())->toBeFalse();
});
