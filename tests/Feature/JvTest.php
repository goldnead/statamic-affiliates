<?php

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\JvContract;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\StatamicPayments\Models\PaymentItem;

function contract($partner, array $attributes = []): JvContract
{
    return JvContract::query()->create([
        'partner_id' => $partner->id,
        'name' => 'Co-Workshop',
        'products' => ['workshop'],
        'percent' => 40,
        ...$attributes,
    ]);
}

it('books a joint-venture share of the net amount, without any link', function () {
    config()->set('affiliates.commissions.vat_percent', 19);
    $partner = $this->makePartner();
    $contract = contract($partner);

    $this->pay($this->makePayment(['product' => 'workshop', 'amount_cent' => 11900]));

    $c = Commission::query()->sole();
    expect($c->kind)->toBe(Commission::KIND_JV)
        ->and($c->jv_contract_id)->toBe($contract->id)
        ->and($c->base_cent)->toBe(10000)
        ->and($c->amount_cent)->toBe(4000);
});

it('books nothing for a product outside the contract or outside its term', function () {
    $partner = $this->makePartner();
    contract($partner, ['starts_on' => now()->addDay()->toDateString()]);
    contract($partner, ['name' => 'Anderes', 'products' => ['anderes']]);

    $this->pay($this->makePayment(['product' => 'workshop']));

    expect(Commission::query()->count())->toBe(0);
});

it('covers every product when none are named, with its own rates for bumps and upsells', function () {
    $partner = $this->makePartner();
    contract($partner, ['products' => [], 'percent' => 50, 'bump_percent' => 10, 'upsell_percent' => 20]);

    $first = $this->makePayment(['amount_cent' => 12000], [
        ['product' => 'kurs', 'amount_cent' => 10000],
        ['product' => 'workbook', 'amount_cent' => 2000, 'kind' => PaymentItem::KIND_BUMP],
    ]);
    $this->pay($first);
    $this->pay($this->makePayment(['product' => 'extra', 'amount_cent' => 5000, 'parent_payment_id' => $first->id]));

    expect(Commission::query()->where('payment_id', $first->id)->value('amount_cent'))->toBe(5200)
        ->and(Commission::query()->where('kind', Commission::KIND_JV)->count())->toBe(2)
        ->and(Commission::query()->where('payment_id', '!=', $first->id)->value('amount_cent'))->toBe(1000);
});

it('shares renewals only when the contract says so', function () {
    $partner = $this->makePartner();
    contract($partner, ['products' => ['abo'], 'recurring' => false]);

    $first = $this->makePayment(['product' => 'abo']);
    $this->pay($first);
    $this->pay($this->makePayment(['product' => 'abo', 'meta' => ['cycle_of' => ['first_payment_id' => $first->id]]]));

    expect(Commission::query()->count())->toBe(1);
});

it('books a joint venture and a referral side by side', function () {
    $jv = $this->makePartner();
    $affiliate = $this->makePartner(['name' => 'Ben', 'email' => 'ben@example.com', 'code' => 'ben']);
    contract($jv);

    $payment = $this->makePayment(['product' => 'workshop']);
    Referral::query()->create(['partner_id' => $affiliate->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $this->pay($payment);

    expect(Commission::query()->where('partner_id', $jv->id)->value('kind'))->toBe(Commission::KIND_JV)
        ->and(Commission::query()->where('partner_id', $affiliate->id)->value('kind'))->toBe(Commission::KIND_SALE);
});
