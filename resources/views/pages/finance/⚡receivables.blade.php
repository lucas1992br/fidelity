<?php

use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Enums\InvoiceStatus;
use App\Livewire\Concerns\FiltersDuePeriod;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Queries\InvoicesQuery;
use App\Support\Billing\BillingRow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Accounts receivable (US-10.3): one list ordered by due date with the
 * pending incomes (settled through the settle modal, `finance.manage`), the
 * open or overdue invoices of individual holders and the open or overdue
 * company bills (in place of their employees), both read-only and linking
 * to the holder or company, plus the total of each origin. Only what is due
 * in the chosen period (the current month by default, any interval of days
 * or months, plus what is overdue before it when checked).
 * Requires `finance.view`.
 *
 * @phpstan-type Receivable array{key: string, due_date: \Carbon\CarbonInterface, description: string, detail: string, url: string|null, amount: string, status: InvoiceStatus|null, entry: FinancialEntry|null}
 */
new class extends Component
{
    use FiltersDuePeriod;

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
        $query = FinancialEntry::query()
            ->where('entry_type_id', EntryType::Income->id())
            ->where('entry_status_id', EntryStatus::Pending->id());

        $this->applyDuePeriod($query);

        return $query;
    }

    /**
     * Open or overdue invoices of individual holders in the period.
     *
     * @return Builder<Invoice>
     */
    protected function payableHolderInvoices(): Builder
    {
        $query = Invoice::query()->payable()->whereNull('company_id');

        $this->applyDuePeriod($query, 'invoices.due_date');

        return $query;
    }

    /**
     * Open or overdue company bills in the period, as billing rows.
     *
     * @return Collection<int, BillingRow>
     */
    protected function payableCompanyBills(): Collection
    {
        $start = $this->periodStart();
        $end = $this->periodEnd();
        $today = CarbonImmutable::today();

        return (new InvoicesQuery)->companyBills()->get()
            ->map(fn (object $record): BillingRow => BillingRow::fromRecord($record))
            ->filter(fn (BillingRow $bill): bool => $bill->status->isPayable() && (
                $bill->dueDate->betweenIncluded($start, $end)
                || ($this->includeEarlierOverdue && $bill->dueDate->lt($start) && $bill->dueDate->lt($today))
            ))
            ->values();
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
                'url' => null,
                'amount' => $entry->amount,
                'status' => null,
                'entry' => $entry,
            ]);

        $invoices = $this->payableHolderInvoices()
            ->with('customer:id,name,deleted_at')
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'key' => "invoice-{$invoice->id}",
                'due_date' => $invoice->due_date,
                'description' => 'Mensalidade '.$invoice->reference_month->format('m/Y'),
                'detail' => $invoice->customer->name,
                'url' => route('customers.show', [$invoice->customer, 'tab' => 'mensalidades']),
                'amount' => $invoice->amount,
                'status' => InvoiceStatus::fromId($invoice->invoice_status_id),
                'entry' => null,
            ]);

        $bills = $this->payableCompanyBills()->map(fn (BillingRow $bill): array => [
            'key' => "company-{$bill->companyId}-{$bill->referenceMonth->format('Y-m')}",
            'due_date' => $bill->dueDate,
            'description' => 'Fatura da empresa '.$bill->referenceMonth->format('m/Y'),
            'detail' => $bill->name,
            'url' => route('companies.show', ['company' => $bill->companyId, 'tab' => 'faturas']),
            'amount' => $bill->amount,
            'status' => $bill->status,
            'entry' => null,
        ]);

        return $incomes->concat($invoices)->concat($bills)
            ->sortBy([
                fn (array $a, array $b): int => $a['due_date'] <=> $b['due_date'],
                fn (array $a, array $b): int => ($a['entry'] === null) <=> ($b['entry'] === null),
                fn (array $a, array $b): int => $a['key'] <=> $b['key'],
            ])
            ->values();
    }

    public function render(): View
    {
        $receivables = $this->receivables();
        $sum = fn (Collection $rows): string => $rows->reduce(fn (string $total, array $row): string => bcadd($total, $row['amount'], 2), '0.00');

        return $this->view([
            'receivables' => $receivables,
            'totals' => [
                'entries' => $sum($receivables->whereNotNull('entry')),
                'invoices' => $sum($receivables->whereNull('entry')),
            ],
            'canManage' => auth()->user()->can('finance.manage'),
        ]);
    }
};
?>

<div class="flex flex-col gap-5">
    <x-finance.due-period-filter :label="$this->periodLabel()" />

    <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
        <x-ui.stat label="Lançamentos a receber" icon="arrow-down-left" tone="green" :value="\App\Support\Format::money($totals['entries'])" note="Repasses do cartão, adesões e receitas pendentes" />
        <x-ui.stat label="Mensalidades e faturas" icon="receipt" tone="blue" :value="\App\Support\Format::money($totals['invoices'])" note="Em aberto e em atraso" />
        <x-ui.stat label="Total a receber" icon="chart-column" tone="gray" :value="\App\Support\Format::money(bcadd($totals['entries'], $totals['invoices'], 2))" />
    </div>

    <x-ui.card class="flex flex-col gap-3">
        <x-ui.card-title>A receber</x-ui.card-title>

        @if ($receivables->isEmpty())
            <x-ui.empty-state icon="arrow-down-left" title="Nada a receber" description="Não há receitas pendentes nem mensalidades em aberto no período." />
        @else
            <x-ui.table :columns="['Vencimento', 'Descrição', 'Origem', 'Categoria/titular/empresa', '>Valor', 'Status', '>Ações']" min-width="min-w-[880px]">
                @foreach ($receivables as $row)
                    <tr wire:key="{{ $row['key'] }}">
                        <td class="whitespace-nowrap">{{ \App\Support\Format::date($row['due_date']) }}</td>
                        <td class="font-semibold text-ink-800">{{ $row['description'] }}</td>
                        <td>{{ $row['entry'] ? 'Lançamento' : (str_starts_with($row['key'], 'company-') ? 'Fatura da empresa' : 'Mensalidade') }}</td>
                        <td>
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" wire:navigate class="text-guardian-600">{{ $row['detail'] }}</a>
                            @else
                                {{ $row['detail'] }}
                            @endif
                        </td>
                        <td class="text-right font-semibold whitespace-nowrap text-success-700">{{ \App\Support\Format::money($row['amount']) }}</td>
                        <td>
                            @if ($row['status'])
                                <x-ui.badge :tone="$row['status']->color()">{{ $row['status']->label() }}</x-ui.badge>
                            @else
                                <x-ui.badge :tone="EntryStatus::Pending->color()">{{ EntryStatus::Pending->label() }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if (! $row['entry'])
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
