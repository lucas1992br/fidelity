<?php

use App\Actions\Companies\SaveCompany;
use App\Models\Company;
use App\Support\Format;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Create/edit company modal, opened by the `open-company` event (with the
 * company id when editing) from the company list and sheet. Confirming runs
 * `SaveCompany`; a new company opens its sheet, an edit dispatches
 * `company-saved`. Requires `customers.update` to open and to save.
 */
new class extends Component
{
    public bool $isOpen = false;

    #[Locked]
    public ?int $companyId = null;

    public string $name = '';

    public string $trade_name = '';

    public string $cnpj = '';

    public string $contact_name = '';

    public string $email = '';

    public string $phone = '';

    public int|string $due_day = '';

    public string $notes = '';

    public bool $is_active = true;

    #[On('open-company')]
    public function open(?int $company = null): void
    {
        $this->authorize('customers.update');

        $this->resetForm();

        if ($company !== null) {
            $found = Company::query()->findOrFail($company);

            $this->companyId = $found->id;
            $this->fill([
                ...$found->only(['name', 'due_day', 'is_active']),
                'trade_name' => (string) $found->trade_name,
                'cnpj' => Format::cnpj($found->cnpj),
                'contact_name' => (string) $found->contact_name,
                'email' => (string) $found->email,
                'phone' => $found->phone,
                'notes' => (string) $found->notes,
            ]);
        }

        $this->isOpen = true;
    }

    public function save(SaveCompany $saveCompany): void
    {
        $this->authorize('customers.update');

        $company = $saveCompany->handle(
            $this->only('name', 'trade_name', 'cnpj', 'contact_name', 'email', 'phone', 'due_day', 'notes', 'is_active'),
            auth()->user(),
            $this->companyId ? Company::query()->findOrFail($this->companyId) : null,
        );

        if ($this->companyId === null) {
            session()->flash('notify', ['title' => 'Empresa cadastrada', 'description' => $company->display_name]);
            $this->redirectRoute('companies.show', $company, navigate: true);

            return;
        }

        $this->dispatch('notify', title: 'Empresa atualizada', description: $company->display_name);
        $this->dispatch('company-saved');
        $this->close();
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('companyId', 'name', 'trade_name', 'cnpj', 'contact_name', 'email', 'phone', 'due_day', 'notes', 'is_active');
        $this->resetValidation();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal :title="$companyId ? 'Editar empresa' : 'Nova empresa'" subtitle="Os funcionários vencem no dia da empresa e entram na fatura única dela." close="close">
            <x-ui.field label="Razão social" for="company-name" error="name">
                <x-ui.input id="company-name" wire:model="name" error="name" autocomplete="off" />
            </x-ui.field>

            <div class="grid grid-cols-2 gap-4">
                <x-ui.field label="Nome fantasia" for="company-trade-name" error="trade_name" hint="Opcional.">
                    <x-ui.input id="company-trade-name" wire:model="trade_name" error="trade_name" />
                </x-ui.field>
                <x-ui.field label="CNPJ" for="company-cnpj" error="cnpj">
                    <x-ui.input id="company-cnpj" wire:model="cnpj" error="cnpj" mask="cnpj" />
                </x-ui.field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <x-ui.field label="Contato" for="company-contact" error="contact_name" hint="Opcional.">
                    <x-ui.input id="company-contact" wire:model="contact_name" error="contact_name" />
                </x-ui.field>
                <x-ui.field label="Telefone" for="company-phone" error="phone">
                    <x-ui.input id="company-phone" wire:model="phone" error="phone" mask="phone" />
                </x-ui.field>
            </div>

            <div class="grid grid-cols-[minmax(0,1fr)_140px] gap-4">
                <x-ui.field label="E-mail" for="company-email" error="email" hint="Opcional.">
                    <x-ui.input id="company-email" type="email" wire:model="email" error="email" />
                </x-ui.field>
                <x-ui.field label="Vencimento" for="company-due-day" error="due_day" hint="Dia 1 a 28.">
                    <x-ui.input id="company-due-day" type="number" min="1" max="28" wire:model="due_day" error="due_day" />
                </x-ui.field>
            </div>

            <x-ui.field label="Observações" for="company-notes" error="notes">
                <x-ui.textarea id="company-notes" wire:model="notes" error="notes" rows="2" placeholder="Opcional" />
            </x-ui.field>

            <label class="flex items-center gap-2.5 text-sm font-semibold text-ink-800">
                <input type="checkbox" wire:model="is_active" class="size-4 accent-guardian-500">
                Ativa para novos funcionários
            </label>

            @if ($companyId)
                <span class="text-xs text-ink-500">Mudar o vencimento muda o dia de todos os funcionários a partir da próxima mensalidade.</span>
            @endif

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ $companyId ? 'Salvar alterações' : 'Cadastrar empresa' }}</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
