<?php

return [

    'permission' => 'Einstellungen des Partnerprogramms verwalten',

    'groups' => [
        'commissions' => [
            'title' => 'Provision',
            'description' => 'Der Standardsatz für jedes Produkt ohne eigenen Satz, und wie gerechnet wird.',
        ],
        'tracking' => [
            'title' => 'Link und Cookie',
            'description' => 'Wie lange ein Klick zählt und wer bei zwei Partnern hintereinander den Verkauf bekommt.',
        ],
        'payouts' => [
            'title' => 'Auszahlung',
            'description' => 'Haltefrist und Mindestbetrag.',
        ],
        'partners' => [
            'title' => 'Partner',
            'description' => 'Anmeldung und Mails an Partner.',
        ],
    ],

    'fields' => [
        'commissions_default_percent' => ['label' => 'Standardsatz in Prozent', 'description' => 'Vom Nettobetrag jeder Zahlung, die ein Partner vermittelt.'],
        'commissions_default_recurring' => ['label' => 'Folgezahlungen im Abo', 'description' => 'Ob ein Partner auch an den Folgezahlungen eines vermittelten Abos verdient.'],
        'commissions_default_recurring_times' => ['label' => 'Wie viele Folgezahlungen', 'description' => 'Nur bei „Die ersten n".'],
        'commissions_default_bumps' => ['label' => 'Auch auf Bumps', 'description' => 'Provision auf Produkte, die im Checkout dazugenommen werden.'],
        'commissions_default_upsells' => ['label' => 'Auch auf Upsells', 'description' => 'Provision auf Nachfassangebote nach dem Kauf.'],
        'commissions_vat_percent' => ['label' => 'Umsatzsteuer in Prozent', 'description' => 'Wird vor der Berechnung abgezogen. 0 bei Kleinunternehmerregelung oder Preisen ohne Steuer.'],
        'commissions_coupon_wins' => ['label' => 'Gutschein schlägt Link', 'description' => 'Trägt eine Bestellung den Gutschein eines Partners, bekommt er den Verkauf, auch wenn der Käufer über den Link eines anderen kam.'],
        'commissions_partner_rate' => ['label' => 'Eigener Satz eines Partners gilt für', 'description' => 'Nur den Hauptsatz eines Verkaufs, oder auch Bumps und Folgezahlungen.'],
        'jv_stack_with_referral' => ['label' => 'JV-Anteil und Empfehlung zusammen', 'description' => 'Ob ein JV-Partner, der den Käufer auch über seinen Link geschickt hat, zusätzlich die Empfehlungsprovision bekommt.'],
        'signup_invite_days' => ['label' => 'Einladung gilt Tage', 'description' => 'So lange funktioniert ein Einladungslink, und nur für die eingeladene Adresse. 0 heißt unbegrenzt.'],
        'commissions_self_referral' => ['label' => 'Eigenkäufe zählen', 'description' => 'Ob ein Partner an Käufen mit seiner eigenen Adresse verdient.'],
        'tracking_attribution' => ['label' => 'Bei zwei Partnern', 'description' => 'Wer den Verkauf bekommt, wenn ein Besucher über zwei Links kam.'],
        'tracking_cookie_days' => ['label' => 'Laufzeit in Tagen', 'description' => 'Wie lange ein Klick zählt. 0 heißt nur für den Besuch.'],
        'consent_mode' => ['label' => 'Einwilligung', 'description' => 'Wann das Cookie geschrieben wird. „Automatisch" fragt statamic-consent.'],
        'consent_session_fallback' => ['label' => 'Ohne Einwilligung für den Besuch merken', 'description' => 'Hält den Klick in der bestehenden Sitzung fest. Legt nichts Neues auf dem Gerät ab.'],
        'commissions_hold_days' => ['label' => 'Haltefrist in Tagen', 'description' => 'So lange wartet eine Provision, bevor sie ausgezahlt werden kann. Eine Erstattung in dieser Zeit storniert sie.'],
        'payouts_minimum_cent' => ['label' => 'Mindestbetrag in Cent', 'description' => 'Partner darunter warten auf die nächste Auszahlungsliste. 5000 sind 50,00.'],
        'signup_enabled' => ['label' => 'Bewerbung offen', 'description' => 'Ob das Formular im Partnerbereich Bewerbungen annimmt.'],
        'signup_approval' => ['label' => 'Freigabe', 'description' => 'Ob neue Partner sofort aktiv sind oder erst freigegeben werden.'],
        'mail_commission' => ['label' => 'Mail bei Provision', 'description' => 'Partner bekommen eine Mail, wenn ein Verkauf ihnen etwas eingebracht hat.'],
        'mail_approved' => ['label' => 'Mail bei Freigabe', 'description' => 'Partner bekommen eine Mail, wenn ihre Bewerbung freigegeben wurde.'],
    ],

    'options' => [
        'recurring' => ['none' => 'Keine', 'limited' => 'Die ersten n', 'always' => 'Alle'],
        'attribution' => ['first' => 'Der erste Klick zählt', 'last' => 'Der letzte Klick zählt'],
        'consent_mode' => ['auto' => 'Automatisch (statamic-consent)', 'always' => 'Immer, die Website fragt selbst', 'never' => 'Nie, nur für den Besuch'],
        'approval' => ['manual' => 'Von Hand', 'auto' => 'Sofort aktiv'],
        'partner_rate' => ['main' => 'Nur den Hauptsatz', 'all' => 'Alle Sätze'],
    ],

];
