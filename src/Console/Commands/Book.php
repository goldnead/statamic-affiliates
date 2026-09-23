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
    protected $signature = 'affiliates:book {payment : The statamic-payments payment id}';

    protected $description = 'Book the commissions of a paid payment (again)';

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

        $booked = $ledger->book(PaymentsBridge::sale($payment));

        $this->info(count($booked).' commission(s) booked.');

        return self::SUCCESS;
    }
}
