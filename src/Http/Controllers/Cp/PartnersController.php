<?php

namespace Goldnead\Affiliates\Http\Controllers\Cp;

use Goldnead\Affiliates\Affiliates;
use Goldnead\Affiliates\Http\Controllers\Cp\Concerns\RendersListing;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\JvContract;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Support\Blueprints;
use Goldnead\Affiliates\Support\Money;
use Goldnead\Affiliates\Support\Percent;
use Goldnead\Affiliates\Support\Setup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\CP\Column;
use Statamic\CP\PublishForm;
use Statamic\Http\Controllers\CP\CpController;

class PartnersController extends CpController
{
    use RendersListing;

    public function __construct(Request $request, protected Affiliates $affiliates)
    {
        parent::__construct($request);
    }

    public function index(): Response
    {
        Gate::authorize('view affiliates');

        $title = __('affiliates::cp.partners');

        if ($setup = Setup::guard($title, 'users')) {
            return $setup;
        }

        $query = Partner::query()->orderByRaw("case status when 'pending' then 0 else 1 end")->orderBy('name');
        $total = (clone $query)->count();

        $earned = Commission::query()
            ->whereIn('status', [Commission::STATUS_PENDING, Commission::STATUS_APPROVED, Commission::STATUS_PAID])
            ->selectRaw('partner_id, currency, sum(amount_cent - reversed_cent) as total')
            ->groupBy('partner_id', 'currency')
            ->get()
            ->groupBy('partner_id');

        $rows = $query->limit($this->limit)->get()->map(fn (Partner $p) => [
            'id' => $p->id,
            'url' => cp_route('affiliates.partners.show', $p->id),
            'name' => $p->name,
            'email' => $p->email,
            'code' => $p->code,
            'status' => $p->status,
            'status_label' => __('affiliates::cp.partner_'.$p->status),
            'earned' => ($earned[$p->id] ?? collect())->map(fn ($r) => Money::format((int) $r->total, (string) $r->currency))->implode(' · ') ?: '—',
            'created_at' => $p->created_at?->toIso8601String(),
            // Not `actions`: core's <Listing> reads that key as server-side
            // Statamic actions and fails to render the row.
            'row_actions' => array_values(array_filter([
                ['text' => __('affiliates::cp.open'), 'url' => cp_route('affiliates.partners.show', $p->id), 'icon' => 'eye'],
                Gate::allows('manage affiliates') ? ['text' => __('affiliates::cp.edit'), 'url' => cp_route('affiliates.partners.edit', $p->id), 'icon' => 'pencil'] : null,
                Gate::allows('manage affiliates') && $p->status === Partner::STATUS_PENDING
                    ? ['text' => __('affiliates::cp.approve'), 'url' => cp_route('affiliates.partners.status', $p->id), 'method' => 'post', 'data' => ['status' => Partner::STATUS_ACTIVE], 'icon' => 'checkmark']
                    : null,
            ])),
        ])->all();

        return $this->listing($title, 'users', 'partners', [
            Column::make('name')->label(__('affiliates::cp.col_name')),
            Column::make('email')->label(__('affiliates::cp.col_email')),
            Column::make('code')->label(__('affiliates::cp.col_code')),
            Column::make('status')->label(__('affiliates::cp.col_status')),
            Column::make('earned')->label(__('affiliates::cp.col_earned'))->sortable(false),
            Column::make('created_at')->label(__('affiliates::cp.col_created')),
        ], $rows, $total, [
            'badges' => ['status' => ['pending' => 'amber', 'active' => 'green', 'rejected' => 'red', 'suspended' => 'gray']],
            'dates' => ['created_at'],
            'mono' => ['code'],
            'headerActions' => Gate::allows('manage affiliates')
                ? [['text' => __('affiliates::cp.create_partner'), 'url' => cp_route('affiliates.partners.create'), 'primary' => true]]
                : [],
            'emptyHeading' => __('affiliates::cp.partners_empty'),
            'emptyDescription' => __('affiliates::cp.partners_empty_description'),
            'emptyUrl' => Gate::allows('manage affiliates') ? cp_route('affiliates.partners.create') : null,
        ]);
    }

