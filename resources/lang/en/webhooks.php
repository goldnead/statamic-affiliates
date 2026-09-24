<?php

/*
| The triggers in the Webhook Manager, labelled "Affiliates: …" so they read
| as one group next to the other addons' triggers.
*/

return [
    'triggers' => [
        'commission_earned' => 'Affiliates: commission earned',
        'commission_reversed' => 'Affiliates: commission reversed',
        'partner_applied' => 'Affiliates: partner applied',
        'partner_approved' => 'Affiliates: partner approved',
    ],

    'descriptions' => [
        'commission_earned' => 'When a sale earns a partner a commission or a share of a revenue split.',
        'commission_reversed' => 'When a commission is taken back after a refund, a chargeback or by hand.',
        'partner_applied' => 'When somebody applies to the partner programme. Carries the message of the application.',
        'partner_approved' => 'When a partner is approved and can refer.',
    ],
];
