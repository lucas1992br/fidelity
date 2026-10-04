<?php

namespace App\Console\Commands;

use App\Actions\Billing\GenerateMonthlyInvoices;
use App\Settings\ClinicSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily routine: on the configured generation day it bills the next month;
 * with `--month=YYYY-MM` it bills that month on any day.
 */
#[Signature('invoices:generate {--month= : Reference month (YYYY-MM); defaults to next month on the generation day}')]
#[Description('Generate the monthly invoices of the holders with a card in force')]
class GenerateInvoicesCommand extends Command
{
    public function handle(GenerateMonthlyInvoices $generateMonthlyInvoices, ClinicSettings $settings): int
    {
        $month = $this->option('month');

        if (filled($month)) {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month)) {
                $this->error('Informe o mês no formato YYYY-MM.');

                return self::INVALID;
            }

            $referenceMonth = CarbonImmutable::createFromFormat('!Y-m', (string) $month)->startOfMonth();
        } elseif (today()->day === $settings->invoice_generation_day) {
            $referenceMonth = CarbonImmutable::today()->startOfMonth()->addMonth();
        } else {
            $this->info('Hoje não é o dia de geração das mensalidades.');

            return self::SUCCESS;
        }

        $created = $generateMonthlyInvoices->handle($referenceMonth);

        activity('routines')
            ->event('invoices:generate')
            ->withProperties(['reference_month' => $referenceMonth->toDateString(), 'count' => $created])
            ->log("Mensalidades geradas ({$referenceMonth->format('m/Y')}): {$created}");

        $this->info("Mensalidades geradas: {$created}");

        return self::SUCCESS;
    }
}
