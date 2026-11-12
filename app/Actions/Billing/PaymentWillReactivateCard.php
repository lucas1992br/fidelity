<?php

namespace App\Actions\Billing;

use App\Enums\CardStatus as CardStatusEnum;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Customer;
use App\Models\Invoice;
use App\Queries\BoletoInstallments;
use App\Settings\ClinicSettings;

/**
 * Whether paying the invoice reactivates the holder's card (US-9.2): the card
 * in force is `suspended` and, once this invoice is paid, no other `overdue`
 * invoice nor pending boleto installment is late for `suspend_after_days`
 * days or more. A cancelled card is never in force, so it is never
 * reactivated. Shared by `RegisterPayment`, the warning of the payment modal
 * and the settlement of a boleto installment.
 */
class PaymentWillReactivateCard
{
    public function __construct(protected ClinicSettings $settings) {}

    public function handle(Invoice $invoice): bool
    {
        return $this->forCustomer($invoice->customer, exceptInvoiceId: $invoice->id);
    }

    /**
     * Whether the holder's suspended card may be reactivated now, leaving out
     * the invoice being paid.
     */
    public function forCustomer(Customer $customer, ?int $exceptInvoiceId = null): bool
    {
        $card = $customer->currentCard()->first();

        if ($card === null || ! $card->hasStatus(CardStatusEnum::Suspended)) {
            return false;
        }

        $cutoff = today()->subDays($this->settings->suspend_after_days);

        $lateInvoice = Invoice::query()
            ->where('customer_id', $customer->id)
            ->when($exceptInvoiceId, fn ($query, int $invoiceId) => $query->whereKeyNot($invoiceId))
            ->where('invoice_status_id', InvoiceStatusEnum::Overdue->id())
            ->whereDate('due_date', '<=', $cutoff)
            ->exists();

        $lateInstallment = BoletoInstallments::byHolder(BoletoInstallments::dueBefore($cutoff, inclusive: true))
            ->where('covered.customer_id', $customer->id)
            ->exists();

        return ! $lateInvoice && ! $lateInstallment;
    }
}
