<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    |
    | A partner's link is any page of the site with `?{parameter}={code}`, or
    | `/!/affiliates/go/{code}?to=/path`. The code is looked up here; nothing
    | else the visitor sends is believed.
    |
    | `attribution`: `first` keeps the partner who sent the visitor first as
    | long as the cookie lives, `last` gives the sale to the most recent link.
    | `cookie_days`: how long a click counts. 0 means only for the visit.
    |
    | Every key in this block is editable per brand under Settings.
    |
    */

    'tracking' => [
        'parameter' => 'ref',
        'attribution' => 'last',
        'cookie_days' => 30,
        'cookie_name' => 'statamic_affiliate',
        'count_clicks' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Consent
    |--------------------------------------------------------------------------
    |
    | The referral cookie is only written with the visitor's consent.
    |
    | `mode`:
    |   auto    ask statamic-consent for the service handle below; without that
    |           addon installed, no cookie is written at all
    |   always  write the cookie; the site asks for consent some other way
    |   never   never write a cookie
    |
    | Without consent the referral is kept in the session the visitor already
    | has, for the rest of the visit, when `session_fallback` is on. That puts
    | nothing new on the visitor's device. A later "yes" in the banner turns it
    | into a cookie on the next page view.
    |
    | Add a service with this handle to config/statamic-consent.php, otherwise
    | the banner never offers it and the answer is always no.
    |
    */

    'consent' => [
        'mode' => 'auto',
        'service' => 'affiliates',
        'session_fallback' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Commissions
    |--------------------------------------------------------------------------
    |
    | The rate for any product without its own row under Affiliates → Rates.
    | `type` is `percent` or `fixed` (`amount_cent` in the payment's currency).
    | `recurring`: `none`, `limited` (the first `recurring_times` renewals) or
    | `always`. `bumps` and `upsells`: whether order bumps and accepted
    | follow-up offers earn commission too.
    |
    | `base`: commission is taken from the amount the buyer paid, less VAT at
    | `vat_percent`. Leave 0 when prices carry no VAT (small business, § 19).
    |
    | `hold_days`: how long a commission waits before it can be paid out, so a
    | refund inside the withdrawal period can still reverse it.
    |
    | `coupon_wins`: a partner's coupon on the order beats a partner's link.
    | `self_referral`: whether partners earn on their own purchases.
    | `partner_rate`: a partner's own percentage replaces the `main` rate of a
    | sale only, or `all` rates including bumps and renewals.
    |
    */

    'commissions' => [
        'default' => [
            'type' => 'percent',
            'percent' => 30,
            'amount_cent' => null,
            'recurring' => 'none',
            'recurring_times' => null,
            'bumps' => false,
            'upsells' => false,
        ],
        'vat_percent' => 0,
        'hold_days' => 30,
        'coupon_wins' => true,
        'self_referral' => false,
        'partner_rate' => 'main',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    |
    | A payout list gathers every approved commission of a partner. Partners
    | below `minimum_cent` are carried over to the next list. The addon pays
    | nobody: the list is exported as CSV, paid by bank or PayPal, and then
    | marked as paid.
    |
    */

    'payouts' => [
        'minimum_cent' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Partner sign-up
    |--------------------------------------------------------------------------
    |
    | `enabled`: whether the `{{ affiliates:apply }}` form accepts sign-ups.
    | `approval`: `manual` (someone approves them in the Control Panel) or
    | `auto` (active on sign-up). Invitations sent from the Control Panel are
    | always active. Signing up needs a signed-in user; the partner area is
    | theirs.
    |
    */

    'signup' => [
        'enabled' => true,
        'approval' => 'manual',
        // How long an invitation link works. It only ever binds to the
        // invited address. 0 means it does not expire.
        'invite_days' => 14,
    ],

    /*
    |--------------------------------------------------------------------------
    | Joint ventures
    |--------------------------------------------------------------------------
    |
    | `stack_with_referral`: whether a JV partner who also sent the buyer
    | through their link earns the referral commission on top of their JV
    | share. Off: one sale, one share.
    |
    */

    'jv' => [
        'stack_with_referral' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | `commission`: tell a partner when a sale earned them something.
    | `approved`: tell a partner their application was approved.
    | Sent through the brand's sender identity (statamic-brand-context).
    |
    */

    'mail' => [
        'commission' => true,
        'approved' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Front-end routes
    |--------------------------------------------------------------------------
    |
    | Read when routes are registered, so a change needs a route cache clear.
    | `login_url`: where a visitor who is not signed in is sent from an
    | invitation link.
    |
    */

    'routes' => [
        'enabled' => (bool) env('AFFILIATES_ROUTES_ENABLED', true),
        'login_url' => '/login',
        'throttle' => '20,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Promotional material
    |--------------------------------------------------------------------------
    |
    | A Statamic collection partners see in their area. `affiliates:install`
    | creates it with a blueprint.
    |
    */

    'materials' => [
        'collection' => 'affiliate_materials',
        // The asset container for the image field. Empty: the site's first.
        'container' => env('AFFILIATES_MATERIALS_CONTAINER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Control Panel
    |--------------------------------------------------------------------------
    */

    'cp' => [
        'enabled' => true,
    ],

];
