<?php

use App\Actions\Customers\UpdateCustomer;
use App\Livewire\Forms\CustomerForm;
use App\Models\Customer;
use App\Support\Customers\CustomerRules;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edit the holder (US-4.6): personal data, contact, address (ViaCEP) and
 * due day, with the sale validations. Card, plan and dependents are not
 * edited here. Requires `customers.update` on mount and on save.
 */
new class extends Component
{
    #[Locked]
    public Customer $customer;

    public CustomerForm $form;

    public function mount(Customer $customer): void
    {
        $this->authorize('update', $customer);

        $this->customer = $customer;
        $this->form->setCustomer($customer);
    }

    public function save(UpdateCustomer $updateCustomer): void
    {
        $this->authorize('update', $this->customer);

        $validated = $this->form->validated();

        try {
            $updateCustomer->handle($this->customer, $validated);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])
                ->all());
        }

        session()->flash('notify', ['title' => 'Cadastro atualizado', 'description' => $this->customer->name]);

        $this->redirectRoute('customers.show', $this->customer, navigate: true);
    }

    public function render(): View
    {
        return $this->view([
            'states' => CustomerRules::STATES,
        ])->title('Editar cliente')->layoutData(['crumb' => 'Clientes']);
    }
};
?>

<form
    wire:submit="save"
    x-data="{
        cepLoading: false,
        cepWarning: null,
        async lookupCep(value) {
            const cep = value.replace(/\D/g, '');
            this.cepWarning = null;

            if (cep.length !== 8) {
                return;
            }

            this.cepLoading = true;

            try {
                const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);

                if (! response.ok) {
                    throw new Error('ViaCEP indisponível');
                }

                const address = await response.json();

                if (address.erro) {
                    this.cepWarning = 'CEP não encontrado. Preencha o endereço manualmente.';

                    return;
                }

                $wire.set('form.street', address.logradouro || $wire.form.street, false);
                $wire.set('form.district', address.bairro || $wire.form.district, false);
                $wire.set('form.city', address.localidade || $wire.form.city, false);
                $wire.set('form.state', address.uf || $wire.form.state, false);
                $refs.addressNumber?.focus();
            } catch (error) {
                this.cepWarning = 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.';
            } finally {
                this.cepLoading = false;
            }
        },
    }"
    class="mx-auto flex w-full max-w-[960px] flex-col gap-6"
>
    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Dados pessoais</x-ui.card-title>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,240px),1fr))] gap-4">
            <x-ui.field label="Nome completo" for="customer-name" error="form.name" class="sm:col-span-2">
                <x-ui.input id="customer-name" x-init="$nextTick(() => $el.focus())" wire:model="form.name" error="form.name" autocomplete="off" />
            </x-ui.field>
            <x-ui.field label="CPF" for="customer-cpf" error="form.cpf">
                <x-ui.input id="customer-cpf" wire:model="form.cpf" error="form.cpf" mask="cpf" />
            </x-ui.field>
            <x-ui.field label="Data de nascimento" for="customer-birth-date" error="form.birth_date">
                <x-ui.input id="customer-birth-date" type="date" max="{{ today()->subDay()->toDateString() }}" wire:model="form.birth_date" error="form.birth_date" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Contato</x-ui.card-title>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,240px),1fr))] gap-4">
            <x-ui.field label="Celular / WhatsApp" for="customer-phone" error="form.phone">
                <x-ui.input id="customer-phone" wire:model="form.phone" error="form.phone" mask="phone" />
            </x-ui.field>
            <x-ui.field label="Telefone secundário" for="customer-phone-secondary" error="form.phone_secondary" hint="Opcional.">
                <x-ui.input id="customer-phone-secondary" wire:model="form.phone_secondary" error="form.phone_secondary" mask="phone" />
            </x-ui.field>
            <x-ui.field label="E-mail" for="customer-email" error="form.email" hint="Opcional.">
                <x-ui.input id="customer-email" type="email" wire:model="form.email" error="form.email" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Endereço</x-ui.card-title>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,200px),1fr))] gap-4">
            <x-ui.field label="CEP" for="customer-zip-code" error="form.zip_code">
                <x-ui.input id="customer-zip-code" wire:model="form.zip_code" error="form.zip_code" mask="zip" x-on:input.debounce.300ms="lookupCep($event.target.value)" />
                <span x-show="cepLoading" x-cloak class="text-xs text-ink-500">Consultando CEP…</span>
            </x-ui.field>
            <x-ui.field label="Logradouro" for="customer-street" error="form.street" class="sm:col-span-2">
                <x-ui.input id="customer-street" wire:model="form.street" error="form.street" />
            </x-ui.field>
            <x-ui.field label="Número" for="customer-number" error="form.number">
                <x-ui.input id="customer-number" x-ref="addressNumber" wire:model="form.number" error="form.number" />
            </x-ui.field>
            <x-ui.field label="Complemento" for="customer-complement" error="form.complement" hint="Opcional.">
                <x-ui.input id="customer-complement" wire:model="form.complement" error="form.complement" />
            </x-ui.field>
            <x-ui.field label="Bairro" for="customer-district" error="form.district">
                <x-ui.input id="customer-district" wire:model="form.district" error="form.district" />
            </x-ui.field>
            <x-ui.field label="Cidade" for="customer-city" error="form.city">
                <x-ui.input id="customer-city" wire:model="form.city" error="form.city" />
            </x-ui.field>
            <x-ui.field label="UF" for="customer-state" error="form.state">
                <x-ui.select id="customer-state" wire:model="form.state" error="form.state" placeholder="Selecione" :options="array_combine($states, $states)" />
            </x-ui.field>
        </div>
        <x-ui.callout tone="warning" x-show="cepWarning" x-cloak><span x-text="cepWarning"></span></x-ui.callout>
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Vencimento</x-ui.card-title>
        <x-ui.field label="Dia de vencimento" for="customer-due-day" error="form.due_day" hint="De 1 a 28. Vale para as próximas mensalidades; as já geradas mantêm o vencimento." class="max-w-60">
            <x-ui.input id="customer-due-day" type="number" min="1" max="28" wire:model="form.due_day" error="form.due_day" />
        </x-ui.field>
    </x-ui.card>

    <div class="flex justify-end gap-2">
        <x-ui.button variant="secondary" size="lg" :href="route('customers.show', $customer)" wire:navigate>Cancelar</x-ui.button>
        <x-ui.button type="submit" size="lg" icon="circle-check" wire:loading.attr="disabled" wire:target="save">Salvar alterações</x-ui.button>
    </div>
</form>
