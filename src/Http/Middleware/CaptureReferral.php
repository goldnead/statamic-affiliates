<?php

namespace Goldnead\Affiliates\Http\Middleware;

use Closure;
use Goldnead\Affiliates\Support\Tracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Notes a partner code from the query string, on every page of the site.
 *
 * Pushed onto the `web` group, after EncryptCookies and StartSession, so the
 * cookie it writes is encrypted and the session is there to fall back on. A
 * failure here must never cost a visitor the page, so it is logged and the
 * response goes out unchanged.
 */
class CaptureReferral
{
    public function __construct(protected Tracking $tracking) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($this->skips($request)) {
            return $response;
        }

        try {
            $this->tracking->handle($request, $response);
        } catch (Throwable $e) {
            Log::warning('statamic-affiliates: the referral on this request could not be noted.', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    protected function skips(Request $request): bool
    {
        $cp = trim((string) config('statamic.cp.route', 'cp'), '/');

        return $cp !== '' && ($request->is($cp) || $request->is($cp.'/*'));
    }
}
