<?php

use App\Actions\Finance\SettleFinancialEntry;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Models\FinancialEntry;
use App\Models\PaymentMethod;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Settlement modal (US-10.4), opened by the `open-settle-entry` event from the
 * entries, payables and receivables screens: payment date and method, then
 * `SettleFinancialEntry`. Requires `finance.manage` (entry policy) to open
 * and confirm; confirming dispatches `financial-entry-saved`.
 */
new class extends Component
{
    public ?int $entryId = null;

    public string $paidAt = '';

    public string $method = '';

    #[On('open-settle-entry')]
    public function open(int $entry): void
    {
        $this->authorize('finance.manage');

        $found = FinancialEntry::query()->findOrFail($entry);
        $this->authorize('settle', $found);

        if ($found->entry_status_id !== EntryStatus::Pending->id()) {
            $this->dispatch('notify', type: 'error', title: 'Este lançamento já foi baixado ou cancelado.');

            return;
        }

        $this->resetValidation();
        $this->entryId = $found->id;
        $this->paidAt = today()->format('d/m/Y');
        $this->method = $this->methods->first()?->slug->value ?? '';
        unset($this->entry);
    }

    public function close(): void
    {
        $this->reset('entryId');
        $this->resetValidation();
    }

    /**
     * The entry being settled, shown in the subtitle.
     */
    #[Computed]
    public function entry(): ?FinancialEntry
    {
        return $this->entryId ? FinancialEntry::query()->with('category')->find($this->entryId) : null;
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

    public function confirm(SettleFinancialEntry $settleEntry): void
    {
        $this->authorize('finance.manage');

        $entry = $this->entry;

        if ($entry === null) {
            $this->close();

            return;
        }

        $this->authorize('settle', $entry);

        $this->validate([
            'paidAt' => ['required', 'date_format:d/m/Y'],
            'method' => ['required', Rule::in($this->methods->map(fn (PaymentMethod $method): string => $method->slug->value)->all())],
        ], [], ['paidAt' => 'data do pagamento', 'method' => 'forma de pagamento']);

        try {
            $settleEntry->handle($entry, [
                'paid_at' => CarbonImmutable::createFromFormat('!d/m/Y', $this->paidAt)->toDateString(),
                'payment_method' => $this->method,
            ]);
        } catch (ValidationException $exception) {
            $fields = ['paid_at' => 'paidAt', 'payment_method' => 'method'];

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$fields[$key] ?? $key => $messages])
                ->all());
        }

        $this->dispatch(
            'notify',
            title: $entry->entry_type_id === EntryType::Income->id() ? 'Recebimento registrado' : 'Pagamento registrado',
            description: $entry->description.' · '.Format::money($entry->amount),
        );
        $this->dispatch('financial-entry-saved', entry: $entry->id);

        $this->close();
    }
};
?>

<div>
    @if ($this->entry)
        @php($entry = $this->entry)

        <x-ui.modal
            title="Dar baixa"
            :subtitle="$entry->description.' · '.$entry->category->name.' · '.\App\Support\Format::money($entry->amount).' · venc. '.\App\Support\Format::date($entry->due_date)"
            close="close"
        >
            @error('entry')
                <x-ui.callout tone="danger">{{ $message }}</x-ui.callout>
            @enderror

            <x-ui.field error="paidAt">
                <x-ui.input label="Data do pagamento" wire:model="paidAt" mask="date" error="paidAt" />
            </x-ui.field>

            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold text-ink-800">Forma de pagamento</span>
                <div class="grid grid-cols-4 gap-2">
                    @foreach ($this->methods as $option)
                        <x-ui.choice wire:key="settle-method-{{ $option->slug->value }}" :active="$method === $option->slug->value" wire:click="$set('method', '{{ $option->slug->value }}')">
                            {{ $option->slug->label() }}
                        </x-ui.choice>
                    @endforeach
                </div>
                @error('method') <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span> @enderror
            </div>

            <x-slot:actions>
                <x-ui.button size="lg" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm">Confirmar baixa</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
