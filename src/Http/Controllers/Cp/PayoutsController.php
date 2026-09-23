<?php

namespace Goldnead\Affiliates\Http\Controllers\Cp;

use Goldnead\Affiliates\Http\Controllers\Cp\Concerns\RendersListing;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Payout;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Money;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\Affiliates\Support\Setup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Statamic\CP\Column;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PayoutsController extends CpController
{
    use RendersListing;

    public function index(Ledger $ledger): Response
    {
        Gate::authorize('manage affiliate payouts');

        $title = __('affiliates::cp.payouts');

        if ($setup = Setup::guard($title, 'money-bag-dollar')) {
            return $setup;
        }

        $ledger->release();

        $query = Payout::query()->orderByRaw("case status when 'open' then 0 else 1 end")->orderByDesc('id');
        $total = (clone $query)->count();
        $partners = Partner::query()->get()->keyBy('id');

        $rows = $query->limit($this->limit)->get()->map(function (Payout $p) use ($partners) {
            $partner = $partners->get($p->partner_id);
            $name = $partner instanceof Partner ? $partner->name : '—';

            return [
                'id' => $p->id,
                'url' => cp_route('affiliates.partners.show', $p->partner_id),
                'reference' => $p->reference,
                // Name and payment route in one cell, the paid date in the
                // badge: six columns, so the row menu stays on screen at 1440 px.
                'partner' => $name.' · '.($partner?->payout_method ? __('affiliates::cp.method_'.$partner->payout_method) : __('affiliates::cp.method_missing')),
                'amount' => Money::format($p->amount_cent, $p->currency),
                'commission_count' => $p->commission_count,
                'status' => $p->status,
                'status_label' => $p->paid_at !== null
                    ? __('affiliates::cp.payout_paid_on', ['date' => $p->paid_at->locale(app()->getLocale())->isoFormat('L')])
                    : __('affiliates::cp.payout_'.$p->status),
                'created_at' => $p->created_at?->toIso8601String(),
                'paid_at' => $p->paid_at?->toIso8601String(),
                'row_actions' => array_values(array_filter([
                    ['text' => __('affiliates::cp.export_csv'), 'url' => cp_route('affiliates.payouts.csv', ['payout' => $p->id]), 'icon' => 'download', 'download' => true],
                    $p->status === Payout::STATUS_OPEN ? [
                        'text' => __('affiliates::cp.mark_paid'),
                        'url' => cp_route('affiliates.payouts.paid', $p->id),
                        'method' => 'post',
                        'icon' => 'checkmark',
                        'confirm' => __('affiliates::cp.mark_paid_confirm', ['amount' => Money::format($p->amount_cent, $p->currency), 'partner' => $name]),
                    ] : null,
                ])),
            ];
        })->all();

        $due = Commission::query()
            ->where('status', Commission::STATUS_APPROVED)
            ->whereNull('payout_id')
            ->get()
            ->groupBy('currency')
            ->map(fn ($r, $c) => Money::format((int) $r->sum(fn (Commission $x) => $x->payableCent()), (string) $c))
            ->implode(' · ');

        $hasOpen = Payout::query()->where('status', Payout::STATUS_OPEN)->exists();

        return $this->listing($title, 'money-bag-dollar', 'payouts', [
            Column::make('reference')->label(__('affiliates::cp.col_reference')),
            Column::make('partner')->label(__('affiliates::cp.col_partner')),
            Column::make('amount')->label(__('affiliates::cp.col_amount'))->sortable(false),
            Column::make('commission_count')->label(__('affiliates::cp.col_commission_count')),
            Column::make('status')->label(__('affiliates::cp.col_status')),
            Column::make('created_at')->label(__('affiliates::cp.col_created')),
        ], $rows, $total, [
            'badges' => ['status' => ['open' => 'amber', 'paid' => 'green']],
            'dates' => ['created_at'],
            'mono' => ['reference'],
            'description' => $due !== ''
                ? __('affiliates::cp.payouts_due', ['amount' => $due, 'minimum' => Money::format((int) config('affiliates.payouts.minimum_cent', 5000), 'EUR')])
                : __('affiliates::cp.payouts_nothing_due'),
            'headerActions' => array_values(array_filter([
                $hasOpen ? ['text' => __('affiliates::cp.export_open_csv'), 'url' => cp_route('affiliates.payouts.csv'), 'download' => true] : null,
                ['text' => __('affiliates::cp.build_payouts'), 'url' => cp_route('affiliates.payouts.build'), 'method' => 'post', 'primary' => true],
            ])),
            'emptyHeading' => __('affiliates::cp.payouts_empty'),
            'emptyDescription' => __('affiliates::cp.payouts_empty_description'),
        ]);
    }

    public function build(Payouts $payouts): RedirectResponse
    {
        Gate::authorize('manage affiliate payouts');

        $created = $payouts->build();

        return back()->with('success', $created->isEmpty()
            ? __('affiliates::cp.payouts_none_built')
            : trans_choice('affiliates::cp.payouts_built', $created->count(), ['count' => $created->count()]));
    }

    public function paid(Payouts $payouts, int $payout): RedirectResponse
    {
        Gate::authorize('manage affiliate payouts');

        $payouts->markPaid(Payout::query()->findOrFail($payout));

        return back()->with('success', __('affiliates::cp.payout_marked_paid'));
    }

    /** One payout, or every open one when no id is given. */
    public function csv(Request $request, Payouts $payouts): HttpResponse
    {
        Gate::authorize('manage affiliate payouts');

        $id = $request->query('payout');
        $rows = is_numeric($id)
            ? Payout::query()->whereKey((int) $id)->get()
            : Payout::query()->where('status', Payout::STATUS_OPEN)->orderBy('id')->get();

        $name = 'auszahlungen-'.now()->format('Y-m-d').(is_numeric($id) ? '-'.$id : '').'.csv';

        return response($payouts->csv($rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
