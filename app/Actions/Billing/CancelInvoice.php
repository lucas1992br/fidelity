<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a pending invoice (`open` or `overdue`); paid or already cancelled
 * invoices are rejected. Nothing is deleted.
 */
class CancelInvoice
{
    /**
     * @throws ValidationException
     */
    public function handle(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! InvoiceStatusEnum::fromId($invoice->invoice_status_id)->isPayable()) {
                throw ValidationException::withMessages([
                    'invoice' => 'Só mensalidades em aberto ou em atraso podem ser canceladas.',
                ]);
            }

            $invoice->forceFill(['invoice_status_id' => InvoiceStatusEnum::Cancelled->id()])->save();

            return $invoice;
        });
    }
}
