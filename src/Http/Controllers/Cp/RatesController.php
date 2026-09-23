<?php

namespace Goldnead\Affiliates\Http\Controllers\Cp;

use Goldnead\Affiliates\Http\Controllers\Cp\Concerns\RendersListing;
use Goldnead\Affiliates\Models\Rate;
use Goldnead\Affiliates\Support\Blueprints;
use Goldnead\Affiliates\Support\Money;
use Goldnead\Affiliates\Support\Percent;
use Goldnead\Affiliates\Support\Setup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Statamic\CP\Column;
use Statamic\CP\PublishForm;
use Statamic\Http\Controllers\CP\CpController;

/** Commission per product. */
class RatesController extends CpController
{
    use RendersListing;

    public function index(): Response
    {
        Gate::authorize('manage affiliates');

        $title = __('affiliates::cp.rates');

        if ($setup = Setup::guard($title, 'shopping-store-discount-percent')) {
            return $setup;
        }

        $products = Blueprints::products();
        $default = (array) config('affiliates.commissions.default', []);

        $rows = Rate::query()->orderBy('product')->get()->map(fn (Rate $r) => [
            'id' => $r->id,
            'url' => cp_route('affiliates.rates.edit', $r->id),
            'product' => $products[$r->product] ?? $r->product,
            'commission' => self::describe($r->type, $r->percent, $r->amount_cent),
            'recurring' => __('affiliates::cp.recurring_'.$r->recurring).($r->recurring === Rate::RECURRING_LIMITED ? ' ('.$r->recurring_times.')' : ''),
            'extras' => implode(', ', array_filter([
                $r->bumps ? __('affiliates::cp.field_bumps') : null,
                $r->upsells ? __('affiliates::cp.field_upsells') : null,
            ])) ?: '—',
            'status' => $r->active ? 'active' : 'inactive',
            'status_label' => $r->active ? __('affiliates::cp.active') : __('affiliates::cp.inactive'),
            'row_actions' => [
                ['text' => __('affiliates::cp.edit'), 'url' => cp_route('affiliates.rates.edit', $r->id), 'icon' => 'pencil'],
                ['text' => __('affiliates::cp.delete'), 'url' => cp_route('affiliates.rates.destroy', $r->id), 'method' => 'delete', 'icon' => 'trash', 'destructive' => true, 'confirm' => __('affiliates::cp.delete_rate_confirm')],
            ],
        ])->all();

        return $this->listing($title, 'shopping-store-discount-percent', 'rates', [
            Column::make('product')->label(__('affiliates::cp.col_product')),
            Column::make('commission')->label(__('affiliates::cp.col_commission'))->sortable(false),
            Column::make('recurring')->label(__('affiliates::cp.col_recurring'))->sortable(false),
            Column::make('extras')->label(__('affiliates::cp.col_extras'))->sortable(false),
            Column::make('status')->label(__('affiliates::cp.col_status')),
        ], $rows, count($rows), [
            'badges' => ['status' => ['active' => 'green', 'inactive' => 'gray']],
            'description' => __('affiliates::cp.rates_default', [
                'rate' => self::describe((string) ($default['type'] ?? 'percent'), $default['percent'] ?? null, isset($default['amount_cent']) ? (int) $default['amount_cent'] : null),
            ]),
            'headerActions' => [['text' => __('affiliates::cp.create_rate'), 'url' => cp_route('affiliates.rates.create'), 'primary' => true]],
            'emptyHeading' => __('affiliates::cp.rates_empty'),
            'emptyDescription' => __('affiliates::cp.rates_empty_description'),
            'emptyUrl' => cp_route('affiliates.rates.create'),
        ]);
    }

    public function create(): PublishForm
    {
        Gate::authorize('manage affiliates');

        return PublishForm::make(Blueprints::rate())
            ->title(__('affiliates::cp.create_rate'))
            ->icon('shopping-store-discount-percent')
            ->submittingTo(cp_route('affiliates.rates.store'), 'POST');
    }

    /** @return array{saved: bool, redirect: string} */
    public function store(Request $request): array
    {
        Gate::authorize('manage affiliates');

        $values = PublishForm::make(Blueprints::rate())->submit($request->all());
        $attributes = $this->attributes($values);

        $this->ensureUnique($attributes['product']);

        Rate::query()->create($attributes);

        return ['saved' => true, 'redirect' => cp_route('affiliates.rates.index')];
    }

    public function edit(int $rate): PublishForm
    {
        Gate::authorize('manage affiliates');

        $model = Rate::query()->findOrFail($rate);

        return PublishForm::make(Blueprints::rate())
            ->title($model->product)
            ->icon('shopping-store-discount-percent')
            ->values(Blueprints::rateValues($model))
            ->submittingTo(cp_route('affiliates.rates.update', $model->id));
    }

    /** @return array{saved: bool, redirect: string} */
    public function update(Request $request, int $rate): array
    {
        Gate::authorize('manage affiliates');

        $model = Rate::query()->findOrFail($rate);
        $values = PublishForm::make(Blueprints::rate())->submit($request->all());
        $attributes = $this->attributes($values);

        $this->ensureUnique($attributes['product'], $model);

        $model->forceFill($attributes)->save();

        return ['saved' => true, 'redirect' => cp_route('affiliates.rates.index')];
    }

    public function destroy(int $rate): RedirectResponse
    {
        Gate::authorize('manage affiliates');

        Rate::query()->findOrFail($rate)->delete();

        return back()->with('success', __('affiliates::cp.rate_deleted'));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function attributes(array $values): array
    {
        $product = $values['product'] ?? '';
        $product = is_array($product) ? (string) ($product[0] ?? '') : (string) $product;
        $type = ($values['type'] ?? Rate::TYPE_PERCENT) === Rate::TYPE_FIXED ? Rate::TYPE_FIXED : Rate::TYPE_PERCENT;
        $recurring = in_array($values['recurring'] ?? null, [Rate::RECURRING_LIMITED, Rate::RECURRING_ALWAYS], true) ? $values['recurring'] : Rate::RECURRING_NONE;

        if ($recurring === Rate::RECURRING_LIMITED && empty($values['recurring_times'])) {
            throw ValidationException::withMessages(['recurring_times' => __('affiliates::cp.recurring_times_required')]);
        }

        return [
            'product' => trim($product),
            'type' => $type,
            'percent' => $type === Rate::TYPE_PERCENT ? self::number($values['percent'] ?? null) : null,
            'amount_cent' => $type === Rate::TYPE_FIXED ? (int) round(((float) ($values['amount'] ?? 0)) * 100) : null,
            'recurring' => $recurring,
            'recurring_times' => $recurring === Rate::RECURRING_LIMITED ? (int) $values['recurring_times'] : null,
            'recurring_percent' => $recurring !== Rate::RECURRING_NONE ? self::number($values['recurring_percent'] ?? null) : null,
            'bumps' => (bool) ($values['bumps'] ?? false),
            'bump_percent' => ! empty($values['bumps']) ? self::number($values['bump_percent'] ?? null) : null,
            'upsells' => (bool) ($values['upsells'] ?? false),
            'active' => (bool) ($values['active'] ?? true),
        ];
    }

    protected function ensureUnique(string $product, ?Rate $except = null): void
    {
        $taken = Rate::query()->where('product', $product)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['product' => __('affiliates::cp.rate_exists')]);
        }
    }

    public static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function describe(string $type, mixed $percent, ?int $amountCent): string
    {
        if ($type === Rate::TYPE_FIXED) {
            return Money::format((int) $amountCent, 'EUR').' '.__('affiliates::cp.per_sale');
        }

        return Percent::format($percent);
    }
}
