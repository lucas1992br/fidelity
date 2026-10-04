<?php

namespace App\Support\Customers;

use App\Actions\Customers\SearchCustomers;
use App\Models\Customer;
use App\Models\Dependent;

/**
 * One hit of {@see SearchCustomers}: the holder, the dependent found by
 * identification (if any) and the number of the no longer current card
 * that was searched (if any).
 */
final readonly class CustomerSearchResult
{
    public function __construct(
        public Customer $customer,
        public ?Dependent $dependent = null,
        public ?string $replacedCardNumber = null,
    ) {}

    /**
     * Number of the card in force, or null when the holder has none.
     */
    public function currentCardNumber(): ?string
    {
        return $this->customer->currentCard?->number;
    }
}
