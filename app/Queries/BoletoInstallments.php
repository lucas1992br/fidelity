<?php

namespace App\Queries;

use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pending installments of boleto payments split by the payer: the covered
 * invoices are already paid, but each installment is still owed by the
 * holder (or by the company, for its consolidated bill) until settled in
 * accounts receivable. An installment past its due date counts as a late
 * payment just like an overdue invoice: for the card suspension and
 * reactivation, the delinquents and the overdue amounts.
 */
class BoletoInstallments
{
    /**
     * Pending installments of non-refunded boleto payments
     * (`financial_entries` joined to `payments`).
     */
    public static function pending(): Builder
    {
        return DB::table('financial_entries')
            ->join('payments', 'payments.id', '=', 'financial_entries.payment_id')
            ->where('financial_entries.entry_type_id', EntryType::Income->id())
            ->where('financial_entries.entry_status_id', EntryStatus::Pending->id())
            ->whereNull('financial_entries.deleted_at')
            ->where('payments.payment_method_id', PaymentMethod::Boleto->id())
            ->whereNull('payments.refunded_at');
    }

    /**
     * Pending installments due before the given date (or on it, when inclusive).
     */
    public static function dueBefore(CarbonInterface $date, bool $inclusive = false): Builder
    {
        return self::pending()->whereDate('financial_entries.due_date', $inclusive ? '<=' : '<', $date->toDateString());
    }

    /**
     * One row per installment and holder it binds (`customer_id`, `due_date`,
     * `amount`, `company_id`): the holder of a holder's payment, or every
     * employee covered by a company's payment.
     */
    public static function byHolder(Builder $installments): Builder
    {
        return $installments
            ->join('invoice_payment', 'invoice_payment.payment_id', '=', 'payments.id')
            ->join('invoices as covered', 'covered.id', '=', 'invoice_payment.invoice_id')
            ->select([
                'covered.customer_id',
                'financial_entries.id as entry_id',
                'financial_entries.due_date',
                'financial_entries.amount',
                'payments.company_id',
            ]);
    }

    /**
     * Total amount of the given installments.
     */
    public static function amountOf(Builder $installments): string
    {
        return (string) $installments->selectRaw('coalesce(sum(financial_entries.amount), 0)::numeric(14, 2) as amount')->value('amount');
    }
}
