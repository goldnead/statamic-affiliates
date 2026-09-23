<?php

namespace Goldnead\Affiliates\Events;

use Goldnead\Affiliates\Models\Partner;
use Illuminate\Foundation\Events\Dispatchable;

/** Somebody signed up through the `{{ affiliates:apply }}` form. */
class PartnerApplied
{
    use Dispatchable;

    public function __construct(public readonly Partner $partner) {}
}
