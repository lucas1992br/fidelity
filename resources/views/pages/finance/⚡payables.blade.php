<?php

use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Livewire\Concerns\FiltersDuePeriod;
use App\Models\FinancialEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Accounts payable (US-10.2, US-10.4): pending expenses due in the chosen
 * period (the current month by default, any interval of days or months,
 * plus those overdue before it when checked), grouped by due date into
 * overdue, due in the next 7 days and future, each with its total.
 * Requires `finance.view`; "Dar baixa" opens the settle modal
 * (`finance.manage`).
 *
 * @phpstan-type PayableGroup array{label: string, icon: string, tone: string, entries: Collection<int, FinancialEntry>, total: string}
 */
new class extends Component
{
    use FiltersDuePeriod;

    public const string Overdue = 'overdue';

    public const string DueSoon = 'due_soon';

    public const string Future = 'future';

    public function mount(): void
    {
        $this->authorize('finance.view');
    }

    /**
     * Render again after an entry is settled in the modal.
     */
    #[On('financial-entry-saved')]
    public function refreshPayables(): void {}

    /**
     * @return Builder<FinancialEntry>
     */
    protected function pendingExpenses(): Builder
    {
        $query = FinancialEntry::query()
            ->where('entry_type_id', EntryType::Expense->id())
            ->where('entry_status_id', EntryStatus::Pending->id());

        $this->applyDuePeriod($query);

        return $query;
    }

    /**
     * The three groups in display order, with their entries and totals summed in the database.
     *
     * @return array<string, PayableGroup>
     */
    protected function groups(): array
    {
        $today = today()->toDateString();
        $dueSoonLimit = today()->addDays(7)->toDateString();

        $totals = $this->pendingExpenses()->toBase()->selectRaw(
            'coalesce(sum(amount) filter (where due_date < ?), 0)::numeric(12, 2) as overdue,
             coalesce(sum(amount) filter (where due_date between ? and ?), 0)::numeric(12, 2) as due_soon,
             coalesce(sum(amount) filter (where due_date > ?), 0)::numeric(12, 2) as future',
            [$today, $today, $dueSoonLimit, $dueSoonLimit],
        )->first();

        $entries = $this->pendingExpenses()
            ->with('category:id,name')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (FinancialEntry $entry): string => match (true) {
                $entry->due_date->toDateString() < $today => self::Overdue,
                $entry->due_date->toDateString() <= $dueSoonLimit => self::DueSoon,
                default => self::Future,
            });

        $definitions = [
            self::Overdue => ['label' => 'Vencidas', 'icon' => 'circle-alert', 'tone' => 'red'],
            self::DueSoon => ['label' => 'Vencendo em 7 dias', 'icon' => 'clock', 'tone' => 'amber'],
            self::Future => ['label' => 'Futuras', 'icon' => 'calendar', 'tone' => 'blue'],
        ];

        return collect($definitions)
            ->map(fn (array $definition, string $key): array => [
                ...$definition,
                'entries' => $entries->get($key, collect()),
                'total' => (string) $totals->{$key},
            ])
            ->all();
    }

    public function render(): View
    {
        return $this->view([
            'groups' => $this->groups(),
            'canManage' => auth()->user()->can('finance.manage'),
        ]);
    }
};
?>

<div class="flex flex-col gap-5">
    <x-finance.due-period-filter :label="$this->periodLabel()" />

    <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
        @foreach ($groups as $key => $group)
            <x-ui.stat
                wire:key="summary-{{ $key }}"
                :label="$group['label']"
                :icon="$group['icon']"
                :tone="$group['tone']"
                :value="\App\Support\Format::money($group['total'])"
                :note="$group['entries']->count().' '.($group['entries']->count() === 1 ? 'conta' : 'contas')"
            />
        @endforeach
    </div>

    @foreach ($groups as $key => $group)
        <x-ui.card wire:key="group-{{ $key }}" class="flex flex-col gap-3" data-group="{{ $key }}">
            <div class="flex flex-wrap items-center gap-4">
                <x-ui.card-title class="flex-1">{{ $group['label'] }}</x-ui.card-title>
                <span class="text-sm font-semibold text-ink-700">Total {{ \App\Support\Format::money($group['total']) }}</span>
            </div>

            @if ($group['entries']->isEmpty())
                <p class="m-0 text-sm text-ink-500">Nenhuma conta neste grupo.</p>
            @else
                <x-ui.table :columns="['Vencimento', 'Descrição', 'Categoria', 'Fornecedor', '>Valor', '>Ações']" min-width="min-w-[760px]">
                    @foreach ($group['entries'] as $entry)
                        <tr wire:key="payable-{{ $entry->id }}">
                            <td @class(['whitespace-nowrap', 'font-semibold text-danger-700' => $key === 'overdue'])>{{ \App\Support\Format::date($entry->due_date) }}</td>
                            <td class="font-semibold text-ink-800">{{ $entry->description }}</td>
                            <td>{{ $entry->category->name }}</td>
                            <td>{{ $entry->supplier_or_payer ?? '—' }}</td>
                            <td class="text-right font-semibold whitespace-nowrap text-danger-700">{{ \App\Support\Format::money($entry->amount) }}</td>
                            <td class="text-right whitespace-nowrap">
                                @if ($canManage)
                                    <x-ui.button variant="soft" size="sm" x-on:click="$dispatch('open-settle-entry', { entry: {{ $entry->id }} })">Dar baixa</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @endforeach

    @if ($canManage)
        <livewire:finance.settle-modal />
    @endif
</div>
