<?php

namespace App\Console\Commands;

use App\Actions\Billing\MarkOverdueInvoices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily routine that flags past-due open invoices as overdue.
 */
#[Signature('invoices:mark-overdue')]
#[Description('Mark open invoices past their due date as overdue')]
class MarkOverdueInvoicesCommand extends Command
{
    public function handle(MarkOverdueInvoices $markOverdueInvoices): int
    {
        $updated = $markOverdueInvoices->handle();

        activity('routines')
            ->event('invoices:mark-overdue')
            ->withProperties(['count' => $updated])
            ->log("Mensalidades marcadas em atraso: {$updated}");

        $this->info("Mensalidades marcadas em atraso: {$updated}");

        return self::SUCCESS;
    }
}
