<?php

namespace App\Livewire\Forms;

use App\Actions\Customers\AddDependent;
use App\Models\Dependent;
use App\Rules\Cpf;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * Dependent data (sale repeater and, ignoring the record itself in the CPF
 * uniqueness, the dependent edit modal).
 */
class DependentForm extends Form
{
    /**
     * Record being edited; null for a new dependent.
     */
    #[Locked]
    public ?int $dependentId = null;

    public string $name = '';

    public string $cpf = '';

    public string $birth_date = '';

    public int|string|null $kinship_id = null;

    /**
     * Load an existing dependent for editing.
     */
    public function setDependent(Dependent $dependent): void
    {
        $this->dependentId = $dependent->id;
        $this->name = $dependent->name;
        $this->cpf = (string) $dependent->cpf;
        $this->birth_date = $dependent->birth_date->toDateString();
        $this->kinship_id = $dependent->kinship_id;
    }

    /**
     * Normalize the CPF to digits (empty = not informed) and validate.
     *
     * @return array{name: string, cpf: string|null, birth_date: string, kinship_id: int}
     */
    public function validated(): array
    {
        $this->name = trim($this->name);
        $this->cpf = Cpf::normalize($this->cpf);

        $validated = $this->validate();

        /** @var array{name: string, cpf: string|null, birth_date: string, kinship_id: int} */
        return [
            'name' => $validated['name'],
            'cpf' => filled($validated['cpf']) ? $validated['cpf'] : null,
            'birth_date' => $validated['birth_date'],
            'kinship_id' => (int) $validated['kinship_id'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return AddDependent::rules($this->dependentId);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return AddDependent::attributes();
    }
}
