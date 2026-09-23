<?php

use Goldnead\Affiliates\Affiliates;
use Goldnead\Affiliates\Models\Click;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Models\Rate;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Blueprints;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Inertia\Testing\AssertableInertia;

/*
 * Kritik Runde 3 (X1).
 */

function referred($test, $partner, array $attributes = [])
{
    $payment = $test->makePayment($attributes);
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $test->pay($payment);

    return $payment;
}

function refundFully($payment): void
{
    $payment->forceFill(['refunded_cent' => $payment->amount_cent])->save();
    PaymentRefunded::dispatch($payment->fresh(), $payment->amount_cent, true);
}

/** A partner with a claw-back of −3000 waiting and a new 6000 sale on an open list. */
function clawbackThenNewSale($test): array
{
    config()->set('affiliates.commissions.hold_days', 0);
    config()->set('affiliates.payouts.minimum_cent', 0);

    $partner = $test->makePartner();
    $first = referred($test, $partner);
    $payouts = app(Payouts::class);
    $payouts->markPaid($payouts->build()->sole());
    PaymentChargedBack::dispatch($first->fresh(), 'chb_1', 10000, null);

    $second = referred($test, $partner, ['amount_cent' => 20000]);
    $open = $payouts->build()->sole();
    expect($open->amount_cent)->toBe(3000);

    return [$partner, $second, $open];
}

// 1 ------------------------------------------------------------------------

it('dissolves an open list that a refund drives to zero or below, and refuses to pay it', function () {
    [, $second, $open] = clawbackThenNewSale($this);

    refundFully($second);

    expect(Payout::query()->find($open->id))->toBeNull()
        // The claw-back waits for the next list again.
        ->and(Commission::query()->where('kind', Commission::KIND_CLAWBACK)->value('payout_id'))->toBeNull()
        ->and(app(Payouts::class)->markPaid($open))->toBeNull()
        ->and(Payout::query()->where('status', Payout::STATUS_PAID)->count())->toBe(1);
});

it('dissolves it the same way when the sale is cancelled by hand', function () {
    [, $second, $open] = clawbackThenNewSale($this);

    app(Ledger::class)->cancel(Commission::query()->where('payment_id', $second->id)->where('kind', 'sale')->sole());

    expect(Payout::query()->find($open->id))->toBeNull()
        ->and(Commission::query()->where('kind', Commission::KIND_CLAWBACK)->value('payout_id'))->toBeNull();
});

it('never marks a list without a positive amount as paid', function () {
    $payout = Payout::query()->create(['partner_id' => $this->makePartner()->id, 'amount_cent' => -3000, 'currency' => 'EUR', 'reference' => 'AFF-X']);

    expect(app(Payouts::class)->markPaid($payout))->toBeNull()
        ->and(Payout::query()->where('status', Payout::STATUS_PAID)->count())->toBe(0);
});

// 6 (list side) ------------------------------------------------------------

it('dissolves an open list that falls below the minimum after a refund', function () {
    config()->set('affiliates.commissions.hold_days', 0);
    config()->set('affiliates.payouts.minimum_cent', 5000);
    $partner = $this->makePartner();
    $a = referred($this, $partner);
    referred($this, $partner);

    $open = app(Payouts::class)->build()->sole();
    expect($open->amount_cent)->toBe(6000);

    refundFully($a);

    expect(Payout::query()->find($open->id))->toBeNull()
        ->and(Commission::query()->where('status', Commission::STATUS_APPROVED)->whereNull('payout_id')->count())->toBe(1);
});

// 2 ------------------------------------------------------------------------

it('counts only paid, unrefunded renewals towards a limit, and not a plan switch', function () {
    $partner = $this->makePartner();
    Rate::query()->create(['product' => 'abo', 'type' => 'percent', 'percent' => 30, 'recurring' => 'limited', 'recurring_times' => 2, 'recurring_percent' => 10]);
    $first = $this->makePayment(['product' => 'abo', 'subscription_id' => 9]);
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $first->id, 'source' => 'link']);
    $this->pay($first);

    $cycle = fn () => $this->makePayment(['product' => 'abo', 'meta' => ['cycle_of' => ['first_payment_id' => $first->id]]]);

    // Renewal 1, refunded: it does not use up a slot.
    $one = $cycle();
    $this->pay($one);
    refundFully($one);

    // A plan switch in between: earns, but is no cycle.
    $this->pay($this->makePayment(['product' => 'abo', 'amount_cent' => 2000, 'meta' => ['subscription_change' => ['subscription_id' => 9]]]));

    $this->pay($cycle());
    $this->pay($cycle());
    $this->pay($cycle());

    $renewals = Commission::query()->where('kind', Commission::KIND_RECURRING)->orderBy('id')->get();

    expect($renewals->whereNotNull('cycle')->where('status', '!=', Commission::STATUS_REVERSED)->pluck('cycle')->values()->all())->toBe([1, 2])
        ->and($renewals->whereNull('cycle')->count())->toBe(1);
});

// 3 ------------------------------------------------------------------------

it('locks the payout method too without the payout permission, and masks a PayPal address', function () {
    $partner = $this->makePartner(['payout_method' => 'paypal', 'payout_details' => 'clara.brandt@paypal.com']);

    $this->actingAs($this->cpUser('view affiliates', 'manage affiliates'))
        ->getJson(cp_route('affiliates.partners.edit', $partner->id))
        ->assertJsonMissingPath('values.payout_method')
        ->assertJsonPath('values.payout_details_masked', 'cl•••@•••');

    $this->actingAs($this->cpUser('view affiliates', 'manage affiliates'))
        ->patchJson(cp_route('affiliates.partners.update', $partner->id), [
            'name' => 'Clara Chor', 'email' => 'clara@example.com', 'code' => 'clara', 'status' => 'active', 'payout_method' => 'bank',
        ])->assertOk();

    expect($partner->fresh()->payout_method)->toBe('paypal')
        ->and(Blueprints::mask('DE02 1203 0000 0000 2020 51'))->toBe('•••• 2051');
});

// 4 ------------------------------------------------------------------------

it('labels a claw-back row as offset, without a base', function () {
    clawbackThenNewSale($this);

    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.commissions.index'))
        ->assertInertia(function (AssertableInertia $page) {
            $row = collect($page->toArray()['props']['rows'])->firstWhere('kind', 'clawback');
            expect($row['rate'])->toBe(__('affiliates::cp.clawback_offset'))
                ->and($row['base'])->toBe('—');

            return $page;
        });
});

// 5 ------------------------------------------------------------------------

it('shows the go link next to the query link on the partner screen', function () {
    $partner = $this->makePartner();

    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.partners.show', $partner->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('partner.go_link', url('/!/affiliates/go/clara')));
});

// 6 (figures) --------------------------------------------------------------

it('does not count refunded sales or own purchases as sales', function () {
    $partner = $this->makePartner();
    Click::query()->create(['partner_id' => $partner->id]);

    referred($this, $partner);
    refundFully(referred($this, $partner));
    referred($this, $partner, ['email' => 'clara@example.com']);

    $stats = app(Affiliates::class)->stats($partner->fresh());

    expect($stats['sales'])->toBe(1)->and($stats['conversion'])->toBe(100.0);
});
