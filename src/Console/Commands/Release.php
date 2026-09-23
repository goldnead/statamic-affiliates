<?php

namespace Goldnead\Affiliates\Console\Commands;

use Goldnead\Affiliates\Support\Ledger;
use Illuminate\Console\Command;

/**
 * Moves commissions whose hold period is over to approved. The Control
 * Panel does the same whenever the lists are opened; schedule this daily if
 * partners should see it in their area without anybody looking.
 */
class Release extends Command
{
    protected $signature = 'affiliates:release';

    protected $description = 'Approve commissions whose hold period is over';

    public function handle(Ledger $ledger): int
    {
        $count = $ledger->release();

        $this->info("{$count} commission(s) approved.");

        return self::SUCCESS;
    }
}
