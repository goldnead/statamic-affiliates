<?php

use Goldnead\Affiliates\Events\CommissionEarned;
use Goldnead\Affiliates\Mail\CommissionEarnedMail;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Rate;
use Goldnead\Affiliates\Models\Referral;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

function refer($test, $payment, $partner): void
{
    Referral::query()->create([
        'brand_id' => $partner->brand_id,
        'partner_id' => $partner->id,
        'payment_id' => $payment->id,
        'source' => Referral::SOURCE_LINK,
    ]);
}

it('books the default percentage on a referred first payment, on hold', function () {
    $partner = $this->makePartner();
    $payment = $this->makePayment();
    refer($this, $payment, $partner);

    $this->pay($payment);

    $c = Commission::query()->sole();
    expect($c->amount_cent)->toBe(3000)
        ->and($c->base_cent)->toBe(10000)
        ->and($c->status)->toBe(Commission::STATUS_PENDING)
        ->and($c->available_at->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('books nothing for a payment nobody referred', function () {
    $this->makePartner();
    $this->pay($this->makePayment());

    expect(Commission::query()->count())->toBe(0);
});

it('books once, however often PaymentPaid arrives', function () {
    Event::fake([CommissionEarned::class]);
    $partner = $this->makePartner();
    $payment = $this->makePayment();
    refer($this, $payment, $partner);

    $this->pay($payment);
    $this->pay($payment);

    expect(Commission::query()->count())->toBe(1);
    Event::assertDispatchedTimes(CommissionEarned::class, 1);
});

it('uses the product rate, fixed or percent, before the default', function () {
    $partner = $this->makePartner();
    Rate::query()->create(['product' => 'kurs', 'type' => Rate::TYPE_FIXED, 'amount_cent' => 2500]);
    Rate::query()->create(['product' => 'coaching', 'type' => Rate::TYPE_PERCENT, 'percent' => 12.5]);

    $a = $this->makePayment(['product' => 'kurs']);
    $b = $this->makePayment(['product' => 'coaching', 'amount_cent' => 20000]);
    refer($this, $a, $partner);
    refer($this, $b, $partner);
    $this->pay($a);
    $this->pay($b);

    expect(Commission::query()->where('payment_id', $a->id)->value('amount_cent'))->toBe(2500)
        ->and(Commission::query()->where('payment_id', $b->id)->value('amount_cent'))->toBe(2500);
});

it('applies a partner\'s own percentage', function () {
    $partner = $this->makePartner(['commission_percent' => 50]);
    $payment = $this->makePayment();
    refer($this, $payment, $partner);

    $this->pay($payment);

    expect(Commission::query()->value('amount_cent'))->toBe(5000);
});

it('takes VAT off before computing', function () {
    config()->set('affiliates.commissions.vat_percent', 19);
    $partner = $this->makePartner();
    $payment = $this->makePayment(['amount_cent' => 11900]);
    refer($this, $payment, $partner);

    $this->pay($payment);

    expect(Commission::query()->value('base_cent'))->toBe(10000)
        ->and(Commission::query()->value('amount_cent'))->toBe(3000);
});

it('pays nothing on a partner\'s own purchase unless allowed', function () {
    $partner = $this->makePartner();
    $payment = $this->makePayment(['email' => 'Clara@Example.com']);
    refer($this, $payment, $partner);

    $this->pay($payment);
    expect(Commission::query()->count())->toBe(0);

    config()->set('affiliates.commissions.self_referral', true);
    $this->pay($payment);
    expect(Commission::query()->count())->toBe(1);
});

it('pays nothing to a suspended partner', function () {
    $partner = $this->makePartner(['status' => 'suspended']);
    $payment = $this->makePayment();
    refer($this, $payment, $partner);

    $this->pay($payment);

    expect(Commission::query()->count())->toBe(0);
});

it('earns on a bump line only when the product says so', function () {
    $partner = $this->makePartner();
    $payment = $this->makePayment(['amount_cent' => 12000], [
        ['product' => 'kurs', 'amount_cent' => 10000],
        ['product' => 'workbook', 'amount_cent' => 2000, 'kind' => PaymentItem::KIND_BUMP],
    ]);
    refer($this, $payment, $partner);

    $this->pay($payment);
    expect(Commission::query()->where('kind', Commission::KIND_BUMP)->count())->toBe(0);

    Commission::query()->delete();
    Rate::query()->create(['product' => 'workbook', 'type' => Rate::TYPE_PERCENT, 'percent' => 30, 'bumps' => true, 'bump_percent' => 50]);
    $this->pay($payment);

    expect(Commission::query()->where('kind', Commission::KIND_BUMP)->value('amount_cent'))->toBe(1000)
        ->and(Commission::query()->where('kind', Commission::KIND_SALE)->value('amount_cent'))->toBe(3000);
});

it('earns on an upsell of a referred payment only when the product says so', function () {
    $partner = $this->makePartner();
    $first = $this->makePayment();
    refer($this, $first, $partner);
    $upsell = $this->makePayment(['product' => 'masterclass', 'amount_cent' => 5000, 'parent_payment_id' => $first->id]);

    $this->pay($upsell);
    expect(Commission::query()->count())->toBe(0);

    Rate::query()->create(['product' => 'masterclass', 'type' => Rate::TYPE_PERCENT, 'percent' => 20, 'upsells' => true]);
    $this->pay($upsell);

    $c = Commission::query()->sole();
    expect($c->kind)->toBe(Commission::KIND_UPSELL)
        ->and($c->amount_cent)->toBe(1000)
        ->and($c->origin_payment_id)->toBe($first->id);
});

it('earns on renewals none, n times or always', function (string $recurring, ?int $times, int $expected) {
    $partner = $this->makePartner();
    Rate::query()->create(['product' => 'abo', 'type' => Rate::TYPE_PERCENT, 'percent' => 30, 'recurring' => $recurring, 'recurring_times' => $times, 'recurring_percent' => 10]);
    $first = $this->makePayment(['product' => 'abo']);
    refer($this, $first, $partner);

    foreach (range(1, 4) as $n) {
        $this->pay($this->makePayment(['product' => 'abo', 'meta' => ['cycle_of' => ['first_payment_id' => $first->id, 'subscription_id' => 1]]]));
    }

    $renewals = Commission::query()->where('kind', Commission::KIND_RECURRING)->orderBy('id')->get();

    expect($renewals)->toHaveCount($expected);

    if ($expected > 0) {
        expect($renewals->first()->amount_cent)->toBe(1000)
            ->and($renewals->pluck('cycle')->all())->toBe(range(1, $expected));
    }
})->with([
    'none' => ['none', null, 0],
    'limited to two' => ['limited', 2, 2],
    'always' => ['always', null, 4],
]);

it('is payable at once without a hold period', function () {
    config()->set('affiliates.commissions.hold_days', 0);
    $partner = $this->makePartner();
    $payment = $this->makePayment();
    refer($this, $payment, $partner);

    $this->pay($payment);

    expect(Commission::query()->value('status'))->toBe(Commission::STATUS_APPROVED);
});

it('releases commissions after the hold period', function () {
    $partner = $this->makePartner();
    $payment = $this->makePayment();
    refer($this, $payment, $partner);
    $this->pay($payment);

    $this->travel(31)->days();
    $this->artisan('affiliates:release')->assertSuccessful();

    expect(Commission::query()->value('status'))->toBe(Commission::STATUS_APPROVED);
    $this->travelBack();
});

it('mails the partner about a new commission, unless they switched it off', function () {
    Mail::fake();
    $partner = $this->makePartner();
    $payment = $this->makePayment();
    refer($this, $payment, $partner);

    $this->pay($payment);
    Mail::assertSent(CommissionEarnedMail::class, fn ($mail) => $mail->hasTo('clara@example.com'));

    $partner->forceFill(['notify' => false])->save();
    $other = $this->makePayment();
    refer($this, $other, $partner);
    $this->pay($other);

    Mail::assertSent(CommissionEarnedMail::class, 1);
});

it('renders the commission mail without the buyer', function () {
    $partner = $this->makePartner();
    $payment = $this->makePayment(['email' => 'geheim@example.com', 'name' => 'Geheime Person']);
    refer($this, $payment, $partner);
    $this->pay($payment);

    $html = (new CommissionEarnedMail(Commission::query()->sole(), $partner))->render();

    expect($html)->toContain('Clara Chor')
        ->toMatch('/30[.,]00/')
        ->not->toContain('geheim@example.com')
        ->not->toContain('Geheime Person');
});
