<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Add a note to the customer sheet (US-4.9), recording the author; the
 * creation time is the note date. Blank notes are rejected.
 */
class AddCustomerNote
{
    /**
     * @throws ValidationException
     */
    public function handle(Customer $customer, string $body, User $user): CustomerNote
    {
        $validated = Validator::make(['body' => trim($body)], [
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'body.required' => 'Escreva a observação antes de adicionar.',
        ], [
            'body' => 'observação',
        ])->validate();

        return $customer->notes()->create([
            'body' => $validated['body'],
            'user_id' => $user->id,
        ]);
    }
}
