<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Invoice;

/**
 * Flag as overdue, in a single update, every open invoice whose due date has
 * passed. An invoice due today stays open; paid and cancelled invoices are
 * never touched.
 */
class MarkOverdueInvoices
{
    /**
     * @return int How many invoices became overdue.
     */
    public function handle(): int
    {
        return Invoice::query()
            ->where('invoice_status_id', InvoiceStatusEnum::Open->id())
            ->whereDate('due_date', '<', today())
            ->update(['invoice_status_id' => InvoiceStatusEnum::Overdue->id()]);
    }
}
