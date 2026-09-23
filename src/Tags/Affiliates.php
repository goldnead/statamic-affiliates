<?php

namespace Goldnead\Affiliates\Tags;

use Goldnead\Affiliates\Affiliates as Programme;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Support\Money;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Tags\Tags;

/**
 * The partner area, for the signed-in user.
 *
 *   {{ affiliates:dashboard }}                       the whole area, from the overridable view
 *   {{ affiliates:partner }} … {{ /affiliates:partner }}   name, code, status, link, stats
 *   {{ affiliates:link url="/kurs" }}                a tracking link to any page
 *   {{ affiliates:commissions limit="20" }} … {{ /affiliates:commissions }}
 *   {{ affiliates:payouts }} … {{ /affiliates:payouts }}
 *   {{ affiliates:materials }} … {{ /affiliates:materials }}   with {link} filled in
 *   {{ affiliates:apply_form }} … {{ /affiliates:apply_form }}
 *   {{ affiliates:details_form }} … {{ /affiliates:details_form }}
 */
class Affiliates extends Tags
{
    protected static $handle = 'affiliates';

    public function dashboard(): string
    {
        return view((string) $this->params->get('view', 'affiliates::dashboard'), [
            'partner' => $this->partnerData(),
            'signed_in' => User::current() !== null,
            'success' => session('affiliates.success'),
        ])->render();
    }

    /** @return array<string, mixed> */
    public function partner(): array
    {
        return $this->partnerData();
    }

    public function link(): string
    {
        $partner = $this->current();

        return $partner && $partner->isActive() ? $this->programme()->link($partner, $this->params->get('url')) : '';
    }

    /** @return list<array<string, mixed>> */
    public function commissions(): array
    {
        $partner = $this->current();

        if ($partner === null) {
            return [];
        }

        return $partner->commissions()
            ->orderByDesc('id')
            ->limit(max(1, min(500, (int) $this->params->get('limit', 50))))
            ->get()
            ->map(fn (Commission $c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'kind_label' => __('affiliates::cp.kind_'.$c->kind),
                'product' => $c->product,
                'amount' => Money::format($c->payableCent(), $c->currency),
                'amount_cent' => $c->payableCent(),
                'currency' => $c->currency,
                'status' => $c->status,
                'status_label' => __('affiliates::cp.status_'.$c->status),
                'date' => $c->created_at,
                'available_at' => $c->available_at,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function payouts(): array
    {
        $partner = $this->current();

        if ($partner === null) {
            return [];
        }

        return $partner->payouts()
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payout $p) => [
                'reference' => $p->reference,
                'amount' => Money::format($p->amount_cent, $p->currency),
                'status' => $p->status,
                'status_label' => __('affiliates::cp.payout_'.$p->status),
                'date' => $p->created_at,
                'paid_at' => $p->paid_at,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function materials(): array
    {
        $partner = $this->current();

        if ($partner === null || ! $partner->isActive()) {
            return [];
        }

        $collection = (string) config('affiliates.materials.collection', 'affiliate_materials');

        // Not installed yet: no material, not an exception on the partner's page.
        if (Collection::find($collection) === null) {
            return [];
        }

        return Entry::whereCollection($collection)
            ->filter(fn ($entry) => $entry->published())
            ->map(function ($entry) use ($partner) {
                $target = $entry->get('target_url');
                $link = $this->programme()->link($partner, is_string($target) ? $target : null);
                $copy = str_replace('{link}', $link, (string) $entry->get('copy'));

                return [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'kind' => $entry->get('kind'),
                    'copy' => $copy,
                    'image' => $entry->augmentedValue('image'),
                    'link' => $link,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|string */
    public function applyForm(): array|string
    {
        return $this->form(route('statamic.affiliates.apply'), [
            'signed_in' => User::current() !== null,
            'is_partner' => $this->current() !== null,
            'open' => (bool) config('affiliates.signup.enabled', true),
        ]);
    }

    /** @return array<string, mixed>|string */
    public function detailsForm(): array|string
    {
        $partner = $this->current();

        return $this->form(route('statamic.affiliates.details'), [
            'is_partner' => $partner !== null,
            'payout_method' => $partner?->payout_method,
            'has_details' => $partner?->payout_details !== null,
            'notify' => $partner !== null ? $partner->notify : true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|string
     */
    protected function form(string $action, array $data): array|string
    {
        $errors = session('errors')?->getBag('affiliates')->all() ?? [];

        $data = [
            ...$data,
            'errors' => $errors,
            'has_errors' => $errors !== [],
            'success' => session('affiliates.success'),
        ];

        if (! $this->isPair) {
            return $data;
        }

        return '<form method="POST" action="'.e($action).'">'.csrf_field().$this->parse($data).'</form>';
    }

    /** @return array<string, mixed> */
    protected function partnerData(): array
    {
        $partner = $this->current();

        if ($partner === null) {
            return ['no_partner' => true, 'signed_in' => User::current() !== null];
        }

        $stats = $this->programme()->stats($partner);

        return [
            'no_partner' => false,
            'name' => $partner->name,
            'email' => $partner->email,
            'code' => $partner->code,
            'status' => $partner->status,
            'status_label' => __('affiliates::cp.partner_'.$partner->status),
            'is_active' => $partner->isActive(),
            'is_pending' => $partner->status === Partner::STATUS_PENDING,
            'link' => $partner->isActive() ? $this->programme()->link($partner) : null,
            'coupon_codes' => $partner->couponCodes(),
            'clicks' => $stats['clicks'],
            'sales' => $stats['sales'],
            'conversion' => $stats['conversion'],
            'money' => array_map(fn (array $row) => [
                'currency' => $row['currency'],
                'pending' => Money::format($row['pending'], $row['currency']),
                'approved' => Money::format($row['approved'], $row['currency']),
                'paid' => Money::format($row['paid'], $row['currency']),
            ], $stats['money']),
        ];
    }

    protected function current(): ?Partner
    {
        return $this->programme()->partnerFor(User::current());
    }

    protected function programme(): Programme
    {
        return app(Programme::class);
    }
}
