<?php

use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\InvoiceStatus;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Accounts receivable (US-10.3): one list ordered by due date with the
 * pending manual incomes (settled through the settle modal, `finance.manage`)
 * and the open or overdue invoices (read-only, linking to the holder's
 * invoices), plus the total of each origin. Requires `finance.view`.
 *
 * @phpstan-type Receivable array{key: string, due_date: \Carbon\CarbonInterface, description: string, detail: string, amount: string, entry: FinancialEntry|null, invoice: Invoice|null}
 */
new class extends Component
{
    public function mount(): void
    {
        $this->authorize('finance.view');
    }

    /**
     * Render again after an income is settled in the modal.
     */
    #[On('financial-entry-saved')]
    public function refreshReceivables(): void {}

    /**
     * @return Builder<FinancialEntry>
     */
    protected function pendingIncomes(): Builder
    {
        return FinancialEntry::query()
            ->where('entry_type_id', EntryType::Income->id())
            ->where('entry_status_id', EntryStatus::Pending->id());
    }

    /**
     * Incomes and invoices merged by due date (incomes first on the same day).
     *
     * @return Collection<int, Receivable>
     */
    protected function receivables(): Collection
    {
        $incomes = $this->pendingIncomes()
            ->with('category:id,name')
            ->get()
            ->map(fn (FinancialEntry $entry): array => [
                'key' => "entry-{$entry->id}",
                'due_date' => $entry->due_date,
                'description' => $entry->description,
                'detail' => collect([$entry->category->name, $entry->supplier_or_payer])->filter()->implode(' · '),
                'amount' => $entry->amount,
                'entry' => $entry,
                'invoice' => null,
            ]);

        $invoices = Invoice::query()
            ->payable()
            ->with('customer:id,name,deleted_at')
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'key' => "invoice-{$invoice->id}",
                'due_date' => $invoice->due_date,
                'description' => 'Mensalidade '.$invoice->reference_month->format('m/Y'),
                'detail' => $invoice->customer->name,
                'amount' => $invoice->amount,
                'entry' => null,
                'invoice' => $invoice,
            ]);

        return $incomes->concat($invoices)
            ->sortBy([
                fn (array $a, array $b): int => $a['due_date'] <=> $b['due_date'],
                fn (array $a, array $b): int => ($a['entry'] === null) <=> ($b['entry'] === null),
                fn (array $a, array $b): int => $a['key'] <=> $b['key'],
            ])
            ->values();
    }

    public function render(): View
    {
        return $this->view([
            'receivables' => $this->receivables(),
            'totals' => [
                'entries' => (string) $this->pendingIncomes()->toBase()->selectRaw('coalesce(sum(amount), 0)::numeric(12, 2) as total')->value('total'),
                'invoices' => (string) Invoice::query()->payable()->toBase()->selectRaw('coalesce(sum(amount), 0)::numeric(12, 2) as total')->value('total'),
            ],
            'canManage' => auth()->user()->can('finance.manage'),
        ]);
    }
};
?>

<div class="flex flex-col gap-5">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
        <x-ui.stat label="Receitas manuais" icon="arrow-down-left" tone="green" :value="\App\Support\Format::money($totals['entries'])" note="Lançamentos pendentes" />
        <x-ui.stat label="Mensalidades" icon="receipt" tone="blue" :value="\App\Support\Format::money($totals['invoices'])" note="Em aberto e em atraso" />
        <x-ui.stat label="Total a receber" icon="chart-column" tone="gray" :value="\App\Support\Format::money(bcadd($totals['entries'], $totals['invoices'], 2))" />
    </div>

    <x-ui.card class="flex flex-col gap-3">
        <x-ui.card-title>A receber</x-ui.card-title>

        @if ($receivables->isEmpty())
            <x-ui.empty-state icon="arrow-down-left" title="Nada a receber" description="Não há receitas pendentes nem mensalidades em aberto." />
        @else
            <x-ui.table :columns="['Vencimento', 'Descrição', 'Origem', 'Categoria/titular', '>Valor', 'Status', '>Ações']" min-width="min-w-[880px]">
                @foreach ($receivables as $row)
                    <tr wire:key="{{ $row['key'] }}">
                        <td class="whitespace-nowrap">{{ \App\Support\Format::date($row['due_date']) }}</td>
                        <td class="font-semibold text-ink-800">{{ $row['description'] }}</td>
                        <td>{{ $row['invoice'] ? 'Mensalidade' : 'Receita manual' }}</td>
                        <td>
                            @if ($row['invoice'])
                                <a href="{{ route('customers.show', [$row['invoice']->customer, 'tab' => 'mensalidades']) }}" wire:navigate class="text-guardian-600">{{ $row['detail'] }}</a>
                            @else
                                {{ $row['detail'] }}
                            @endif
                        </td>
                        <td class="text-right font-semibold whitespace-nowrap text-success-700">{{ \App\Support\Format::money($row['amount']) }}</td>
                        <td>
                            @if ($row['invoice'])
                                @php($invoiceStatus = InvoiceStatus::fromId($row['invoice']->invoice_status_id))
                                <x-ui.badge :tone="$invoiceStatus->color()">{{ $invoiceStatus->label() }}</x-ui.badge>
                            @else
                                <x-ui.badge :tone="EntryStatus::Pending->color()">{{ EntryStatus::Pending->label() }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if ($row['invoice'])
                                <span class="text-xs text-ink-400">Somente leitura</span>
                            @elseif ($canManage)
                                <x-ui.button variant="soft" size="sm" x-on:click="$dispatch('open-settle-entry', { entry: {{ $row['entry']->id }} })">Dar baixa</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            <div class="text-[13px] text-ink-500">{{ $receivables->count() }} {{ $receivables->count() === 1 ? 'conta' : 'contas' }}</div>
        @endif
    </x-ui.card>

    @if ($canManage)
        <livewire:finance.settle-modal />
    @endif
</div>
