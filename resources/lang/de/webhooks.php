<?php

/*
| Die Auslöser im Webhook-Manager. Dieselben Wörter wie in den Automationen,
| „Partner: …" als Gruppe neben den Auslösern der anderen Addons.
*/

return [
    'triggers' => [
        'commission_earned' => 'Partner: Provision verdient',
        'commission_reversed' => 'Partner: Provision zurückgenommen',
        'partner_applied' => 'Partner: Bewerbung eingegangen',
        'partner_approved' => 'Partner: freigegeben',
    ],

    'descriptions' => [
        'commission_earned' => 'Wenn ein Verkauf einem Partner eine Provision oder einen Anteil aus einer Umsatzteilung bringt.',
        'commission_reversed' => 'Wenn eine Provision nach einer Erstattung, einer Rückbuchung oder von Hand zurückgenommen wird.',
        'partner_applied' => 'Wenn sich jemand für das Partnerprogramm bewirbt. Enthält die Nachricht der Bewerbung.',
        'partner_approved' => 'Wenn ein Partner freigegeben ist und empfehlen kann.',
    ],
];