    public function create(): PublishForm
    {
        Gate::authorize('manage affiliates');

        return PublishForm::make(Blueprints::partner(true))
            ->title(__('affiliates::cp.create_partner'))
            ->icon('users')
            ->submittingTo(cp_route('affiliates.partners.store'), 'POST');
    }

    /** @return array{saved: bool, redirect: string} */
    public function store(Request $request): array
    {
        Gate::authorize('manage affiliates');

        $values = PublishForm::make(Blueprints::partner(true))->submit($request->all());
        $email = mb_strtolower(trim((string) $values['email']));

        $this->ensureUnique($email, $values['code'] ?? null);

        $partner = Partner::query()->create([
            ...$this->attributes($values),
            'email' => $email,
            'code' => $this->code($values['code'] ?? null, (string) $values['name']),
            'approved_at' => ($values['status'] ?? null) === Partner::STATUS_ACTIVE ? now() : null,
        ]);

        if (! empty($values['send_invitation'])) {
            $this->affiliates->invite($partner);
        }

        return ['saved' => true, 'redirect' => cp_route('affiliates.partners.show', $partner->id)];
    }

    public function show(int $partner): Response
    {
        Gate::authorize('view affiliates');

        $model = Partner::query()->findOrFail($partner);
        $stats = $this->affiliates->stats($model);
        $canManage = Gate::allows('manage affiliates');

        $commissions = $model->commissions()->orderByDesc('id')->limit(200)->get()
            ->map(fn (Commission $c) => CommissionsController::row($c, false))->all();

        $payouts = $model->payouts()->orderByDesc('id')->limit(50)->get()->map(fn (Payout $p) => [
            'id' => $p->id,
            'reference' => $p->reference,
            'amount' => Money::format($p->amount_cent, $p->currency),
            'status' => $p->status,
            'status_label' => __('affiliates::cp.payout_'.$p->status),
            'created_at' => $p->created_at?->toIso8601String(),
        ])->all();

        $contracts = JvContract::query()->where('partner_id', $model->id)->get()->map(fn (JvContract $c) => [
            'name' => $c->name,
            'percent' => Percent::format($c->percent),
            'active' => $c->active,
            'url' => cp_route('affiliates.jv.edit', $c->id),
        ])->all();

        return Inertia::render('affiliates::Partners/Show', [
            'partner' => [
                'id' => $model->id,
                'name' => $model->name,
                'email' => $model->email,
                'code' => $model->code,
                'status' => $model->status,
                'status_label' => __('affiliates::cp.partner_'.$model->status),
                'link' => $model->isActive() ? $this->affiliates->link($model) : null,
                'coupon_codes' => $model->couponCodes(),
                'commission_percent' => $model->commission_percent !== null ? Percent::format($model->commission_percent) : null,
                'payout_method' => $model->payout_method ? __('affiliates::cp.method_'.$model->payout_method) : null,
                'has_payout_details' => $model->payout_details !== null && $model->payout_details !== '',
                'website' => $model->website,
                'message' => $model->message,
                'notes' => $model->notes,
                'user_linked' => $model->user_id !== null,
                'invited_at' => $model->invited_at?->toIso8601String(),
                'created_at' => $model->created_at?->toIso8601String(),
            ],
            'stats' => [
                'clicks' => $stats['clicks'],
                'sales' => $stats['sales'],
                'conversion' => $stats['conversion'] !== null ? Percent::format($stats['conversion']) : null,
                'money' => array_map(fn ($m) => [
                    'currency' => $m['currency'],
                    'pending' => Money::format($m['pending'], $m['currency']),
                    'approved' => Money::format($m['approved'], $m['currency']),
                    'paid' => Money::format($m['paid'], $m['currency']),
                ], $stats['money']),
            ],
            'commissions' => $commissions,
            'commissionColumns' => collect(CommissionsController::columns(false))->map->toArray()->all(),
            'payouts' => $payouts,
            'contracts' => $contracts,
            'locale' => str_replace('_', '-', app()->getLocale()),
            'indexUrl' => cp_route('affiliates.partners.index'),
            'editUrl' => $canManage ? cp_route('affiliates.partners.edit', $model->id) : null,
            'statusUrl' => $canManage ? cp_route('affiliates.partners.status', $model->id) : null,
            'inviteUrl' => $canManage ? cp_route('affiliates.partners.invite', $model->id) : null,
        ]);
    }

