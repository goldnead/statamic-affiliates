<?php

namespace Goldnead\Affiliates\Events;

use Goldnead\Affiliates\Models\Partner;
use Illuminate\Foundation\Events\Dispatchable;

/** A partner became active: approved in the Control Panel, or on sign-up with automatic approval. */
class PartnerApproved
{
    use Dispatchable;

    public function __construct(public readonly Partner $partner) {}
}
