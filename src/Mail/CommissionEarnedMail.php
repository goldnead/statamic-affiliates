<?php

namespace Goldnead\Affiliates\Mail;

use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Support\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "A sale earned you something." Tells the partner what, how much and from
 * when it can be paid out. Never who bought: the buyer's name and address are
 * not the partner's business.
 */
class CommissionEarnedMail extends Mailable
{
    public function __construct(public readonly Commission $commission, public readonly Partner $partner) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('affiliates::mail.commission_subject', [
                'amount' => Money::format($this->commission->amount_cent, $this->commission->currency),
            ]),
        );
    }

    public function content(): Content
    {
        /** @var view-string $view */
        $view = 'affiliates::mail.commission';

        return new Content(
            view: $view,
            with: [
                'partner' => $this->partner,
                'commission' => $this->commission,
                'amount' => Money::format($this->commission->amount_cent, $this->commission->currency),
                'kind' => __('affiliates::cp.kind_'.$this->commission->kind),
                'available' => $this->commission->available_at?->locale(app()->getLocale())->isoFormat('LL'),
                'pending' => $this->commission->status === Commission::STATUS_PENDING,
            ],
        );
    }
}
