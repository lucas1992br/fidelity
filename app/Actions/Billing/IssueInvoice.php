<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Customer;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Issue the customer's invoice of a reference month. The plan, the holder's
 * monthly amount and company are frozen on the invoice; a month that already
 * has an invoice is left untouched (one invoice per customer per month).
 */
class IssueInvoice
{
    /**
     * @return Invoice|null The new invoice, or null when the month already had one.
     */
    public function handle(Customer $customer, CarbonInterface $referenceMonth): ?Invoice
    {
        $referenceMonth = CarbonImmutable::instance($referenceMonth)->startOfMonth();

        if ($customer->invoices()->forMonth($referenceMonth)->exists()) {
            return null;
        }

        $plan = $customer->plan()->firstOrFail();

        return $customer->invoices()->create([
            'company_id' => $customer->company_id,
            'plan_id' => $plan->id,
            'reference_month' => $referenceMonth->toDateString(),
            'amount' => $customer->monthly_amount,
            'due_date' => $referenceMonth->setDay($customer->due_day)->toDateString(),
            'invoice_status_id' => InvoiceStatusEnum::Open->id(),
        ]);
    }
}
