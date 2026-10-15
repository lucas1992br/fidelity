<?php

namespace App\Actions\Companies;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Rules\Cnpj;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a company. A new due day is copied to every employee, so
 * the next invoices of the consolidated bill share it (invoices already
 * issued keep their due date). An inactive company keeps its employees but
 * receives no new ones. Requires `customers.update`.
 */
class SaveCompany
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $input, User $user, ?Company $company = null): Company
    {
        if (! $user->can('customers.update')) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(self::normalize($input), [
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'cnpj' => ['required', 'string', new Cnpj, Rule::unique('companies', 'cnpj')->ignore($company?->id)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'digits_between:10,11'],
            'due_day' => ['required', 'integer', 'between:1,28'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ], [
            'cnpj.unique' => 'Este CNPJ já pertence a outra empresa.',
            'phone.digits_between' => 'Informe o telefone com DDD.',
            'due_day.between' => 'O dia de vencimento deve ser entre 1 e 28.',
        ], [
            'name' => 'razão social',
            'trade_name' => 'nome fantasia',
            'cnpj' => 'CNPJ',
            'contact_name' => 'contato',
            'email' => 'e-mail',
            'phone' => 'telefone',
            'due_day' => 'dia de vencimento',
            'notes' => 'observações',
            'is_active' => 'ativa',
        ])->validate();

        return DB::transaction(function () use ($validated, $company): Company {
            $company ??= new Company;
            $company->fill($validated)->save();

            if ($company->wasChanged('due_day')) {
                $company->employees()->get()->each(fn (Customer $employee): bool => $employee->update(['due_day' => $company->due_day]));
            }

            return $company;
        });
    }

    /**
     * Keep only digits in CNPJ and phone, trim the texts and turn empty optional fields into null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['cnpj', 'phone'] as $field) {
            $input[$field] = (string) preg_replace('/\D/', '', is_scalar($input[$field] ?? null) ? (string) $input[$field] : '');
        }

        foreach (['name', 'trade_name', 'contact_name', 'email', 'notes'] as $field) {
            $value = is_string($input[$field] ?? null) ? trim($input[$field]) : ($input[$field] ?? null);
            $input[$field] = $field !== 'name' && blank($value) ? null : $value;
        }

        return $input;
    }
}
