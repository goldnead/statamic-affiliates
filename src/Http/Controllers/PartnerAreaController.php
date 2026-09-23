<?php

namespace Goldnead\Affiliates\Http\Controllers;

use Goldnead\Affiliates\Affiliates;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Support\Tracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Statamic\Facades\User;

/**
 * The front end of the programme: the redirect link, sign-up, accepting an
 * invitation and a partner's own payout details.
 */
class PartnerAreaController extends Controller
{
    public function __construct(protected Affiliates $affiliates) {}

    /**
     * `/!/affiliates/go/{code}?to=/path`: a short link for places that cannot
     * carry a query string. Only paths on this site; an open redirect would
     * lend the site's name to anybody's phishing link.
     */
    public function go(Request $request, string $code): RedirectResponse
    {
        $to = (string) $request->query('to', '/');

        if (! str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            $to = '/';
        }

        // The referral is noted here, on a route PHP always handles, and the
        // visitor lands on the plain page. That is what makes this link work
        // behind full static caching, where `?ref=` on a cached page is
        // answered by the web server and never reaches the middleware.
        $response = redirect($to);

        app(Tracking::class)->capture($request, $response, mb_substr($code, 0, 64));

        return $response;
    }

    public function apply(Request $request): RedirectResponse
    {
        $user = User::current();

        if ($user === null) {
            return back()->withErrors(['affiliates' => __('affiliates::messages.login_required')], 'affiliates');
        }

        if (! config('affiliates.signup.enabled', true)) {
            return back()->withErrors(['affiliates' => __('affiliates::messages.signup_closed')], 'affiliates');
        }

        $data = $request->validateWithBag('affiliates', [
            'name' => ['nullable', 'string', 'max:191'],
            'website' => ['nullable', 'url', 'max:500'],
            'message' => ['nullable', 'string', 'max:2000'],
            'terms' => ['accepted'],
        ]);

        $partner = $this->affiliates->apply($user, $data);

        return back()->with('affiliates.success', $partner->isActive()
            ? __('affiliates::messages.applied_active')
            : __('affiliates::messages.applied_pending'));
    }

    public function invite(string $token): RedirectResponse
    {
        $user = User::current();

        if ($user === null) {
            $login = (string) config('affiliates.routes.login_url', '/login');

            return redirect($login.(str_contains($login, '?') ? '&' : '?').'redirect='.rawurlencode('/!/affiliates/invite/'.$token));
        }

        $partner = $this->affiliates->acceptInvite($token, $user);

        return redirect('/')->with('affiliates.success', $partner
            ? __('affiliates::messages.invite_accepted')
            : __('affiliates::messages.invite_invalid'));
    }

    public function details(Request $request): RedirectResponse
    {
        $partner = $this->affiliates->partnerFor(User::current());

        if (! $partner instanceof Partner) {
            return back()->withErrors(['affiliates' => __('affiliates::messages.login_required')], 'affiliates');
        }

        $data = $request->validateWithBag('affiliates', [
            'payout_method' => ['required', 'in:bank,paypal,other'],
            'payout_details' => ['required', 'string', 'max:500'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $partner->forceFill([
            'payout_method' => $data['payout_method'],
            'payout_details' => $data['payout_details'],
            'notify' => (bool) ($data['notify'] ?? false),
        ])->save();

        return back()->with('affiliates.success', __('affiliates::messages.details_saved'));
    }
}
