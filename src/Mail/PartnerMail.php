<?php

namespace Goldnead\Affiliates\Mail;

use Goldnead\Affiliates\Models\Partner;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The two mails about the partnership itself: the invitation and the
 * approval. One class, because they differ only in their words and link.
 */
class PartnerMail extends Mailable
{
    public const INVITATION = 'invitation';

    public const APPROVED = 'approved';

    public function __construct(
        public readonly Partner $partner,
        public readonly string $type,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('affiliates::mail.'.$this->type.'_subject', ['site' => config('app.name')]));
    }

    public function content(): Content
    {
        /** @var view-string $view */
        $view = 'affiliates::mail.partner';

        return new Content(
            view: $view,
            with: [
                'partner' => $this->partner,
                'type' => $this->type,
                'url' => $this->url,
            ],
        );
    }
}
