<?php

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\BrandContext\Models\Brand;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['brand-context.multi_brand' => true]);
    $this->other = Brand::create(['handle' => 'other', 'name' => 'Other'])->id;
    $this->default = app('brand-context')->defaultId();
});

it('lists only the current brand\'s partners', function () {
    app('brand-context')->runFor($this->other, fn () => $this->makePartner(['name' => 'Fremd', 'email' => 'fremd@example.com', 'code' => 'fremd']));
    app('brand-context')->setCurrent($this->default);
    $this->makePartner();

    $this->actingAs($this->superUser())
        ->get(cp_route('affiliates.partners.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('affiliates::Listing')
            ->has('rows', 1)
            ->where('rows.0.code', 'clara'));
});

it('does not give a payment of one brand to the coupon owner of another', function () {
    app('brand-context')->runFor($this->other, fn () => $this->makePartner(['coupon_codes' => ['CLARA10']]));

    $this->pay($this->makePayment(['brand_id' => $this->default, 'discount_code' => 'CLARA10']));

    expect(Commission::query()->acrossBrands()->count())->toBe(0);
});

it('books the commission in the partner\'s brand from a webhook without a brand', function () {
    $partner = app('brand-context')->runFor($this->other, fn () => $this->makePartner(['coupon_codes' => ['CLARA10']]));
    app('brand-context')->forget();

    $this->pay($this->makePayment(['brand_id' => $this->other, 'discount_code' => 'CLARA10']));

    $commission = Commission::query()->acrossBrands()->sole();
    expect($commission->brand_id)->toBe($this->other)
        ->and($commission->partner_id)->toBe($partner->id);
});

it('finds a partner code across brands, since codes are unique on the host', function () {
    app('brand-context')->runFor($this->other, fn () => $this->makePartner());

    expect(Partner::freshCode('Clara'))->not->toBe('clara');
    $this->get('/landing?ref=clara')->assertCookie('statamic_affiliate');
});
