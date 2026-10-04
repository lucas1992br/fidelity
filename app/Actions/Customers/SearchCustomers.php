<?php

namespace App\Actions\Customers;

use App\Models\Card;
use App\Models\Customer;
use App\Models\Dependent;
use App\Support\Customers\CustomerSearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Unified quick search (US-5.1, US-5.2). The term is resolved in order:
 * exact card number (current or old), dependent identification
 * `{card}-{NN}`, CPF (masked or not) and finally a partial name ignoring
 * case and accents. The first step that finds someone wins.
 */
class SearchCustomers
{
    public const int Limit = 10;

    /**
     * @return Collection<int, CustomerSearchResult>
     */
    public function handle(string $term): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        return $this->byCardNumber($term)
            ?? $this->byDependentIdentification($term)
            ?? $this->byCpf($term)
            ?? $this->byName($term);
    }

    /**
     * Relations shown on the result card.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return ['plan', 'currentCard', 'lastCard', 'activeDependents.kinship'];
    }

    /**
     * @return Collection<int, CustomerSearchResult>|null
     */
    protected function byCardNumber(string $term): ?Collection
    {
        $card = $this->linkedCard($term);
        $customer = $card === null ? null : $this->customer($card->customer_id);

        if ($customer === null) {
            return null;
        }

        return collect([new CustomerSearchResult($customer, replacedCardNumber: $card->is_current ? null : $card->number)]);
    }

    /**
     * @return Collection<int, CustomerSearchResult>|null
     */
    protected function byDependentIdentification(string $term): ?Collection
    {
        if (preg_match('/^(.+)-(\d{2})$/', $term, $matches) !== 1) {
            return null;
        }

        $card = $this->linkedCard($matches[1]);
        $customer = $card === null ? null : $this->customer($card->customer_id);

        if ($customer === null) {
            return null;
        }

        $dependent = $customer->activeDependents->firstWhere('sequence', (int) $matches[2]);

        return collect([new CustomerSearchResult(
            $customer,
            $dependent instanceof Dependent ? $dependent : null,
            $card->is_current ? null : $card->number,
        )]);
    }

    /**
     * @return Collection<int, CustomerSearchResult>|null
     */
    protected function byCpf(string $term): ?Collection
    {
        if (! Customer::looksLikeCpf($term)) {
            return null;
        }

        $customer = $this->query()->whereCpf($term)->first();

        return $customer === null ? null : collect([new CustomerSearchResult($this->withHolder($customer))]);
    }

    /**
     * @return Collection<int, CustomerSearchResult>
     */
    protected function byName(string $term): Collection
    {
        return $this->query()
            ->whereNameMatches($term)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::Limit)
            ->get()
            ->map(fn (Customer $customer): CustomerSearchResult => new CustomerSearchResult($this->withHolder($customer)));
    }

    /**
     * A card with exactly this number that is (or was) linked to a customer.
     */
    protected function linkedCard(string $number): ?Card
    {
        return Card::query()->where('number', $number)->whereNotNull('customer_id')->first();
    }

    protected function customer(?int $id): ?Customer
    {
        $customer = $this->query()->find($id);

        return $customer === null ? null : $this->withHolder($customer);
    }

    /**
     * @return Builder<Customer>
     */
    protected function query(): Builder
    {
        return Customer::query()->with(self::relations());
    }

    /**
     * Point the dependents back at the loaded holder, so their
     * identification reuses the eager-loaded current card.
     */
    protected function withHolder(Customer $customer): Customer
    {
        $customer->activeDependents->each(fn (Dependent $dependent) => $dependent->setRelation('customer', $customer));

        return $customer;
    }
}
