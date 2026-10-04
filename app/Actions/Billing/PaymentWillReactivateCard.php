<?php

namespace App\Actions\Billing;

use App\Enums\CardStatus as CardStatusEnum;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Invoice;
use App\Settings\ClinicSettings;

/**
 * Whether paying the invoice reactivates the holder's card (US-9.2): the card
 * in force is `suspended` and, once this invoice is paid, no other `overdue`
 * invoice is late for `suspend_after_days` days or more. A cancelled card is
 * never in force, so it is never reactivated. Shared by `RegisterPayment` and
 * the warning of the payment modal.
 */
class PaymentWillReactivateCard
{
    public function __construct(protected ClinicSettings $settings) {}

    public function handle(Invoice $invoice): bool
    {
        $card = $invoice->customer->currentCard()->first();

        if ($card === null || ! $card->hasStatus(CardStatusEnum::Suspended)) {
            return false;
        }

        return ! Invoice::query()
            ->where('customer_id', $invoice->customer_id)
            ->whereKeyNot($invoice->id)
            ->where('invoice_status_id', InvoiceStatusEnum::Overdue->id())
            ->whereDate('due_date', '<=', today()->subDays($this->settings->suspend_after_days))
            ->exists();
    }
}
