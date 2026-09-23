<?php

namespace Goldnead\Affiliates\Support;

use Goldnead\Affiliates\Models\Partner;
use Goldnead\BrandContext\Sending\BrandMailer;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a partner mail as the partner's brand (statamic-brand-context's
 * sender identity). A mail that cannot go out is logged, never thrown: it is
 * always a side effect of something that already happened.
 */
class Mailer
{
    public function send(Partner $partner, Mailable $mailable): bool
    {
        try {
            if (empty($mailable->from)) {
                $mailable->from(config('mail.from.address'), config('mail.from.name'));
            }

            return app(BrandMailer::class)->send($partner->brand_id ?: null, $partner->email, $partner->name, $mailable);
        } catch (Throwable $e) {
            Log::warning('statamic-affiliates: a partner mail could not be sent.', [
                'partner_id' => $partner->getKey(),
                'mail' => $mailable::class,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
