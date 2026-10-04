<?php

use App\Actions\Cards\BuildCardBatchPreview;
use App\Actions\Cards\RegisterCardBatch;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * "Cadastrar lote" modal (US-2.1, US-2.2): batch data plus the numbers by
 * range, pasted list or CSV upload. "Pré-visualizar" shows the valid and the
 * ignored numbers; "Confirmar cadastro" is only enabled for a preview of the
 * current input, and `RegisterCardBatch` rebuilds it on the server anyway.
 *
 * @phpstan-import-type IgnoredNumber from BuildCardBatchPreview
 */
new class extends Component
{
    use WithFileUploads;

    /**
     * How many valid numbers the preview lists (the count covers all of them).
     */
    public const int PREVIEW_LIMIT = 100;

    public bool $isOpen = false;

    public string $description = '';

    public string $supplier = '';

    public string $received_at = '';

    public string $mode = BuildCardBatchPreview::MODE_RANGE;

    public string $prefix = '';

    public int|string $start = '';

    public int|string $end = '';

    public int|string $digits = 6;

    public string $numbers = '';

    /**
     * @var UploadedFile|null
     */
    public $csv = null;

    /**
     * Preview of the current input; cleared whenever the input changes.
     *
     * @var array{valid_count: int, valid: list<string>, ignored: list<IgnoredNumber>}|null
     */
    public ?array $preview = null;

    #[On('open-register-card-batch')]
    public function open(): void
    {
        $this->authorize('cards.stock.manage');

        $this->resetForm();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->resetForm();
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, [BuildCardBatchPreview::MODE_RANGE, BuildCardBatchPreview::MODE_LIST, BuildCardBatchPreview::MODE_CSV], true)
            ? $mode
            : BuildCardBatchPreview::MODE_RANGE;

        $this->preview = null;
        $this->resetValidation();
    }

    /**
     * Any change to the numbers invalidates the preview shown.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['prefix', 'start', 'end', 'digits', 'numbers', 'csv'], true)) {
            $this->preview = null;
        }
    }

    public function previewBatch(BuildCardBatchPreview $buildPreview): void
    {
        $this->authorize('cards.stock.manage');

        $this->resetValidation();
        $preview = $buildPreview->handle($this->numbersInput());

        $this->preview = [
            'valid_count' => count($preview['valid']),
            'valid' => array_slice($preview['valid'], 0, self::PREVIEW_LIMIT),
            'ignored' => $preview['ignored'],
        ];
    }

    public function confirm(RegisterCardBatch $registerCardBatch): void
    {
        $this->authorize('cards.stock.manage');

        if (($this->preview['valid_count'] ?? 0) === 0) {
            return;
        }

        $batch = $registerCardBatch->handle(auth()->user(), [
            ...$this->only('description', 'supplier', 'received_at'),
            ...$this->numbersInput(),
        ]);

        $this->dispatch('notify', title: 'Lote cadastrado', description: "{$batch->quantity} ".($batch->quantity === 1 ? 'cartão entrou' : 'cartões entraram')." no estoque · {$batch->description}");
        $this->dispatch('card-stock-updated');
        $this->close();
    }

    /**
     * Input of {@see BuildCardBatchPreview} for the selected mode; the CSV
     * upload is validated and read here.
     *
     * @return array{mode: string, prefix?: string, start?: int|string, end?: int|string, digits?: int|string, numbers?: string}
     */
    protected function numbersInput(): array
    {
        return match ($this->mode) {
            BuildCardBatchPreview::MODE_LIST => ['mode' => $this->mode, 'numbers' => $this->numbers],
            BuildCardBatchPreview::MODE_CSV => ['mode' => $this->mode, 'numbers' => $this->csvContents()],
            default => ['mode' => BuildCardBatchPreview::MODE_RANGE, ...$this->only('prefix', 'start', 'end', 'digits')],
        };
    }

    protected function csvContents(): string
    {
        $this->validate(
            ['csv' => ['required', 'file', 'extensions:csv,txt', 'mimes:csv,txt', 'max:1024']],
            ['csv.required' => 'Selecione o arquivo CSV com os números.'],
            ['csv' => 'arquivo CSV'],
        );

        return (string) $this->csv->get();
    }

    protected function resetForm(): void
    {
        $this->reset('description', 'supplier', 'mode', 'prefix', 'start', 'end', 'digits', 'numbers', 'csv', 'preview');
        $this->received_at = today()->toDateString();
        $this->resetValidation();
    }
};
?>

