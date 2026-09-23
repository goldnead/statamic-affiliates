<?php

namespace Goldnead\Affiliates\Http\Controllers\Cp\Concerns;

use Inertia\Inertia;
use Inertia\Response;
use Statamic\CP\Column;

/**
 * The five listings of this addon are one Inertia page
 * (`affiliates::Listing`) fed differently: core's `<Listing>` in client mode,
 * the rows travelling with the page. A partner programme has hundreds of
 * rows, not hundreds of thousands; past the limit the page says so.
 */
trait RendersListing
{
    protected int $limit = 2000;

    /**
     * @param  list<Column>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extra
     */
    protected function listing(string $title, string $icon, string $prefix, array $columns, array $rows, int $total, array $extra = []): Response
    {
        return Inertia::render('affiliates::Listing', [
            'title' => $title,
            'icon' => $icon,
            'preferencesPrefix' => 'affiliates.'.$prefix,
            'rows' => $rows,
            'columns' => collect($columns)->map->toArray()->all(),
            'truncated' => $total > count($rows),
            'total' => $total,
            'locale' => str_replace('_', '-', app()->getLocale()),
            'docsUrl' => 'https://docs.adriangoldner.dev/affiliates/',
            ...$extra,
        ]);
    }
}
