<?php

namespace App\Actions\Customers;

use App\Models\Dependent;
use App\Models\User;
use App\Rules\Cpf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Correct a dependent after the sale (US-7.1) with the same validations as
 * the inclusion, the CPF unique among the other dependents. The sequence,
 * and so the identification, never changes. Requires `customers.update`.
 */
class UpdateDependent
{
    /**
     * @param  array{name?: string|null, cpf?: string|null, birth_date?: string|null, kinship_id?: int|string|null}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Dependent $dependent, array $input): Dependent
    {
        if (! $user->can('update', $dependent->customer)) {
            throw new AuthorizationException;
        }

        $input['cpf'] = filled($input['cpf'] ?? null) ? Cpf::normalize($input['cpf']) : null;
        $input['name'] = trim((string) ($input['name'] ?? ''));

        $rules = AddDependent::rules($dependent->id);

        $validated = Validator::make(array_intersect_key($input, $rules), $rules, [], AddDependent::attributes())->validate();

        $dependent->update($validated);

        return $dependent;
    }
}