<div>
    @if ($isOpen)
        <x-ui.modal title="Cadastrar lote" subtitle="Cartões já impressos pela gráfica entram no estoque como disponíveis." close="close">
            <x-ui.field label="Descrição" for="batch-description" error="description">
                <x-ui.input id="batch-description" wire:model="description" error="description" placeholder="Ex.: Lote gráfica out/2026" autocomplete="off" />
            </x-ui.field>

            <div class="grid grid-cols-2 gap-4">
                <x-ui.field label="Gráfica (opcional)" for="batch-supplier" error="supplier">
                    <x-ui.input id="batch-supplier" wire:model="supplier" error="supplier" autocomplete="off" />
                </x-ui.field>
                <x-ui.field label="Data de recebimento" for="batch-received-at" error="received_at">
                    <x-ui.input id="batch-received-at" type="date" wire:model="received_at" error="received_at" />
                </x-ui.field>
            </div>

            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold text-ink-800">Números dos cartões</span>
                <div class="grid grid-cols-3 gap-2">
                    <x-ui.choice :active="$mode === 'range'" wire:click="setMode('range')">Faixa</x-ui.choice>
                    <x-ui.choice :active="$mode === 'list'" wire:click="setMode('list')">Lista</x-ui.choice>
                    <x-ui.choice :active="$mode === 'csv'" wire:click="setMode('csv')">CSV</x-ui.choice>
                </div>
            </div>

            @if ($mode === 'range')
                <div class="grid grid-cols-2 gap-4">
                    <x-ui.field label="Prefixo (opcional)" for="batch-prefix" error="prefix">
                        <x-ui.input id="batch-prefix" wire:model.live.debounce.300ms="prefix" error="prefix" class="font-mono" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field label="Nº de dígitos" for="batch-digits" error="digits">
                        <x-ui.input id="batch-digits" type="number" min="1" wire:model.live.debounce.300ms="digits" error="digits" />
                    </x-ui.field>
                    <x-ui.field label="Número inicial" for="batch-start" error="start">
                        <x-ui.input id="batch-start" type="number" min="0" wire:model.live.debounce.300ms="start" error="start" />
                    </x-ui.field>
                    <x-ui.field label="Número final" for="batch-end" error="end">
                        <x-ui.input id="batch-end" type="number" min="0" wire:model.live.debounce.300ms="end" error="end" />
                    </x-ui.field>
                </div>
            @elseif ($mode === 'list')
                <x-ui.field label="Um número por linha" for="batch-numbers" hint="O número fica exatamente como impresso; só os espaços nas pontas são removidos.">
                    <x-ui.textarea id="batch-numbers" wire:model.live.debounce.500ms="numbers" error="numbers" rows="6" class="font-mono" />
                </x-ui.field>
            @else
                <x-ui.field label="Arquivo .csv ou .txt" for="batch-csv" error="csv" hint="Os números são lidos da primeira coluna; um cabeçalho é ignorado.">
                    <input id="batch-csv" type="file" accept=".csv,.txt,text/csv,text/plain" wire:model="csv" class="text-sm text-ink-700 file:mr-3 file:h-9 file:cursor-pointer file:rounded-full file:border file:border-ink-200 file:bg-white file:px-3.5 file:text-[13px] file:font-semibold file:text-ink-800">
                </x-ui.field>
            @endif

            @error('numbers')
                <x-ui.callout tone="danger">{{ $message }}</x-ui.callout>
            @enderror

            @if ($preview)
                <div class="flex flex-col gap-3 rounded-md bg-ink-50 p-3.5">
                    <div class="flex flex-wrap gap-2">
                        <x-ui.badge tone="green">{{ $preview['valid_count'] }} {{ $preview['valid_count'] === 1 ? 'válido' : 'válidos' }}</x-ui.badge>
                        <x-ui.badge :tone="count($preview['ignored']) > 0 ? 'amber' : 'gray'">{{ count($preview['ignored']) }} {{ count($preview['ignored']) === 1 ? 'ignorado' : 'ignorados' }}</x-ui.badge>
                    </div>

                    @if ($preview['valid_count'] > 0)
                        <div class="flex flex-col gap-1">
                            <span class="text-xs font-semibold text-ink-500">Válidos</span>
                            <div class="max-h-24 overflow-y-auto font-mono text-xs text-ink-700">
                                {{ implode(', ', $preview['valid']) }}@if ($preview['valid_count'] > count($preview['valid'])) e mais {{ $preview['valid_count'] - count($preview['valid']) }}@endif
                            </div>
                        </div>
                    @else
                        <x-ui.callout tone="warning">Nenhum número válido: o lote não pode ser cadastrado.</x-ui.callout>
                    @endif

                    @if ($preview['ignored'] !== [])
                        <div class="flex flex-col gap-1">
                            <span class="text-xs font-semibold text-ink-500">Ignorados</span>
                            <ul class="m-0 flex max-h-32 list-none flex-col gap-0.5 overflow-y-auto p-0 text-xs">
                                @foreach ($preview['ignored'] as $index => $ignored)
                                    <li wire:key="ignored-{{ $index }}"><span class="font-mono font-semibold text-ink-800">{{ $ignored['number'] }}</span> <span class="text-ink-500">— {{ $ignored['reason'] }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif

            <x-slot:actions>
                <x-ui.button variant="secondary" size="lg" wire:click="previewBatch">Pré-visualizar</x-ui.button>
                <x-ui.button size="lg" wire:click="confirm" :disabled="($preview['valid_count'] ?? 0) === 0">Confirmar cadastro</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    @endif
</div>
