<?php

namespace App\Actions\Billing;

use App\Models\Invoice;

/**
 * Total due for paying `$months` consecutive months starting at the invoice's
 * reference month: the frozen amount of each month already invoiced plus the
 * holder's current plan price for each month not invoiced yet. Shared by
 * `RegisterPayment` and the payment modal, which prefills the amount with it.
 */
class PaymentPeriodAmount
{
    public function handle(Invoice $invoice, int $months): string
    {
        $referenceMonths = collect(range(0, $months - 1))
            ->map(fn (int $offset): string => $invoice->reference_month->copy()->addMonthsNoOverflow($offset)->toDateString());

        $invoiced = Invoice::query()
            ->where('customer_id', $invoice->customer_id)
            ->whereIn('reference_month', $referenceMonths)
            ->pluck('amount');

        $missingMonths = $months - $invoiced->count();
        $total = $invoiced->reduce(fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0.00');

        if ($missingMonths === 0) {
            return $total;
        }

        return bcadd($total, bcmul($invoice->customer->plan()->firstOrFail()->price, (string) $missingMonths, 2), 2);
    }
}
