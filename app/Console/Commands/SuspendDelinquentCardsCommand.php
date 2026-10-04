<?php

namespace App\Console\Commands;

use App\Actions\Cards\SuspendDelinquentCards;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily routine that suspends the cards of holders late beyond the limit.
 */
#[Signature('cards:suspend-delinquent')]
#[Description('Suspend the active cards of holders with invoices overdue beyond the limit')]
class SuspendDelinquentCardsCommand extends Command
{
    public function handle(SuspendDelinquentCards $suspendDelinquentCards): int
    {
        $suspended = $suspendDelinquentCards->handle();

        activity('routines')
            ->event('cards:suspend-delinquent')
            ->withProperties(['count' => $suspended])
            ->log("Cartões suspensos por inadimplência: {$suspended}");

        $this->info("Cartões suspensos por inadimplência: {$suspended}");

        return self::SUCCESS;
    }
}
