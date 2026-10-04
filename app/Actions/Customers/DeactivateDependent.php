<?php

namespace App\Actions\Customers;

use App\Models\Dependent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Deactivate a dependent (US-7.1): the plan stops covering them and the slot
 * is freed for a new dependent, while the record keeps its sequence,
 * identification and history. Requires `customers.update`.
 */
class DeactivateDependent
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, Dependent $dependent): Dependent
    {
        if (! $user->can('update', $dependent->customer)) {
            throw new AuthorizationException;
        }

        $dependent->update(['is_active' => false]);

        return $dependent;
    }
}
