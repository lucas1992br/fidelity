<?php

use App\Actions\Finance\CreateFinancialEntry;
use App\Actions\Finance\UpdateFinancialEntry;
use App\Enums\EntryType;
use App\Livewire\Forms\FinancialEntryForm;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\PaymentMethod;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Manual entry modal (US-10.1), opened by the `open-financial-entry` event:
 * empty for a new entry or loaded with a manual one. The type is a choice,
 * the categories follow the type and "Já pago?" reveals the payment date and
 * method. Requires `finance.manage` (and the entry policy) to open and save;
 * saving dispatches `financial-entry-saved` so the screens reload.
 */
new class extends Component
{
    public bool $isOpen = false;

    public FinancialEntryForm $form;

    #[On('open-financial-entry')]
    public function open(?int $entry = null): void
    {
        $this->authorize('finance.manage');

        $this->form->reset();
        $this->resetValidation();
        $this->form->fillDefaults();

        if ($entry !== null) {
            $found = FinancialEntry::query()->findOrFail($entry);
            $this->authorize('update', $found);

            $this->form->setEntry($found);
        }

        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->form->reset();
        $this->resetValidation();
    }

    /**
     * Switching the type clears the category, since categories belong to one type.
     */
    public function chooseType(string $type): void
    {
        $this->form->entry_type = EntryType::tryFrom($type)?->value ?? EntryType::Expense->value;
        $this->form->financial_category_id = null;
    }

    /**
     * Active categories of the chosen type, plus the current one when editing.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function categories(): Collection
    {
        $currentId = $this->form->entryId ? FinancialEntry::query()->whereKey($this->form->entryId)->value('financial_category_id') : null;

        return FinancialCategory::optionsFor(EntryType::tryFrom($this->form->entry_type) ?? EntryType::Expense, $currentId);
    }

    /**
     * Active payment methods offered as choices.
     *
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function methods(): Collection
    {
        return PaymentMethod::query()->active()->orderBy('id')->get();
    }

    public function save(CreateFinancialEntry $createEntry, UpdateFinancialEntry $updateEntry): void
    {
        $this->authorize('finance.manage');

        try {
            if ($this->form->entryId === null) {
                $entry = $createEntry->handle($this->form->input(), auth()->user());
                $title = 'Lançamento registrado';
            } else {
                $entry = FinancialEntry::query()->findOrFail($this->form->entryId);
                $this->authorize('update', $entry);

                $updateEntry->handle($entry, $this->form->input());
                $title = 'Lançamento atualizado';
            }
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$key === 'entry' ? $key : "form.{$key}" => $messages])
                ->all());
        }

        $this->dispatch('notify', title: $title, description: $entry->description.' · '.Format::money($entry->amount));
        $this->dispatch('financial-entry-saved', entry: $entry->id);
        $this->close();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal :title="$form->entryId ? 'Editar lançamento' : 'Novo lançamento'" subtitle="Receita ou despesa avulsa; pode ser registrada já paga." close="close">
            @error('entry')
                <x-ui.callout tone="danger">{{ $message }}</x-ui.callout>
            @enderror

            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold text-ink-800">Tipo</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (EntryType::cases() as $option)
                        <x-ui.choice wire:key="entry-type-{{ $option->value }}" :active="$form->entry_type === $option->value" wire:click="chooseType('{{ $option->value }}')">
                            {{ $option->label() }}
                        </x-ui.choice>
                    @endforeach
                </div>
                @error('form.entry_type') <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span> @enderror
            </div>

            <x-ui.field label="Categoria" for="entry-category" error="form.financial_category_id">
                <x-ui.select id="entry-category" wire:model="form.financial_category_id" error="form.financial_category_id" placeholder="Selecione" :options="$this->categories" />
            </x-ui.field>

            <x-ui.field label="Descrição" for="entry-description" error="form.description">
                <x-ui.input id="entry-description" wire:model="form.description" error="form.description" autocomplete="off" />
            </x-ui.field>

            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Valor (R$)" for="entry-amount" error="form.amount">
                    <x-ui.input id="entry-amount" wire:model="form.amount" error="form.amount" mask="money" placeholder="0,00" />
                </x-ui.field>
                <x-ui.field label="Vencimento" for="entry-due-date" error="form.due_date">
                    <x-ui.input id="entry-due-date" type="date" wire:model="form.due_date" error="form.due_date" />
                </x-ui.field>
            </div>

            <x-ui.field :label="$form->entry_type === 'income' ? 'Pagador' : 'Fornecedor'" for="entry-supplier" error="form.supplier_or_payer" hint="Opcional.">
                <x-ui.input id="entry-supplier" wire:model="form.supplier_or_payer" error="form.supplier_or_payer" autocomplete="off" />
            </x-ui.field>

            <x-ui.field label="Observação" for="entry-notes" error="form.notes">
                <x-ui.textarea id="entry-notes" wire:model="form.notes" error="form.notes" rows="2" placeholder="Opcional" />
            </x-ui.field>

            <label class="flex items-center gap-2.5 text-sm font-semibold text-ink-800">
                <input type="checkbox" wire:model.live="form.is_paid" class="size-4 accent-guardian-500">
                Já pago?
            </label>

            @if ($form->is_paid)
                <div class="flex flex-col gap-4 rounded-md bg-ink-50 p-3.5" data-slot="paid-fields">
                    <x-ui.field label="Data do pagamento" for="entry-paid-at" error="form.paid_at">
                        <x-ui.input id="entry-paid-at" type="date" wire:model="form.paid_at" error="form.paid_at" />
                    </x-ui.field>

                    <div class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-semibold text-ink-800">Forma de pagamento</span>
                        <div class="grid grid-cols-4 gap-2">
                            @foreach ($this->methods as $option)
                                <x-ui.choice wire:key="entry-method-{{ $option->slug->value }}" :active="$form->payment_method === $option->slug->value" wire:click="$set('form.payment_method', '{{ $option->slug->value }}')">
                                    {{ $option->slug->label() }}
                                </x-ui.choice>
                            @endforeach
                        </div>
                        @error('form.payment_method') <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span> @enderror
                    </div>
                </div>
            @endif

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ $form->entryId ? 'Salvar alterações' : 'Registrar lançamento' }}</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
