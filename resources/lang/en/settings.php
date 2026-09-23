<?php

return [

    'permission' => 'Manage partner programme settings',

    'groups' => [
        'commissions' => [
            'title' => 'Commission',
            'description' => 'The default rate for every product without its own, and how it is computed.',
        ],
        'tracking' => [
            'title' => 'Link and cookie',
            'description' => 'How long a click counts, and who gets the sale when two partners sent the same visitor.',
        ],
        'payouts' => [
            'title' => 'Payouts',
            'description' => 'Hold period and minimum amount.',
        ],
        'partners' => [
            'title' => 'Partners',
            'description' => 'Sign-up and emails to partners.',
        ],
    ],

    'fields' => [
        'commissions_default_percent' => ['label' => 'Default rate in percent', 'description' => 'Of the net amount of every payment a partner refers.'],
        'commissions_default_recurring' => ['label' => 'Subscription renewals', 'description' => 'Whether a partner also earns on the renewals of a subscription they referred.'],
        'commissions_default_recurring_times' => ['label' => 'How many renewals', 'description' => 'Only with "The first n".'],
        'commissions_default_bumps' => ['label' => 'Also on bumps', 'description' => 'Commission on products added at checkout.'],
        'commissions_default_upsells' => ['label' => 'Also on upsells', 'description' => 'Commission on follow-up offers after the purchase.'],
        'commissions_vat_percent' => ['label' => 'VAT in percent', 'description' => 'Taken off before computing. 0 for prices without VAT.'],
        'commissions_coupon_wins' => ['label' => 'Coupon beats link', 'description' => 'An order carrying a partner\'s coupon goes to that partner, even when the buyer came through somebody else\'s link.'],
        'commissions_partner_rate' => ['label' => 'A partner\'s own rate applies to', 'description' => 'Only the main rate of a sale, or bumps and renewals too.'],
        'jv_stack_with_referral' => ['label' => 'JV share and referral together', 'description' => 'Whether a JV partner who also sent the buyer through their link earns the referral commission on top.'],
        'signup_invite_days' => ['label' => 'Invitation valid for days', 'description' => 'How long an invitation link works, and only for the invited address. 0 means no limit.'],
        'commissions_self_referral' => ['label' => 'Own purchases count', 'description' => 'Whether partners earn on purchases made with their own address.'],
        'tracking_attribution' => ['label' => 'With two partners', 'description' => 'Who gets the sale when a visitor came through two links.'],
        'tracking_cookie_days' => ['label' => 'Cookie life in days', 'description' => 'How long a click counts. 0 means only for the visit.'],
        'consent_mode' => ['label' => 'Consent', 'description' => 'When the cookie is written. "Automatic" asks statamic-consent.'],
        'consent_session_fallback' => ['label' => 'Remember for the visit without consent', 'description' => 'Keeps the click in the existing session. Puts nothing new on the device.'],
        'commissions_hold_days' => ['label' => 'Hold period in days', 'description' => 'How long a commission waits before it can be paid out. A refund in that time reverses it.'],
        'payouts_minimum_cent' => ['label' => 'Minimum in cents', 'description' => 'Partners below it wait for the next payout list. 5000 is 50.00.'],
        'signup_enabled' => ['label' => 'Sign-up open', 'description' => 'Whether the form in the partner area accepts applications.'],
        'signup_approval' => ['label' => 'Approval', 'description' => 'Whether new partners are active at once or wait for approval.'],
        'mail_commission' => ['label' => 'Email on commission', 'description' => 'Partners get an email when a sale earned them something.'],
        'mail_approved' => ['label' => 'Email on approval', 'description' => 'Partners get an email when their application is approved.'],
    ],

    'options' => [
        'recurring' => ['none' => 'None', 'limited' => 'The first n', 'always' => 'All'],
        'attribution' => ['first' => 'The first click counts', 'last' => 'The last click counts'],
        'consent_mode' => ['auto' => 'Automatic (statamic-consent)', 'always' => 'Always, the site asks itself', 'never' => 'Never, only for the visit'],
        'approval' => ['manual' => 'By hand', 'auto' => 'Active at once'],
        'partner_rate' => ['main' => 'Only the main rate', 'all' => 'All rates'],
    ],

];
