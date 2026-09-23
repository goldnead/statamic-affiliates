<?php

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\Affiliates\Support\Payouts;
use Illuminate\Support\Facades\DB;

function earn($test, $partner, int $amount): void
{
    $payment = $test->makePayment(['amount_cent' => $amount]);
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $test->pay($payment);
}

beforeEach(function () {
    config()->set('affiliates.commissions.hold_days', 0);
});

it('lists each partner above the minimum once, and carries the rest over', function () {
    config()->set('affiliates.payouts.minimum_cent', 5000);
    $clara = $this->makePartner();
    $ben = $this->makePartner(['name' => 'Ben', 'email' => 'ben@example.com', 'code' => 'ben']);

    earn($this, $clara, 10000);
    earn($this, $clara, 10000);
    earn($this, $ben, 10000);

    $created = app(Payouts::class)->build();

    expect($created)->toHaveCount(1)
        ->and($created->first()->partner_id)->toBe($clara->id)
        ->and($created->first()->amount_cent)->toBe(6000)
        ->and($created->first()->commission_count)->toBe(2)
        ->and(Commission::query()->where('partner_id', $ben->id)->value('payout_id'))->toBeNull();

    // Built again: nothing new.
    expect(app(Payouts::class)->build())->toHaveCount(0);
});

it('leaves commissions on hold out of the list', function () {
    config()->set('affiliates.commissions.hold_days', 14);
    config()->set('affiliates.payouts.minimum_cent', 0);
    earn($this, $this->makePartner(), 10000);

    expect(app(Payouts::class)->build())->toHaveCount(0);
});

it('marks a payout and its commissions as paid', function () {
    config()->set('affiliates.payouts.minimum_cent', 0);
    earn($this, $this->makePartner(), 10000);

    $payout = app(Payouts::class)->build()->sole();
    app(Payouts::class)->markPaid($payout);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID)
        ->and($payout->fresh()->paid_at)->not->toBeNull()
        ->and(Commission::query()->value('status'))->toBe(Commission::STATUS_PAID);
});

it('exports a CSV with the payout details and no formula injection', function () {
    config()->set('affiliates.payouts.minimum_cent', 0);
    $partner = $this->makePartner(['name' => '=HYPERLINK("x")', 'payout_method' => 'bank', 'payout_details' => "DE02 1203 0000 0000 2020 51\nClara Chor"]);
    earn($this, $partner, 10000);

    $csv = app(Payouts::class)->csv(app(Payouts::class)->build());

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain("'=HYPERLINK")
        ->toContain('DE02 1203 0000 0000 2020 51 Clara Chor')
        ->toContain('30.00;EUR');
});

it('stores payout details encrypted', function () {
    $partner = $this->makePartner(['payout_details' => 'DE02120300000000202051']);

    $raw = DB::table('affiliate_partners')->where('id', $partner->id)->value('payout_details');

    expect($raw)->not->toContain('DE02')
        ->and($partner->fresh()->payout_details)->toBe('DE02120300000000202051');
});
