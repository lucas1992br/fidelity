<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Support\Customers\CustomerRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Correct the holder data after the sale (US-4.6) with the same validations
 * as the sale, the CPF unique among the other holders. Card, plan and
 * dependents are not touched; a new `due_day` applies only to the invoices
 * generated from now on (existing invoices keep their due date).
 */
class UpdateCustomer
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(Customer $customer, array $input): Customer
    {
        $rules = CustomerRules::updateRules($customer->id);

        $validated = Validator::make(
            CustomerRules::normalize(array_intersect_key($input, $rules)),
            $rules,
            CustomerRules::messages(),
            CustomerRules::attributes(),
        )->validate();

        $customer->update($validated);

        return $customer;
    }
}
