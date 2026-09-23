<?php

namespace Goldnead\Affiliates\Http\Controllers\Cp;

use Goldnead\Affiliates\Http\Controllers\Cp\Concerns\RendersListing;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Money;
use Goldnead\Affiliates\Support\Percent;
use Goldnead\Affiliates\Support\Setup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Statamic\CP\Column;
use Statamic\Http\Controllers\CP\CpController;

class CommissionsController extends CpController
{
    use RendersListing;

    public function index(Ledger $ledger): Response
    {
        Gate::authorize('view affiliates');

        $title = __('affiliates::cp.commissions');

        if ($setup = Setup::guard($title, 'money-cash-bill')) {
            return $setup;
        }

        // Whatever is due becomes payable before anyone looks at the list.
        $ledger->release();

        $query = Commission::query()->orderByDesc('id');
        $total = (clone $query)->count();
        $partners = Partner::query()->pluck('name', 'id');

        $rows = $query->limit($this->limit)->get()
            ->map(fn (Commission $c) => self::row($c, true, (string) ($partners[$c->partner_id] ?? '—')))
            ->all();

        return $this->listing($title, 'money-cash-bill', 'commissions', self::columns(true), $rows, $total, [
            'badges' => ['status' => self::statusColours()],
            'dates' => ['sold_at', 'created_at', 'available_at'],
            'emptyHeading' => __('affiliates::cp.commissions_empty'),
            'emptyDescription' => __('affiliates::cp.commissions_empty_description'),
        ]);
    }

    public function cancel(Ledger $ledger, int $commission): RedirectResponse
    {
        Gate::authorize('manage affiliate payouts');

        $model = Commission::query()->findOrFail($commission);
        $ledger->cancel($model, 'manual');

        return back()->with('success', __('affiliates::cp.commission_cancelled'));
    }

    /** @return array<string, mixed> */
    public static function row(Commission $c, bool $withPartner, string $partner = ''): array
    {
        $cancellable = in_array($c->status, [Commission::STATUS_PENDING, Commission::STATUS_APPROVED], true)
            && $c->kind !== Commission::KIND_CLAWBACK
            && $c->payout_id === null
            && Gate::allows('manage affiliate payouts');

        return [
            'id' => $c->id,
            'url' => $withPartner ? cp_route('affiliates.partners.show', $c->partner_id) : null,
            'partner' => $partner,
            'kind' => $c->kind,
            'kind_label' => __('affiliates::cp.kind_'.$c->kind).($c->cycle ? ' #'.$c->cycle : ''),
            'product' => $c->product,
            'payment' => '#'.$c->payment_id,
            // A claw-back has no base of its own; it is offset against the
            // next payout, and says so instead of showing "0,00 €".
            'base' => $c->kind === Commission::KIND_CLAWBACK ? '—' : Money::format($c->base_cent, $c->currency),
            'amount' => Money::format($c->payableCent(), $c->currency),
            'rate' => match (true) {
                $c->kind === Commission::KIND_CLAWBACK => __('affiliates::cp.clawback_offset'),
                $c->rate === 'fixed' => __('affiliates::cp.type_fixed'),
                is_numeric($c->rate) => Percent::format($c->rate),
                default => (string) $c->rate,
            },
            'status' => $c->status,
            'status_label' => __('affiliates::cp.status_'.$c->status),
            // The sale's date. The booking date is kept apart: a webhook that
            // arrives days late must not move the sale.
            'sold_at' => ($c->sold_at ?? $c->created_at)?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
            'available_at' => $c->available_at?->toIso8601String(),
            'row_actions' => $cancellable ? [[
                'text' => __('affiliates::cp.cancel_commission'),
                'url' => cp_route('affiliates.commissions.cancel', $c->id),
                'method' => 'post',
                'icon' => 'x',
                'destructive' => true,
                'confirm' => __('affiliates::cp.cancel_commission_confirm'),
            ]] : [],
        ];
    }

    /** @return list<Column> */
    public static function columns(bool $withPartner): array
    {
        return array_values(array_filter([
            Column::make('sold_at')->label(__('affiliates::cp.col_sold_at')),
            $withPartner ? Column::make('partner')->label(__('affiliates::cp.col_partner')) : null,
            Column::make('kind_label')->label(__('affiliates::cp.col_kind')),
            Column::make('product')->label(__('affiliates::cp.col_product')),
            Column::make('payment')->label(__('affiliates::cp.col_payment'))->visible(false),
            Column::make('base')->label(__('affiliates::cp.col_base'))->sortable(false),
            Column::make('rate')->label(__('affiliates::cp.col_rate'))->sortable(false),
            Column::make('amount')->label(__('affiliates::cp.col_amount'))->sortable(false),
            Column::make('status')->label(__('affiliates::cp.col_status')),
            Column::make('available_at')->label(__('affiliates::cp.col_available'))->visible(false),
            Column::make('created_at')->label(__('affiliates::cp.col_booked_at'))->visible(false),
        ]));
    }

    /** @return array<string, string> */
    public static function statusColours(): array
    {
        return ['pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'reversed' => 'red'];
    }
}
