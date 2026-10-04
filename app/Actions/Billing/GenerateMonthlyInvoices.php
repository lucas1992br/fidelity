<?php

namespace App\Actions\Billing;

use App\Models\Customer;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Issue the invoice of a reference month for every holder with a card in
 * force (active or suspended). Holders already billed for the month are
 * skipped, so running it again creates nothing new.
 */
class GenerateMonthlyInvoices
{
    public function __construct(protected IssueInvoice $issueInvoice) {}

    /**
     * @return int How many invoices were created.
     */
    public function handle(CarbonInterface $referenceMonth): int
    {
        $created = 0;

        Customer::query()
            ->whereHas('currentCard')
            ->chunkById(200, function (Collection $customers) use ($referenceMonth, &$created): void {
                foreach ($customers as $customer) {
                    if ($this->issueInvoice->handle($customer, $referenceMonth) !== null) {
                        $created++;
                    }
                }
            });

        return $created;
    }
}
