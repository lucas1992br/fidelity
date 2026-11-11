<?php

use App\Settings\ClinicSettings;
use Livewire\Component;

/**
 * Clinic data and program parameters (US-12.1). Saved values apply
 * immediately; only `settings.manage` (admin) may open or save.
 */
new class extends Component
{
    public string $clinic_name = '';

    public ?string $clinic_cnpj = null;

    public ?string $clinic_address = null;

    public ?string $clinic_phone = null;

    public ?int $default_due_day = null;

    public ?int $suspend_after_days = null;

    public ?int $invoice_generation_day = null;

    public ?int $min_card_stock = null;

    public ?string $card_number_pattern = null;

    public float|string|null $enrollment_commission_percent = null;

    public function mount(ClinicSettings $settings): void
    {
        $this->authorize('settings.manage');

        $this->fill($settings->toArray());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'clinic_name' => ['required', 'string', 'max:255'],
            'clinic_cnpj' => ['nullable', 'string', 'max:18'],
            'clinic_address' => ['nullable', 'string', 'max:255'],
            'clinic_phone' => ['nullable', 'string', 'max:20'],
            'default_due_day' => ['required', 'integer', 'between:1,28'],
            'suspend_after_days' => ['required', 'integer', 'min:1'],
            'invoice_generation_day' => ['required', 'integer', 'between:1,28'],
            'min_card_stock' => ['required', 'integer', 'min:0'],
            'enrollment_commission_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'card_number_pattern' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (@preg_match($value, '') === false) {
                    $fail('O padrão do número do cartão não é uma expressão regular válida.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'clinic_name' => 'nome da clínica',
            'clinic_cnpj' => 'CNPJ',
            'clinic_address' => 'endereço',
            'clinic_phone' => 'telefone',
            'default_due_day' => 'dia de vencimento padrão',
            'suspend_after_days' => 'dias para suspensão',
            'invoice_generation_day' => 'dia de geração das faturas',
            'min_card_stock' => 'estoque mínimo de cartões',
            'card_number_pattern' => 'padrão do número do cartão',
            'enrollment_commission_percent' => 'comissão sobre a taxa de adesão',
        ];
    }

    public function save(ClinicSettings $settings): void
    {
        $this->authorize('settings.manage');

        $validated = $this->validate();

        $settings->fill([
            ...array_map(
                fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value,
                $validated,
            ),
            'enrollment_commission_percent' => (float) $validated['enrollment_commission_percent'],
        ])->save();

        $this->dispatch('notify', title: 'Configurações salvas', description: 'Os novos valores já estão valendo.');
    }
};
?>

<form wire:submit="save" class="flex flex-col gap-6">
    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Dados da clínica</x-ui.card-title>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,280px),1fr))] gap-4">
            <x-ui.field label="Nome da clínica" for="clinic-name" error="clinic_name">
                <x-ui.input id="clinic-name" wire:model="clinic_name" error="clinic_name" />
            </x-ui.field>
            <x-ui.field label="CNPJ" for="clinic-cnpj" error="clinic_cnpj">
                <x-ui.input id="clinic-cnpj" wire:model="clinic_cnpj" error="clinic_cnpj" x-mask="99.999.999/9999-99" inputmode="numeric" />
            </x-ui.field>
            <x-ui.field label="Endereço" for="clinic-address" error="clinic_address">
                <x-ui.input id="clinic-address" wire:model="clinic_address" error="clinic_address" />
            </x-ui.field>
            <x-ui.field label="Telefone" for="clinic-phone" error="clinic_phone">
                <x-ui.input id="clinic-phone" wire:model="clinic_phone" error="clinic_phone" mask="phone" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-5">
        <x-ui.card-title>Parâmetros do programa</x-ui.card-title>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,280px),1fr))] gap-4">
            <x-ui.field label="Dia de vencimento padrão" for="default-due-day" error="default_due_day" hint="De 1 a 28. Sugerido nas novas adesões.">
                <x-ui.input id="default-due-day" type="number" min="1" max="28" wire:model="default_due_day" error="default_due_day" />
            </x-ui.field>
            <x-ui.field label="Dias para suspensão" for="suspend-after-days" error="suspend_after_days" hint="Dias de atraso até o cartão ser suspenso.">
                <x-ui.input id="suspend-after-days" type="number" min="1" wire:model="suspend_after_days" error="suspend_after_days" />
            </x-ui.field>
            <x-ui.field label="Dia de geração das faturas" for="invoice-generation-day" error="invoice_generation_day" hint="De 1 a 28. As mensalidades do mês seguinte são geradas neste dia.">
                <x-ui.input id="invoice-generation-day" type="number" min="1" max="28" wire:model="invoice_generation_day" error="invoice_generation_day" />
            </x-ui.field>
            <x-ui.field label="Estoque mínimo de cartões" for="min-card-stock" error="min_card_stock" hint="Abaixo deste número o alerta de estoque é exibido.">
                <x-ui.input id="min-card-stock" type="number" min="0" wire:model="min_card_stock" error="min_card_stock" />
            </x-ui.field>
            <x-ui.field label="Padrão do número do cartão" for="card-number-pattern" error="card_number_pattern" hint="Opcional. Expressão regular, ex.: /^\d{6}$/">
                <x-ui.input id="card-number-pattern" wire:model="card_number_pattern" error="card_number_pattern" class="font-mono" />
            </x-ui.field>
            <x-ui.field label="Comissão sobre a taxa de adesão (%)" for="enrollment-commission-percent" error="enrollment_commission_percent" hint="De 0 a 100. Devida ao atendente quando a 1ª mensalidade da venda é paga.">
                <x-ui.input id="enrollment-commission-percent" type="number" min="0" max="100" step="0.01" wire:model="enrollment_commission_percent" error="enrollment_commission_percent" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <div class="flex justify-end">
        <x-ui.button type="submit" size="lg">Salvar configurações</x-ui.button>
    </div>
</form>
