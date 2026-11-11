<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Issue the customer's invoice of a reference month. The plan, the holder's
 * monthly amount and company are frozen on the invoice; a month that already
 * has an invoice is left untouched (one invoice per customer per month).
 *
 * A company employee's invoice is zero, since the company pays a fixed
 * amount: the month's company bill is created with the company's current
 * fixed amount. An employee joining a month the company already paid gets
 * the invoice paid by that same payment, so the bill is not charged again.
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
        $companyPayment = null;

        if ($customer->isEmployee()) {
            $company = $customer->company()->firstOrFail();
            $company->bills()->firstOrCreate(
                ['reference_month' => $referenceMonth->toDateString()],
                ['amount' => $company->monthly_amount],
            );
            $companyPayment = $this->companyPaymentOf($customer->company_id, $referenceMonth);
        }

        $invoice = $customer->invoices()->create([
            'company_id' => $customer->company_id,
            'plan_id' => $plan->id,
            'reference_month' => $referenceMonth->toDateString(),
            'amount' => $customer->isEmployee() ? '0.00' : $customer->monthly_amount,
            'due_date' => $referenceMonth->setDay($customer->due_day)->toDateString(),
            'invoice_status_id' => ($companyPayment ? InvoiceStatusEnum::Paid : InvoiceStatusEnum::Open)->id(),
            'paid_at' => $companyPayment?->paid_at,
        ]);

        $companyPayment?->invoices()->attach($invoice->id);

        return $invoice;
    }

    /**
     * The company's non-refunded payment of the month's bill, if already paid.
     */
    protected function companyPaymentOf(int $companyId, CarbonImmutable $referenceMonth): ?Payment
    {
        return Payment::query()
            ->where('company_id', $companyId)
            ->whereNull('refunded_at')
            ->whereHas('invoice', fn ($invoice) => $invoice->forMonth($referenceMonth))
            ->first();
    }
}
