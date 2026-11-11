<?php

use App\Models\CardFee;
use App\Settings\ClinicSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Card machine fees: the rate of each modality (debit, credit at once and
 * credit in 2 to 12 installments, the same for every brand, anticipation
 * included) and the days until the acquirer deposits the sale. A card
 * payment freezes the rate of its modality when registered, so a new rate
 * only applies to the next payments. Requires `finance.manage`.
 */
new class extends Component
{
    /**
     * Rates typed as pt-BR percentages ("2,99"), by card fee id.
     *
     * @var array<int, string>
     */
    public array $rates = [];

    public int|string $settlementDays = '';

    public function mount(ClinicSettings $settings): void
    {
        $this->authorize('finance.manage');

        $this->rates = $this->fees->mapWithKeys(fn (CardFee $fee): array => [$fee->id => str_replace('.', ',', $fee->rate)])->all();
        $this->settlementDays = $settings->card_settlement_days;
    }

    /**
     * Modalities in the order of the machine: debit, then credit 1x to 12x.
     *
     * @return Collection<int, CardFee>
     */
    #[Computed]
    public function fees(): Collection
    {
        return CardFee::query()->orderByDesc('is_debit')->orderBy('installments')->get();
    }

    public function save(ClinicSettings $settings): void
    {
        $this->authorize('finance.manage');

        $this->rates = array_map(fn (mixed $rate): string => trim((string) $rate), $this->rates);

        $validated = $this->validate([
            'rates' => ['required', 'array'],
            'rates.*' => ['required', 'regex:/^\d{1,3}(,\d{1,2})?$/', function (string $attribute, mixed $value, Closure $fail): void {
                if ((float) str_replace(',', '.', (string) $value) > 100) {
                    $fail('A taxa deve ser de no máximo 100%.');
                }
            }],
            'settlementDays' => ['required', 'integer', 'between:0,60'],
        ], [
            'rates.*.required' => 'Informe a taxa (0 se não houver).',
            'rates.*.regex' => 'Use até 2 casas decimais, ex.: 2,99.',
        ], ['settlementDays' => 'prazo de recebimento']);

        DB::transaction(function () use ($validated): void {
            foreach ($this->fees as $fee) {
                $fee->update(['rate' => str_replace(',', '.', $validated['rates'][$fee->id] ?? '0')]);
            }
        });

        $settings->card_settlement_days = (int) $validated['settlementDays'];
        $settings->save();

        $this->dispatch('notify', title: 'Taxas salvas', description: 'Valem para os próximos pagamentos no cartão.');
    }

    public function render(): View
    {
        return $this->view();
    }
};
?>

<form wire:submit="save" class="flex flex-col gap-6">
    <x-ui.callout tone="info">
        Taxas cobradas pela {{ \App\Models\CardFee::Acquirer }}, já com a antecipação, iguais para todas as bandeiras.
        Cada pagamento no cartão guarda a taxa do momento: mudar aqui vale só para os próximos.
    </x-ui.callout>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Taxas por modalidade</x-ui.card-title>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,180px),1fr))] gap-4">
            @foreach ($this->fees as $fee)
                <x-ui.field wire:key="fee-{{ $fee->id }}" :label="$fee->label().' (%)'" for="fee-{{ $fee->id }}" error="rates.{{ $fee->id }}">
                    <x-ui.input id="fee-{{ $fee->id }}" wire:model="rates.{{ $fee->id }}" error="rates.{{ $fee->id }}" inputmode="decimal" placeholder="0,00" />
                </x-ui.field>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Recebimento</x-ui.card-title>
        <x-ui.field label="Prazo de recebimento (dias)" for="settlement-days" error="settlementDays" hint="Dias até a operadora depositar a venda antecipada, ex.: 1 = D+1. Define o vencimento em Contas a receber." class="max-w-80">
            <x-ui.input id="settlement-days" type="number" min="0" max="60" wire:model="settlementDays" error="settlementDays" />
        </x-ui.field>
    </x-ui.card>

    <div class="flex justify-end">
        <x-ui.button type="submit" size="lg">Salvar taxas</x-ui.button>
    </div>
</form>
