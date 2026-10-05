<?php

use App\Models\Plan;
use App\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports (US-11.2): pick one of the six reports, the period and its own
 * filters, then download it as PDF or Excel. Spreadsheets above the row
 * limit are queued into the exports disk and listed as "Processando…" until
 * the light polling finds the file. Requires `reports.view` on mount and on
 * every download.
 *
 * @phpstan-type QueuedExport array{path: string, title: string, ready: bool}
 */
new class extends Component
{
    /** @var array<string, string> */
    protected const array Icons = [
        'clientes' => 'users',
        'mensalidades' => 'receipt',
        'inadimplencia' => 'trending-down',
        'pagamentos' => 'credit-card',
        'lancamentos' => 'tags',
        'fluxo-de-caixa' => 'chart-column',
    ];

    #[Url]
    public string $report = 'clientes';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    public string $plan = '';

    public string $status = '';

    /** @var list<QueuedExport> */
    #[Locked]
    public array $queuedExports = [];

    public function mount(): void
    {
        $this->authorize('reports.view');

        if (! array_key_exists($this->report, Report::catalog())) {
            $this->report = array_key_first(Report::catalog());
        }

        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->until = $this->until ?: today()->endOfMonth()->toDateString();
    }

    public function selectReport(string $key): void
    {
        if (array_key_exists($key, Report::catalog())) {
            $this->report = $key;
            $this->reset('status');
            $this->resetValidation();
        }
    }

    public function downloadPdf(): StreamedResponse
    {
        $this->authorize('reports.view');

        return $this->buildReport()->downloadPdf();
    }

    /**
     * Download the spreadsheet, or queue it when it has too many rows.
     */
    public function downloadExcel(): ?BinaryFileResponse
    {
        $this->authorize('reports.view');

        $report = $this->buildReport();

        if (! $report->shouldQueueExcel()) {
            return $report->downloadExcel();
        }

        array_unshift($this->queuedExports, ['path' => $report->queueExcel(), 'title' => $report::title(), 'ready' => false]);

        $this->dispatch('notify', title: 'Relatório em processamento', description: 'O arquivo Excel é grande e está sendo gerado. Ele aparece aqui para download quando ficar pronto.');

        return null;
    }

    /**
     * Polling: flag the queued files that were written and announce them.
     */
    public function checkQueuedExports(): void
    {
        $this->authorize('reports.view');

        foreach ($this->queuedExports as $index => $export) {
            if (! $export['ready'] && $this->disk()->exists($export['path'])) {
                $this->queuedExports[$index]['ready'] = true;
                $this->dispatch('notify', title: 'Relatório pronto', description: "{$export['title']} · Excel disponível para download.");
            }
        }
    }

    public function downloadQueued(int $index): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('reports.view');

        $path = $this->queuedExports[$index]['path'] ?? null;

        abort_unless($path !== null && $this->disk()->exists($path), 404);

        return $this->disk()->download($path);
    }

    /**
     * The selected report with the validated filters it understands.
     */
    protected function buildReport(): Report
    {
        $class = Report::catalog()[$this->report] ?? abort(404);
        $filters = $class::filters();

        $this->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'plan' => ['nullable', Rule::exists(Plan::class, 'id')],
            'status' => ['nullable', Rule::in(array_keys($class::statusOptions()))],
        ], attributes: ['from' => 'início', 'until' => 'fim', 'plan' => 'plano', 'status' => 'status']);

        return new $class(
            CarbonImmutable::parse($this->from),
            CarbonImmutable::parse($this->until),
            in_array(Report::FilterPlan, $filters, true) && $this->plan !== '' ? (int) $this->plan : null,
            in_array(Report::FilterStatus, $filters, true) && $this->status !== '' ? $this->status : null,
        );
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(config('reports.exports_disk'));
    }

    public function render(): View
    {
        $selected = Report::catalog()[$this->report];

        return $this->view([
            'catalog' => Report::catalog(),
            'icons' => self::Icons,
            'selected' => $selected,
            'filters' => $selected::filters(),
            'planOptions' => Plan::query()->orderBy('name')->pluck('name', 'id')->all(),
            'statusOptions' => $selected::statusOptions(),
            'isProcessing' => collect($this->queuedExports)->contains('ready', false),
        ]);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(260px,1fr))] gap-4">
        @foreach ($catalog as $key => $reportClass)
            <button
                type="button"
                wire:key="report-{{ $key }}"
                wire:click="selectReport('{{ $key }}')"
                aria-pressed="{{ $report === $key ? 'true' : 'false' }}"
                @class([
                    'flex cursor-pointer flex-col gap-2.5 rounded-lg bg-white p-5 text-left shadow-sm outline-none transition-shadow duration-200 ease-standard focus-visible:shadow-focus',
                    'ring-2 ring-guardian-500' => $report === $key,
                    'hover:shadow-md' => $report !== $key,
                ])
            >
                <span class="flex items-center gap-2.5">
                    <span @class([
                        'flex size-8 items-center justify-center rounded-md',
                        'bg-guardian-500 text-white' => $report === $key,
                        'bg-guardian-50 text-guardian-700' => $report !== $key,
                    ])>
                        <x-ui.icon :name="$icons[$key]" class="size-4" />
                    </span>
                    <span class="font-display text-[17px] font-bold text-ink-800">{{ $reportClass::title() }}</span>
                </span>
                <span class="text-sm text-ink-500">{{ $reportClass::description() }}</span>
            </button>
        @endforeach
    </div>

    <x-ui.card class="flex flex-col gap-4">
        <x-ui.card-title>{{ $selected::title() }}</x-ui.card-title>

        <div class="flex flex-wrap items-start gap-4">
            @if (in_array(\App\Reports\Report::FilterPeriod, $filters, true))
                <x-ui.field label="Início" for="report-from" error="from">
                    <x-ui.input id="report-from" type="date" wire:model="from" error="from" class="w-44" />
                </x-ui.field>
                <x-ui.field label="Fim" for="report-until" error="until">
                    <x-ui.input id="report-until" type="date" wire:model="until" error="until" class="w-44" />
                </x-ui.field>
            @endif

            @if (in_array(\App\Reports\Report::FilterPlan, $filters, true))
                <x-ui.field label="Plano" for="report-plan" error="plan" class="w-52">
                    <x-ui.select id="report-plan" wire:model="plan" :options="$planOptions" placeholder="Todos os planos" error="plan" />
                </x-ui.field>
            @endif

            @if (in_array(\App\Reports\Report::FilterStatus, $filters, true))
                <x-ui.field label="Status" for="report-status" error="status" class="w-52">
                    <x-ui.select id="report-status" wire:model="status" :options="$statusOptions" placeholder="Todos" error="status" />
                </x-ui.field>
            @endif

            @if (! in_array(\App\Reports\Report::FilterPeriod, $filters, true))
                <p class="m-0 self-center text-sm text-ink-500">Posição atual: este relatório não depende do período.</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2.5 border-t border-ink-100 pt-4">
            <span class="flex-1 text-[13px] text-ink-500">PDF e Excel trazem as mesmas linhas. Planilhas grandes são geradas em segundo plano.</span>
            <x-ui.button variant="secondary" icon="file-text" wire:click="downloadPdf" wire:loading.attr="disabled" wire:target="downloadPdf">PDF</x-ui.button>
            <x-ui.button variant="secondary" icon="chart-column" wire:click="downloadExcel" wire:loading.attr="disabled" wire:target="downloadExcel">Excel</x-ui.button>
        </div>
    </x-ui.card>

    @if ($queuedExports !== [])
        <div @if ($isProcessing) wire:poll.5s="checkQueuedExports" @endif>
            <x-ui.card class="flex flex-col gap-2">
                <x-ui.card-title class="mb-1">Arquivos gerados em segundo plano</x-ui.card-title>
                @foreach ($queuedExports as $index => $export)
                    <div wire:key="queued-{{ $export['path'] }}" class="flex items-center gap-3 border-t border-ink-100 py-2.5">
                        <x-ui.icon :name="$export['ready'] ? 'circle-check' : 'clock'" @class(['size-4', 'text-success-600' => $export['ready'], 'text-ink-400' => ! $export['ready']]) />
                        <span class="flex-1 text-sm font-semibold text-ink-800">{{ $export['title'] }} · Excel</span>
                        @if ($export['ready'])
                            <x-ui.button variant="link" size="sm" wire:click="downloadQueued({{ $index }})">Baixar</x-ui.button>
                        @else
                            <span class="text-[13px] text-ink-500">Processando…</span>
                        @endif
                    </div>
                @endforeach
            </x-ui.card>
        </div>
    @endif
</div>
