<?php

namespace Goldnead\Affiliates\Console\Commands;

use Goldnead\Affiliates\Integrations\PaymentsBridge;
use Goldnead\Affiliates\Support\Ledger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Books a paid payment again, for the case the listener logged a failure.
 * Idempotent: what is already booked stays as it is.
 */
class Book extends Command
{
    protected $signature = 'affiliates:book {payment : The statamic-payments payment id}
        {--reverse : Catch up a refund or chargeback the listener missed}';

    protected $description = 'Book the commissions of a paid payment (again), or reverse them for a refunded one';

    public function handle(Ledger $ledger): int
    {
        if (! PaymentsBridge::available()) {
            $this->error('statamic-payments is not installed.');

            return self::FAILURE;
        }

        $class = PaymentsBridge::PAYMENT;
        $payment = $class::query()->find((int) $this->argument('payment'));

        if (! $payment instanceof Model || $payment->getAttribute('paid_at') === null) {
            $this->error('No paid payment with that id.');

            return self::FAILURE;
        }

        $amount = (int) $payment->getAttribute('amount_cent');
        $refunded = (int) $payment->getAttribute('refunded_cent');

        $chargedBack = $payment->getAttribute('charged_back_at') !== null;

        if ($this->option('reverse')) {
            $given = $chargedBack ? $amount : $refunded;

            if ($given <= 0) {
                $this->error('The payment has no refund or chargeback recorded; nothing to reverse.');

                return self::FAILURE;
            }

            $ledger->reverse((int) $payment->getKey(), $amount, $given, $chargedBack ? 'chargeback' : 'refund');

            $this->info('Commissions reversed for '.$given.' of '.$amount.' cent.');

            return self::SUCCESS;
        }

        // A refunded payment earns nothing: booking it now would pay a
        // commission on money that has been given back.
        if ($chargedBack || ($refunded > 0 && $refunded >= $amount)) {
            $this->error('The payment is refunded or charged back; nothing booked. Use --reverse to reverse commissions the refund event missed.');

            return self::FAILURE;
        }

        $booked = $ledger->book(PaymentsBridge::sale($payment));

        // A part-refunded payment is booked in full first, then the refunded
        // share is taken back, so the ledger matches the payment.
        if ($refunded > 0) {
            $ledger->reverse((int) $payment->getKey(), $amount, $refunded, 'refund');
        }

        $this->info(count($booked).' commission(s) booked.');

        return self::SUCCESS;
    }
}
