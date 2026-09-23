<?php

namespace Goldnead\Affiliates\Events;

use Goldnead\Affiliates\Models\Commission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sale earned a partner a commission (or a joint-venture share). Once per
 * row: a payment booked twice dispatches nothing the second time.
 */
class CommissionEarned
{
    use Dispatchable;

    public function __construct(public readonly Commission $commission) {}
}
