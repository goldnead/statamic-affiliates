<?php

namespace Goldnead\Affiliates\Integrations;

use Goldnead\Affiliates\Support\Attribution;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The optional coupling to statamic-payments.
 *
 * Event and model classes are named as strings and checked with
 * `class_exists`, so nothing here autoloads a package that is not installed.
 *
 * **No listener here ever throws.** payments dispatches PaymentPaid inside its
 * fulfilment claim and releases the claim when a listener fails; the provider
 * then redelivers, and the buyer would get their access twice because a
 * commission could not be booked. A commission that failed is logged and can
 * be booked again with `affiliates:book {payment}`.
 */
class PaymentsBridge
{
    public const PAYMENT = '\Goldnead\StatamicPayments\Models\Payment';

    public const PAID = '\Goldnead\StatamicPayments\Events\PaymentPaid';

    public const REFUNDED = '\Goldnead\StatamicPayments\Events\PaymentRefunded';

    public const CHARGED_BACK = '\Goldnead\StatamicPayments\Events\PaymentChargedBack';

    public static function available(): bool
    {
        return class_exists(self::PAYMENT) && class_exists(self::PAID);
    }

    /** The payment row is being created in the buyer's own checkout request. */
    public static function created(Model $payment): void
    {
        try {
            if (self::isFollowOn($payment)) {
                return;
            }

            app(Attribution::class)->fromRequest(
                (int) $payment->getKey(),
                (int) ($payment->getAttribute('brand_id') ?? 0),
                request(),
            );
        } catch (Throwable $e) {
            Log::warning('statamic-affiliates: could not note the referral on a new payment.', [
                'payment_id' => $payment->getKey(),
                'event' => 'created',
                'exception' => $e,
            ]);
        }
    }

    public static function paid(object $event): void
    {
        $payment = $event->payment ?? null;

        if (! $payment instanceof Model) {
            return;
        }

        try {
            app(Ledger::class)->book(self::sale($payment));
        } catch (Throwable $e) {
            Log::error('statamic-affiliates: a paid payment could not be booked; run affiliates:book to retry.', [
                'payment_id' => $payment->getKey(),
                'event' => 'paid',
                'exception' => $e,
            ]);
        }
    }

    public static function refunded(object $event): void
    {
        $payment = $event->payment ?? null;

        if (! $payment instanceof Model) {
            return;
        }

        try {
            $amount = (int) $payment->getAttribute('amount_cent');
            $refunded = (bool) ($event->isFull ?? false)
                ? $amount
                : max((int) $payment->getAttribute('refunded_cent'), (int) ($event->amountCent ?? 0));

            app(Ledger::class)->reverse((int) $payment->getKey(), $amount, $refunded, 'refund');
        } catch (Throwable $e) {
            Log::error('statamic-affiliates: a refund could not be applied to the commissions; run affiliates:book --reverse to catch up.', [
                'payment_id' => $payment->getKey(),
                'event' => 'refund',
                'exception' => $e,
            ]);
        }
    }

    public static function chargedBack(object $event): void
    {
        $payment = $event->payment ?? null;

        if (! $payment instanceof Model) {
            return;
        }

        try {
            $amount = (int) $payment->getAttribute('amount_cent');

            app(Ledger::class)->reverse((int) $payment->getKey(), $amount, $amount, 'chargeback');
        } catch (Throwable $e) {
            Log::error('statamic-affiliates: a chargeback could not be applied to the commissions; run affiliates:book --reverse to catch up.', [
                'payment_id' => $payment->getKey(),
                'event' => 'chargeback',
                'exception' => $e,
            ]);
        }
    }

    /**
     * A renewal or a follow-up offer. Both inherit the referral of the payment
     * they follow and must not take a new one from whatever cookie the buyer
     * carries today.
     */
    protected static function isFollowOn(Model $payment): bool
    {
        $meta = $payment->getAttribute('meta');

        return $payment->getAttribute('parent_payment_id') !== null
            || (is_array($meta) && (isset($meta['cycle_of']) || isset($meta['subscription_change'])));
    }

