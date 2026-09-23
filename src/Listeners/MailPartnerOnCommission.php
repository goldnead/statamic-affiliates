<?php

namespace Goldnead\Affiliates\Listeners;

use Goldnead\Affiliates\Events\CommissionEarned;
use Goldnead\Affiliates\Mail\CommissionEarnedMail;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Support\Brands;
use Goldnead\Affiliates\Support\Mailer;

/**
 * Tells the partner about a new commission, when the brand has the mail on
 * and the partner has not switched it off.
 */
class MailPartnerOnCommission
{
    public function __construct(protected Mailer $mailer) {}

    public function handle(CommissionEarned $event): void
    {
        $commission = $event->commission;
        $partner = Partner::query()->acrossBrands()->find($commission->partner_id);

        if ($partner === null || ! $partner->notify) {
            return;
        }

        Brands::runFor($partner->brand_id, function () use ($partner, $commission): void {
            if (! config('affiliates.mail.commission', true)) {
                return;
            }

            $this->mailer->send($partner, new CommissionEarnedMail($commission, $partner));
        });
    }
}
