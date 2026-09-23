<?php

namespace Goldnead\Affiliates\Events;

use Goldnead\Affiliates\Models\Commission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A refund, a chargeback or somebody in the Control Panel took back all or
 * part of a commission. For a commission already paid out, `$commission` is
 * the negative claw-back row.
 */
class CommissionReversed
{
    use Dispatchable;

    public function __construct(public readonly Commission $commission) {}
}
