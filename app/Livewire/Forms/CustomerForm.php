<?php

namespace App\Livewire\Forms;

use App\Models\Customer;
use App\Settings\ClinicSettings;
use App\Support\Customers\CustomerRules;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * Holder (titular) data of the sale and, ignoring the record itself in the
 * CPF uniqueness, of the edit screen.
 */
class CustomerForm extends Form
{
    /**
     * Record being edited; null on a new sale.
     */
    #[Locked]
    public ?int $customerId = null;

    public string $name = '';

    public string $cpf = '';

    public string $birth_date = '';

    public string $phone = '';

    public string $phone_secondary = '';

    public string $email = '';

    public string $zip_code = '';

    public string $street = '';

    public string $number = '';

    public string $complement = '';

    public string $district = '';

    public string $city = '';

    public string $state = '';

    public int|string|null $plan_id = null;

    public int|string $due_day = '';

    /**
     * Defaults of a new sale: the clinic's default due day.
     */
    public function fillDefaults(ClinicSettings $settings): void
    {
        $this->due_day = $settings->default_due_day;
    }

    /**
     * Load an existing holder for editing.
     */
    public function setCustomer(Customer $customer): void
    {
        $this->customerId = $customer->id;
        $this->fill([
            ...$customer->only(['name', 'cpf', 'phone', 'street', 'number', 'district', 'city', 'state', 'zip_code', 'plan_id', 'due_day']),
            'birth_date' => $customer->birth_date->toDateString(),
            'phone_secondary' => (string) $customer->phone_secondary,
            'email' => (string) $customer->email,
            'complement' => (string) $customer->complement,
        ]);
    }

    /**
     * Normalize (CPF, CEP and phones to digits) and validate.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $normalized = CustomerRules::normalize($this->except('customerId'));

        $this->fill(array_map(fn (mixed $value): mixed => $value ?? '', $normalized));

        return CustomerRules::normalize($this->validate());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return $this->customerId === null
            ? CustomerRules::rules()
            : CustomerRules::updateRules($this->customerId);
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return CustomerRules::messages();
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return CustomerRules::attributes();
    }
}
