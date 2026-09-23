<?php

use Goldnead\Affiliates\Affiliates;
use Goldnead\Affiliates\Mail\PartnerMail;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Referral;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Antlers;
use Statamic\Facades\Entry;

function antlers(string $template): string
{
    // Third argument true: without it Antlers::parse() runs no tags at all.
    return (string) Antlers::parse($template, [], true);
}

it('turns an application away without a signed-in user', function () {
    $this->post('/!/affiliates/apply', ['terms' => '1'])->assertRedirect();

    expect(Partner::query()->count())->toBe(0);
});

it('takes an application from a signed-in user, pending by default', function () {
    $user = $this->member();

    $this->actingAs($user)->post('/!/affiliates/apply', ['name' => 'Clara', 'website' => 'https://clara.example', 'terms' => '1'])
        ->assertSessionHas('affiliates.success');

    $partner = Partner::query()->sole();
    expect($partner->status)->toBe(Partner::STATUS_PENDING)
        ->and($partner->user_id)->toBe((string) $user->id())
        ->and($partner->email)->toBe('clara@example.com');
});

it('activates an application at once with automatic approval', function () {
    config()->set('affiliates.signup.approval', 'auto');

    $this->actingAs($this->member())->post('/!/affiliates/apply', ['terms' => '1']);

    expect(Partner::query()->value('status'))->toBe(Partner::STATUS_ACTIVE);
});

it('refuses an application without accepted terms, and when sign-up is closed', function () {
    $user = $this->member();

    $this->actingAs($user)->post('/!/affiliates/apply', [])->assertSessionHasErrors('terms', null, 'affiliates');

    config()->set('affiliates.signup.enabled', false);
    $this->actingAs($user)->post('/!/affiliates/apply', ['terms' => '1'])->assertSessionHasErrors('affiliates', null, 'affiliates');

    expect(Partner::query()->count())->toBe(0);
});

it('sends an invitation that binds the record to whoever accepts it once', function () {
    Mail::fake();
    $partner = $this->makePartner(['status' => Partner::STATUS_PENDING]);

    app(Affiliates::class)->invite($partner);
    Mail::assertSent(PartnerMail::class, fn ($m) => $m->type === PartnerMail::INVITATION && $m->hasTo('clara@example.com'));

    $token = $partner->fresh()->invite_token;
    expect($partner->fresh()->status)->toBe(Partner::STATUS_ACTIVE);

    // Not signed in: sent to the login page, and back here afterwards.
    $this->get('/!/affiliates/invite/'.$token)->assertRedirectContains('/login?redirect=');

    $user = $this->member('someone@example.com');
    $this->actingAs($user)->get('/!/affiliates/invite/'.$token)->assertSessionHas('affiliates.success');

    expect($partner->fresh()->user_id)->toBe((string) $user->id())
        ->and($partner->fresh()->invite_token)->toBeNull();
});

it('mails the partner on approval', function () {
    Mail::fake();
    $partner = $this->makePartner(['status' => Partner::STATUS_PENDING]);

    app(Affiliates::class)->approve($partner);

    Mail::assertSent(PartnerMail::class, fn ($m) => $m->type === PartnerMail::APPROVED);
    expect($partner->fresh()->approved_at)->not->toBeNull();
});

it('shows the partner area through the tags', function () {
    config()->set('affiliates.commissions.hold_days', 0);
    $user = $this->member();
    $partner = $this->makePartner(['user_id' => (string) $user->id(), 'coupon_codes' => ['CLARA10']]);
    $payment = $this->makePayment();
    Referral::query()->create(['partner_id' => $partner->id, 'payment_id' => $payment->id, 'source' => 'link']);
    $this->pay($payment);

    $this->actingAs($user);

    expect(antlers('{{ affiliates:link url="/kurse" }}'))->toBe(url('/kurse').'?ref=clara')
        ->and(antlers('{{ affiliates:partner }}{{ sales }}|{{ code }}{{ /affiliates:partner }}'))->toBe('1|clara')
        ->and(antlers('{{ affiliates:commissions }}{{ kind }}:{{ status }}{{ /affiliates:commissions }}'))->toBe('sale:approved');

    $html = antlers('{{ affiliates:dashboard }}');
    expect($html)->toContain('?ref=clara')->toContain('CLARA10')->toContain('<form method="POST"');
});

it('shows nothing of a partner to somebody else', function () {
    $this->makePartner(['user_id' => 'someone-else']);

    $this->actingAs($this->member('other@example.com'));

    expect(antlers('{{ affiliates:link }}'))->toBe('')
        ->and(antlers('{{ affiliates:partner }}{{ if no_partner }}none{{ /if }}{{ /affiliates:partner }}'))->toBe('none');
});

it('lets a partner store their payout details', function () {
    $user = $this->member();
    $partner = $this->makePartner(['user_id' => (string) $user->id()]);

    $this->actingAs($user)->post('/!/affiliates/details', [
        'payout_method' => 'paypal',
        'payout_details' => 'clara@paypal.example',
        'notify' => '0',
    ])->assertSessionHas('affiliates.success');

    expect($partner->fresh()->payout_method)->toBe('paypal')
        ->and($partner->fresh()->payout_details)->toBe('clara@paypal.example')
        ->and($partner->fresh()->notify)->toBeFalse();
});

it('keeps commissions of other partners out of the loop', function () {
    $user = $this->member();
    $mine = $this->makePartner(['user_id' => (string) $user->id()]);
    $other = $this->makePartner(['name' => 'Ben', 'email' => 'ben@example.com', 'code' => 'ben']);

    foreach ([$mine, $other] as $p) {
        $payment = $this->makePayment();
        Referral::query()->create(['partner_id' => $p->id, 'payment_id' => $payment->id, 'source' => 'link']);
        $this->pay($payment);
    }

    $this->actingAs($user);

    expect(Commission::query()->count())->toBe(2)
        ->and(antlers('{{ affiliates:commissions }}x{{ /affiliates:commissions }}'))->toBe('x');
});

it('installs the material collection and fills in the partner\'s link', function () {
    $this->artisan('affiliates:install')->assertSuccessful();

    Entry::make()->collection('affiliate_materials')->slug('mail')
        ->data(['title' => 'Mailvorlage', 'kind' => 'mail', 'target_url' => '/kurse', 'copy' => 'Hier entlang: {link}'])
        ->save();
    Entry::make()->collection('affiliate_materials')->slug('entwurf')->published(false)
        ->data(['title' => 'Entwurf', 'copy' => 'nicht zeigen'])
        ->save();

    $user = $this->member();
    $this->makePartner(['user_id' => (string) $user->id()]);
    $this->actingAs($user);

    expect(antlers('{{ affiliates:materials }}{{ title }}: {{ copy }}{{ /affiliates:materials }}'))
        ->toBe('Mailvorlage: Hier entlang: '.url('/kurse').'?ref=clara');
});
