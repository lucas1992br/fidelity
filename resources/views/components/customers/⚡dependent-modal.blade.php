<?php

use App\Actions\Customers\AddDependent;
use App\Actions\Customers\UpdateDependent;
use App\Livewire\Forms\DependentForm;
use App\Models\Customer;
use App\Models\Kinship;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Dependent inclusion and edit modal of the customer sheet (US-7.1), with the
 * `DependentForm`. A new dependent runs `AddDependent` (plan limit and next
 * sequence); an existing one runs `UpdateDependent`. Requires
 * `customers.update` (customer policy) to open and to save.
 */
new class extends Component
{
    #[Locked]
    public int $customerId;

    public bool $isOpen = false;

    public DependentForm $form;

    /**
     * Open empty for a new dependent, or loaded with the given dependent.
     */
    #[On('open-dependent')]
    public function open(?int $dependent = null): void
    {
        $this->authorize('update', $this->customer);

        $this->form->reset();
        $this->resetValidation();

        if ($dependent !== null) {
            $this->form->setDependent($this->customer->dependents()->findOrFail($dependent));
        }

        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->form->reset();
        $this->resetValidation();
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::query()->with('plan')->findOrFail($this->customerId);
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function kinships(): Collection
    {
        return Kinship::query()->active()->orderBy('id')->pluck('name', 'id');
    }

    public function save(AddDependent $addDependent, UpdateDependent $updateDependent): void
    {
        $this->authorize('update', $this->customer);

        $input = $this->form->validated();

        try {
            if ($this->form->dependentId === null) {
                $dependent = $addDependent->handle($this->customer, $input);
                $title = 'Dependente adicionado';
            } else {
                $dependent = $updateDependent->handle(auth()->user(), $this->customer->dependents()->findOrFail($this->form->dependentId), $input);
                $title = 'Dependente atualizado';
            }
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$key === 'dependents' ? $key : "form.{$key}" => $messages])
                ->all());
        }

        $dependent->setRelation('customer', $this->customer);

        $this->dispatch('notify', title: $title, description: collect([$dependent->name, $dependent->identification])->filter()->implode(' · '));
        $this->dispatch('customer-dependents-updated');
        $this->close();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal :title="$form->dependentId ? 'Editar dependente' : 'Adicionar dependente'" :subtitle="$this->customer->name.' · plano '.$this->customer->plan->name" close="close">
            @error('dependents')
                <x-ui.callout tone="danger">{{ $message }}</x-ui.callout>
            @enderror

            <x-ui.field label="Nome" for="dependent-modal-name" error="form.name">
                <x-ui.input id="dependent-modal-name" wire:model="form.name" error="form.name" autofocus />
            </x-ui.field>
            <x-ui.field label="CPF" for="dependent-modal-cpf" error="form.cpf" hint="Opcional.">
                <x-ui.input id="dependent-modal-cpf" wire:model="form.cpf" error="form.cpf" mask="cpf" />
            </x-ui.field>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Data de nascimento" for="dependent-modal-birth-date" error="form.birth_date">
                    <x-ui.input id="dependent-modal-birth-date" type="date" max="{{ today()->toDateString() }}" wire:model="form.birth_date" error="form.birth_date" />
                </x-ui.field>
                <x-ui.field label="Parentesco" for="dependent-modal-kinship" error="form.kinship_id">
                    <x-ui.select id="dependent-modal-kinship" wire:model="form.kinship_id" error="form.kinship_id" placeholder="Selecione" :options="$this->kinships" />
                </x-ui.field>
            </div>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ $form->dependentId ? 'Salvar' : 'Adicionar dependente' }}</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
