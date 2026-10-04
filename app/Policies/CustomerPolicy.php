<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    /**
     * Customer list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    /**
     * Full customer sheet.
     */
    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view');
    }

    /**
     * New sale: holder, dependents and a card from stock.
     */
    public function create(User $user): bool
    {
        return $user->can('sales.create');
    }

    /**
     * Edit the customer and dependents after the sale.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.update');
    }

    /**
     * Export the holder's data (LGPD access right).
     */
    public function export(User $user, Customer $customer): bool
    {
        return $user->can('customers.view');
    }
}