    public function edit(int $partner): PublishForm
    {
        Gate::authorize('manage affiliates');

        $model = Partner::query()->findOrFail($partner);

        return PublishForm::make(Blueprints::partner(false))
            ->title($model->name)
            ->icon('users')
            ->values(Blueprints::partnerValues($model))
            ->submittingTo(cp_route('affiliates.partners.update', $model->id));
    }

    /** @return array{saved: bool, redirect: string} */
    public function update(Request $request, int $partner): array
    {
        Gate::authorize('manage affiliates');

        $model = Partner::query()->findOrFail($partner);
        $values = PublishForm::make(Blueprints::partner(false))->submit($request->all());
        $email = mb_strtolower(trim((string) $values['email']));

        $this->ensureUnique($email, $values['code'] ?? null, $model);

        $wasActive = $model->isActive();

        $attributes = $this->attributes($values);
        $status = $attributes['status'];
        unset($attributes['status']);

        $model->forceFill([
            ...$attributes,
            'email' => $email,
            'code' => $this->code($values['code'] ?? null, (string) $values['name'], $model),
        ])->save();

        if ($status !== $model->status) {
            $this->affiliates->setStatus($model, $status);
        } elseif (! $wasActive && $model->isActive()) {
            $this->affiliates->approve($model);
        }

        return ['saved' => true, 'redirect' => cp_route('affiliates.partners.show', $model->id)];
    }

    public function status(Request $request, int $partner): RedirectResponse
    {
        Gate::authorize('manage affiliates');

        $model = Partner::query()->findOrFail($partner);
        $data = $request->validate(['status' => ['required', 'in:'.implode(',', Partner::statuses())]]);

        $this->affiliates->setStatus($model, $data['status']);

        return back()->with('success', __('affiliates::cp.status_changed', ['status' => __('affiliates::cp.partner_'.$data['status'])]));
    }

    public function invite(int $partner): RedirectResponse
    {
        Gate::authorize('manage affiliates');

        $model = Partner::query()->findOrFail($partner);

        $this->affiliates->invite($model);

        return back()->with('success', __('affiliates::cp.invitation_sent', ['email' => $model->email]));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function attributes(array $values): array
    {
        $details = $values['payout_details'] ?? null;

        return [
            'name' => trim((string) $values['name']),
            'status' => in_array($values['status'] ?? null, Partner::statuses(), true) ? $values['status'] : Partner::STATUS_ACTIVE,
            'commission_percent' => isset($values['commission_percent']) && $values['commission_percent'] !== '' ? (float) $values['commission_percent'] : null,
            'coupon_codes' => collect((array) ($values['coupon_codes'] ?? []))
                ->filter(fn ($c) => is_string($c) && trim($c) !== '')
                ->map(fn (string $c) => mb_strtoupper(trim($c)))
                ->unique()->values()->all(),
            'notify' => (bool) ($values['notify'] ?? true),
            'payout_method' => in_array($values['payout_method'] ?? null, ['bank', 'paypal', 'other'], true) ? $values['payout_method'] : null,
            'payout_details' => is_string($details) && trim($details) !== '' ? trim($details) : null,
            'notes' => $values['notes'] ?? null,
        ];
    }

    protected function code(mixed $wanted, string $name, ?Partner $current = null): string
    {
        $wanted = is_string($wanted) ? Str::lower(trim($wanted)) : '';

        if ($wanted !== '') {
            return $wanted;
        }

        return $current !== null ? $current->code : Partner::freshCode($name);
    }

    protected function ensureUnique(string $email, mixed $code, ?Partner $except = null): void
    {
        $errors = [];

        $emailTaken = Partner::query()->where('email', $email)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->exists();

        if ($emailTaken) {
            $errors['email'] = __('affiliates::cp.email_taken');
        }

        $code = is_string($code) ? Str::lower(trim($code)) : '';

        if ($code !== '') {
            // Across brands: a link code names exactly one partner on the host.
            $codeTaken = Partner::query()->acrossBrands()->where('code', $code)
                ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
                ->exists();

            if ($codeTaken) {
                $errors['code'] = __('affiliates::cp.code_taken');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