    /** The first payment of a subscription: the lowest id that carries it. */
    protected static function firstPaymentOf(int $subscriptionId, Model $payment): int
    {
        $id = $payment->newQuery()
            ->where('subscription_id', $subscriptionId)
            ->whereKeyNot($payment->getKey())
            ->orderBy($payment->getKeyName())
            ->value($payment->getKeyName());

        return is_numeric($id) ? (int) $id : 0;
    }

    /** The paid payment as the ledger reads it. */
    public static function sale(Model $payment): Sale
    {
        $meta = is_array($payment->getAttribute('meta')) ? $payment->getAttribute('meta') : [];
        $parent = $payment->getAttribute('parent_payment_id');
        $first = $meta['cycle_of']['first_payment_id'] ?? null;

        $switched = $meta['subscription_change']['subscription_id'] ?? null;

        [$role, $origin] = match (true) {
            $parent !== null => [Sale::ROLE_UPSELL, (int) $parent],
            is_numeric($first) && (int) $first !== (int) $payment->getKey() => [Sale::ROLE_CYCLE, (int) $first],
            // A plan switch charges the difference as its own payment. It
            // belongs to the subscription it changes, so it is a renewal of
            // that subscription's first payment and inherits its referral;
            // origin 0 when that payment cannot be found, which means none.
            is_array($meta['subscription_change'] ?? null) => [Sale::ROLE_SWITCH, is_numeric($switched) ? self::firstPaymentOf((int) $switched, $payment) : 0],
            default => [Sale::ROLE_FIRST, null],
        };

        if ($role === Sale::ROLE_SWITCH && $origin === 0) {
            Log::warning('statamic-affiliates: a plan switch has no first payment to inherit its referral from; it earns no commission.', [
                'payment_id' => $payment->getKey(),
                'subscription_id' => $switched,
            ]);
        }

        $coupon = $payment->getAttribute('discount_code');

        if (! is_string($coupon) || $coupon === '') {
            $coupon = is_string($meta['coupon']['code'] ?? null) ? $meta['coupon']['code'] : null;
        }

        $lines = [];

        if (method_exists($payment, 'items')) {
            foreach ($payment->items()->get() as $item) {
                $total = (int) $item->getAttribute('amount_cent') * max(1, (int) $item->getAttribute('quantity'))
                    - (int) $item->getAttribute('discount_cent');

                $lines[] = [
                    'id' => (int) $item->getKey(),
                    'product' => (string) ($item->getAttribute('product') ?: $payment->getAttribute('product')),
                    'kind' => $item->getAttribute('kind') === 'bump' ? 'bump' : 'primary',
                    'total_cent' => max(0, $total),
                ];
            }
        }

        if ($lines === []) {
            $lines[] = [
                'id' => null,
                'product' => (string) $payment->getAttribute('product'),
                'kind' => 'primary',
                'total_cent' => (int) $payment->getAttribute('amount_cent'),
            ];
        }

        $paidAt = $payment->getAttribute('paid_at');

        return new Sale(
            paymentId: (int) $payment->getKey(),
            brandId: (int) ($payment->getAttribute('brand_id') ?? 0),
            role: $role,
            originPaymentId: $origin,
            product: (string) $payment->getAttribute('product'),
            amountCent: (int) $payment->getAttribute('amount_cent'),
            currency: mb_strtoupper((string) ($payment->getAttribute('currency') ?: 'EUR')),
            email: is_string($payment->getAttribute('email')) ? $payment->getAttribute('email') : null,
            couponCode: is_string($coupon) && $coupon !== '' ? $coupon : null,
            paidAt: $paidAt instanceof Carbon ? $paidAt : Carbon::now(),
            lines: $lines,
        );
    }
}
