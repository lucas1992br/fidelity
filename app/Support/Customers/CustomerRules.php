<?php

namespace App\Support\Customers;

use App\Rules\Cpf;
use Illuminate\Validation\Rule;

/**
 * Validation of the holder (titular) data, shared by the sale Action and the
 * `CustomerForm` (sale and, ignoring the record itself, the edit screen).
 * Values must be normalized with {@see self::normalize()} before validating.
 */
class CustomerRules
{
    /**
     * Brazilian state abbreviations (UF).
     *
     * @var list<string>
     */
    public const array STATES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?int $ignoreCustomerId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'cpf' => ['required', 'string', new Cpf, Rule::unique('customers', 'cpf')->ignore($ignoreCustomerId)],
            'birth_date' => ['required', 'date', 'before:today'],
            'phone' => ['required', 'digits_between:10,11'],
            'phone_secondary' => ['nullable', 'digits_between:10,11'],
            'email' => ['nullable', 'email', 'max:255'],
            'zip_code' => ['required', 'digits:8'],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', Rule::in(self::STATES)],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'due_day' => ['required', 'integer', 'between:1,28'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'cpf.unique' => 'Este CPF já pertence a outro titular.',
            'birth_date.before' => 'A data de nascimento deve ser anterior a hoje.',
            'phone.digits_between' => 'Informe o celular com DDD.',
            'phone_secondary.digits_between' => 'Informe o telefone com DDD.',
            'zip_code.digits' => 'O CEP deve ter 8 dígitos.',
            'state.in' => 'Informe uma UF válida.',
            'plan_id.exists' => 'Selecione um plano ativo.',
            'due_day.between' => 'O dia de vencimento deve ser entre 1 e 28.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => 'nome',
            'cpf' => 'CPF',
            'birth_date' => 'data de nascimento',
            'phone' => 'celular',
            'phone_secondary' => 'telefone secundário',
            'email' => 'e-mail',
            'zip_code' => 'CEP',
            'street' => 'logradouro',
            'number' => 'número',
            'complement' => 'complemento',
            'district' => 'bairro',
            'city' => 'cidade',
            'state' => 'UF',
            'plan_id' => 'plano',
            'due_day' => 'dia de vencimento',
        ];
    }

    /**
     * Keep only digits in CPF, CEP and phones, trim the texts, upper-case the
     * UF and turn empty optional fields into null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['cpf', 'zip_code', 'phone', 'phone_secondary'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = (string) preg_replace('/\D/', '', is_scalar($input[$field]) ? (string) $input[$field] : '');
            }
        }

        foreach (['name', 'email', 'street', 'number', 'complement', 'district', 'city'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }

        if (isset($input['state']) && is_string($input['state'])) {
            $input['state'] = mb_strtoupper(trim($input['state']));
        }

        foreach (['email', 'phone_secondary', 'complement'] as $field) {
            if (array_key_exists($field, $input) && blank($input[$field])) {
                $input[$field] = null;
            }
        }

        return $input;
    }
}
