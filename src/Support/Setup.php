<?php

namespace Goldnead\Affiliates\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The studio's setup guard: a CP screen whose tables are missing renders an
 * empty state naming the fix, and the log says why, instead of answering 500
 * until somebody runs the migrations.
 */
final class Setup
{
    public const TABLES = [
        'affiliate_partners',
        'affiliate_referrals',
        'affiliate_rates',
        'affiliate_jv_contracts',
        'affiliate_commissions',
        'affiliate_payouts',
    ];

    public static function guard(string $title, string $icon): ?Response
    {
        $missing = array_values(array_filter(self::TABLES, fn (string $table) => ! Schema::hasTable($table)));

        if ($missing === []) {
            return null;
        }

        Log::warning('statamic-affiliates: tables are missing; run php artisan migrate.', ['missing' => $missing]);

        return Inertia::render('affiliates::Listing', [
            'title' => $title,
            'icon' => $icon,
            'preferencesPrefix' => 'affiliates.setup',
            'rows' => [],
            'columns' => [],
            'setupRequired' => true,
            'docsUrl' => 'https://docs.adriangoldner.dev/affiliates/',
        ]);
    }
}
