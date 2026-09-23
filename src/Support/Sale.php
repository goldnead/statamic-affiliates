<?php

namespace Goldnead\Affiliates\Support;

use Goldnead\Affiliates\Integrations\PaymentsBridge;
use Illuminate\Support\Carbon;

/**
 * A paid payment, reduced to what commission is computed from.
 *
 * Built from a statamic-payments row by {@see PaymentsBridge},
 * so the ledger never touches another addon's model and can be tested
 * without one.
 */
final class Sale
{
    /** A first payment. */
    public const ROLE_FIRST = 'first';

    /** A renewal of a subscription. */
    public const ROLE_CYCLE = 'cycle';

    /**
     * The difference charged when a subscription changes plan. Earns like a
     * renewal, but is not one: it does not count towards "the first n".
     */
    public const ROLE_SWITCH = 'switch';

    /** A follow-up offer accepted after another payment. */
    public const ROLE_UPSELL = 'upsell';

    /**
     * @param  list<array{id: int|null, product: string, kind: string, total_cent: int}>  $lines
     *                                                                                            `kind` is `primary` or `bump`.
     */
    public function __construct(
        public readonly int $paymentId,
        public readonly int $brandId,
        public readonly string $role,
        public readonly ?int $originPaymentId,
        public readonly string $product,
        public readonly int $amountCent,
        public readonly string $currency,
        public readonly ?string $email,
        public readonly ?string $couponCode,
        public readonly Carbon $paidAt,
        public readonly array $lines,
    ) {}

    /** A renewal or a plan switch: a payment on a running subscription. */
    public function isRecurring(): bool
    {
        return $this->role === self::ROLE_CYCLE || $this->role === self::ROLE_SWITCH;
    }

    /** The payment whose referral this sale inherits. Itself for a first payment. */
    public function originId(): int
    {
        return $this->originPaymentId ?? $this->paymentId;
    }
}
