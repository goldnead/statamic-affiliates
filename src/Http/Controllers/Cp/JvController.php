<?php

namespace Goldnead\Affiliates\Http\Controllers\Cp;

use Goldnead\Affiliates\Http\Controllers\Cp\Concerns\RendersListing;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\JvContract;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Support\Blueprints;
use Goldnead\Affiliates\Support\Money;
use Goldnead\Affiliates\Support\Setup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Statamic\CP\Column;
use Statamic\CP\PublishForm;
use Statamic\Http\Controllers\CP\CpController;

/** Joint-venture contracts: a fixed share per product, no link. */
class JvController extends CpController
{
    use RendersListing;

    public function index(): Response
    {
        Gate::authorize('manage affiliates');

        $title = __('affiliates::cp.jv');

        if ($setup = Setup::guard($title, 'text-formatting-input-signature')) {
            return $setup;
        }

        $partners = Partner::query()->pluck('name', 'id');
        $products = Blueprints::products();

        $earned = Commission::query()
            ->where('kind', Commission::KIND_JV)
            ->whereIn('status', [Commission::STATUS_PENDING, Commission::STATUS_APPROVED, Commission::STATUS_PAID])
            ->selectRaw('jv_contract_id, currency, sum(amount_cent - reversed_cent) as total')
            ->groupBy('jv_contract_id', 'currency')
            ->get()
            ->groupBy('jv_contract_id');

        $rows = JvContract::query()->orderBy('name')->get()->map(fn (JvContract $c) => [
            'id' => $c->id,
            'url' => cp_route('affiliates.jv.edit', $c->id),
            'name' => $c->name,
            'partner' => (string) ($partners[$c->partner_id] ?? '—'),
            'products' => collect($c->products ?? [])->map(fn ($p) => $products[$p] ?? $p)->implode(', ') ?: __('affiliates::cp.all_products'),
            'share' => RatesController::describe('percent', $c->percent, null),
            'period' => trim(($c->starts_on?->format('d.m.Y') ?? '…').' – '.($c->ends_on?->format('d.m.Y') ?? '…')),
            'earned' => ($earned[$c->id] ?? collect())->map(fn ($r) => Money::format((int) $r->total, (string) $r->currency))->implode(' · ') ?: '—',
            'status' => $c->active ? 'active' : 'inactive',
            'status_label' => $c->active ? __('affiliates::cp.active') : __('affiliates::cp.inactive'),
            'row_actions' => [
                ['text' => __('affiliates::cp.edit'), 'url' => cp_route('affiliates.jv.edit', $c->id), 'icon' => 'pencil'],
                ['text' => __('affiliates::cp.delete'), 'url' => cp_route('affiliates.jv.destroy', $c->id), 'method' => 'delete', 'icon' => 'trash', 'destructive' => true, 'confirm' => __('affiliates::cp.delete_jv_confirm')],
            ],
        ])->all();

        return $this->listing($title, 'text-formatting-input-signature', 'jv', [
            Column::make('name')->label(__('affiliates::cp.col_name')),
            Column::make('partner')->label(__('affiliates::cp.col_partner')),
            Column::make('products')->label(__('affiliates::cp.col_products'))->sortable(false),
            Column::make('share')->label(__('affiliates::cp.col_share'))->sortable(false),
            Column::make('period')->label(__('affiliates::cp.col_period'))->sortable(false),
            Column::make('earned')->label(__('affiliates::cp.col_earned'))->sortable(false),
            Column::make('status')->label(__('affiliates::cp.col_status')),
        ], $rows, count($rows), [
            'badges' => ['status' => ['active' => 'green', 'inactive' => 'gray']],
            'description' => __('affiliates::cp.jv_description'),
            'headerActions' => [['text' => __('affiliates::cp.create_jv'), 'url' => cp_route('affiliates.jv.create'), 'primary' => true]],
            'emptyHeading' => __('affiliates::cp.jv_empty'),
            'emptyDescription' => __('affiliates::cp.jv_empty_description'),
            'emptyUrl' => cp_route('affiliates.jv.create'),
        ]);
    }

    public function create(): PublishForm
    {
        Gate::authorize('manage affiliates');

        return PublishForm::make(Blueprints::jv())
            ->title(__('affiliates::cp.create_jv'))
            ->icon('text-formatting-input-signature')
            ->submittingTo(cp_route('affiliates.jv.store'), 'POST');
    }

    /** @return array{saved: bool, redirect: string} */
    public function store(Request $request): array
    {
        Gate::authorize('manage affiliates');

        $values = PublishForm::make(Blueprints::jv())->submit($request->all());

        JvContract::query()->create($this->attributes($values));

        return ['saved' => true, 'redirect' => cp_route('affiliates.jv.index')];
    }

    public function edit(int $contract): PublishForm
    {
        Gate::authorize('manage affiliates');

        $model = JvContract::query()->findOrFail($contract);

        return PublishForm::make(Blueprints::jv())
            ->title($model->name)
            ->icon('text-formatting-input-signature')
            ->values(Blueprints::jvValues($model))
            ->submittingTo(cp_route('affiliates.jv.update', $model->id));
    }

    /** @return array{saved: bool, redirect: string} */
    public function update(Request $request, int $contract): array
    {
        Gate::authorize('manage affiliates');

        $model = JvContract::query()->findOrFail($contract);
        $values = PublishForm::make(Blueprints::jv())->submit($request->all());

        $model->forceFill($this->attributes($values))->save();

        return ['saved' => true, 'redirect' => cp_route('affiliates.jv.index')];
    }

    public function destroy(int $contract): RedirectResponse
    {
        Gate::authorize('manage affiliates');

        JvContract::query()->findOrFail($contract)->delete();

        return back()->with('success', __('affiliates::cp.jv_deleted'));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function attributes(array $values): array
    {
        $partnerId = $values['partner_id'] ?? null;
        $partnerId = is_array($partnerId) ? ($partnerId[0] ?? null) : $partnerId;

        // Through the brand scope: a partner of another brand is not found.
        if (! is_numeric($partnerId) || Partner::query()->find((int) $partnerId) === null) {
            throw ValidationException::withMessages(['partner_id' => __('affiliates::cp.partner_missing')]);
        }

        $startsOn = $this->date($values['starts_on'] ?? null);
        $endsOn = $this->date($values['ends_on'] ?? null);

        if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => __('affiliates::cp.ends_before_start')]);
        }

        return [
            'name' => trim((string) $values['name']),
            'partner_id' => (int) $partnerId,
            'products' => collect((array) ($values['products'] ?? []))->filter(fn ($p) => is_string($p) && $p !== '')->values()->all(),
            'active' => (bool) ($values['active'] ?? true),
            'recurring' => (bool) ($values['recurring'] ?? true),
            'percent' => (float) $values['percent'],
            'bump_percent' => RatesController::number($values['bump_percent'] ?? null),
            'upsell_percent' => RatesController::number($values['upsell_percent'] ?? null),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ];
    }

    protected function date(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['date'] ?? $value['start'] ?? null;
        }

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
    }
}
