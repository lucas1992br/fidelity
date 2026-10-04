<?php

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardBatch;
use App\Settings\ClinicSettings;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Card stock (US-2.3): summary with the low stock alert, the cards table with
 * filters kept in the URL and the batches with their usage. Viewing requires
 * `cards.stock.view`; registering batches and discarding cards happen in the
 * modals, which require `cards.stock.manage`.
 */
new class extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'cards';

    #[Url]
    public string $status = '';

    #[Url]
    public string $batch = '';

    #[Url]
    public string $number = '';

    public function mount(): void
    {
        $this->authorize('cards.stock.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'batch', 'number'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Re-render after a batch is registered or a card is discarded.
     */
    #[On('card-stock-updated')]
    public function refreshStock(): void
    {
        //
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'batch', 'number');
        $this->resetPage();
    }

    /**
     * @return array<string, int>
     */
    protected function countsByStatus(): array
    {
        $counts = Card::query()
            ->selectRaw('card_status_id, count(*) as total')
            ->groupBy('card_status_id')
            ->pluck('total', 'card_status_id');

        return collect(CardStatus::cases())
            ->mapWithKeys(fn (CardStatus $status): array => [$status->value => (int) ($counts[$status->id()] ?? 0)])
            ->all();
    }

    /**
     * Date shown in the cards table, according to the card status.
     */
    protected function cardDate(Card $card): string
    {
        $status = CardStatus::fromId($card->card_status_id);

        return match ($status) {
            CardStatus::InStock => 'Recebido '.Format::date($card->batch->received_at ?? $card->created_at),
            CardStatus::Active, CardStatus::Suspended, CardStatus::Cancelled => 'Vinculado '.Format::date($card->assigned_at),
            CardStatus::Lost, CardStatus::Discarded => $status->label().' '.Format::date($card->status_changed_at),
        };
    }

    public function render(ClinicSettings $settings): View
    {
        $counts = $this->countsByStatus();
        $statusFilter = CardStatus::tryFrom($this->status);

        $cards = Card::query()
            ->with(['batch:id,description,received_at', 'status:id,name,color', 'customer:id,name'])
            ->when($statusFilter, fn (Builder $query) => $query->withStatus($statusFilter))
            ->when($this->batch !== '', fn (Builder $query) => $query->where('card_batch_id', (int) $this->batch))
            ->when(trim($this->number) !== '', fn (Builder $query) => $query->where('number', 'ilike', '%'.addcslashes(trim($this->number), '%_\\').'%'))
            ->orderByDesc('created_at')
            ->orderBy('number')
            ->paginate(20);

        $linkedStatusIds = array_map(fn (CardStatus $status): int => $status->id(), [...CardStatus::current(), CardStatus::Cancelled, CardStatus::Lost]);

        $batches = CardBatch::query()
            ->withCount([
                'cards',
                'cards as linked_count' => fn (Builder $query) => $query->whereIn('card_status_id', $linkedStatusIds),
                'cards as available_count' => fn (Builder $query) => $query->withStatus(CardStatus::InStock),
                'cards as discarded_count' => fn (Builder $query) => $query->withStatus(CardStatus::Discarded),
            ])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();

        return $this->view([
            'counts' => $counts,
            'minCardStock' => $settings->min_card_stock,
            'isLowStock' => $counts[CardStatus::InStock->value] < $settings->min_card_stock,
            'cards' => $cards,
            'cardDates' => $cards->getCollection()->mapWithKeys(fn (Card $card): array => [$card->id => $this->cardDate($card)])->all(),
            'batches' => $batches,
            'statusOptions' => collect(CardStatus::cases())->mapWithKeys(fn (CardStatus $status): array => [$status->value => $status->label()])->all(),
            'batchOptions' => $batches->pluck('description', 'id')->all(),
        ]);
    }
};
?>

<div class="flex flex-col gap-5">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
        <x-ui.stat
            label="Em estoque"
            :value="$counts['in_stock']"
            icon="package"
            :tone="$isLowStock ? 'amber' : 'blue'"
            :alert="$isLowStock"
            :note="$isLowStock ? 'Abaixo do mínimo de '.$minCardStock.'. Peça um novo lote.' : 'Disponíveis para venda'"
        />
        <x-ui.stat
            label="Vinculados"
            :value="$counts['active'] + $counts['suspended']"
            icon="credit-card"
            tone="green"
            :note="$counts['active'].' '.($counts['active'] === 1 ? 'ativo' : 'ativos').' · '.$counts['suspended'].' '.($counts['suspended'] === 1 ? 'suspenso' : 'suspensos')"
        />
        <x-ui.stat
            label="Extraviados / inutilizados"
            :value="$counts['lost'] + $counts['discarded']"
            icon="triangle-alert"
            tone="gray"
            note="Não voltam ao estoque"
        />
    </div>

    <x-ui.card class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.tabs :tabs="['cards' => 'Cartões', 'batches' => 'Lotes']" :active="$tab" model="tab" class="flex-1" />
            @can('cards.stock.manage')
                <x-ui.button icon="package" wire:click="$dispatch('open-register-card-batch')">Cadastrar lote</x-ui.button>
            @endcan
        </div>

        @if ($tab === 'batches')
            @forelse ($batches as $cardBatch)
                <div class="grid grid-cols-1 items-center gap-4 border-t border-ink-100 px-1 py-4 first:border-t-0 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(160px,1.2fr)]" wire:key="batch-{{ $cardBatch->id }}">
                    <div class="flex flex-col">
                        <span class="text-sm font-semibold text-ink-800">{{ $cardBatch->description }}</span>
                        <span class="text-xs text-ink-500">{{ $cardBatch->supplier ? $cardBatch->supplier.' · ' : '' }}recebido {{ \App\Support\Format::date($cardBatch->received_at) }}</span>
                    </div>
                    <span class="font-mono text-[13px] text-ink-500">
                        {{ $cardBatch->first_number === $cardBatch->last_number ? $cardBatch->first_number : $cardBatch->first_number.' – '.$cardBatch->last_number }}
                    </span>
                    <div class="flex flex-col gap-1.5">
                        <x-ui.progress :value="$cardBatch->linked_count" :max="$cardBatch->cards_count" label="Uso do lote {{ $cardBatch->description }}" />
                        <span class="text-xs text-ink-500">{{ implode(' · ', [
                            $cardBatch->linked_count.' '.($cardBatch->linked_count === 1 ? 'vinculado' : 'vinculados'),
                            $cardBatch->available_count.' '.($cardBatch->available_count === 1 ? 'disponível' : 'disponíveis'),
                            $cardBatch->discarded_count.' '.($cardBatch->discarded_count === 1 ? 'inutilizado' : 'inutilizados'),
                        ]) }}</span>
                    </div>
                </div>
            @empty
                <x-ui.empty-state title="Nenhum lote cadastrado" description="Cadastre o lote recebido da gráfica para os cartões entrarem no estoque." />
            @endforelse
        @else
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,200px),1fr))] gap-3">
                <x-ui.input wire:model.live.debounce.300ms="number" placeholder="Buscar número" aria-label="Número do cartão" class="font-mono" />
                <x-ui.select wire:model.live="status" :options="$statusOptions" placeholder="Todos os status" aria-label="Status" />
                <x-ui.select wire:model.live="batch" :options="$batchOptions" placeholder="Todos os lotes" aria-label="Lote" />
            </div>

            @if ($cards->isEmpty())
                <x-ui.empty-state title="Nenhum cartão encontrado" description="Ajuste os filtros ou cadastre um novo lote.">
                    @if ($status !== '' || $batch !== '' || $number !== '')
                        <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
                    @endif
                </x-ui.empty-state>
            @else
                <x-ui.table :columns="['Número', 'Lote', 'Status', 'Cliente', 'Data', '>']">
                    @foreach ($cards as $card)
                        <tr wire:key="card-{{ $card->id }}">
                            <td class="font-mono text-[13px] font-semibold text-ink-800">{{ $card->number }}</td>
                            <td class="text-ink-500">{{ $card->batch?->description ?? '—' }}</td>
                            <td><x-ui.badge :tone="$card->status->color">{{ $card->status->name }}</x-ui.badge></td>
                            <td>{{ $card->customer?->name ?? '—' }}</td>
                            <td class="text-ink-500">{{ $cardDates[$card->id] }}</td>
                            <td class="text-right">
                                @if ($card->hasStatus(\App\Enums\CardStatus::InStock))
                                    @can('cards.stock.manage')
                                        <x-ui.button variant="ghost" size="sm" wire:click="$dispatch('open-discard-card', { id: {{ $card->id }} })">Inutilizar</x-ui.button>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                {{ $cards->links() }}
            @endif
        @endif
    </x-ui.card>

    @can('cards.stock.manage')
        <livewire:cards.register-batch-modal />
        <livewire:cards.discard-card-modal />
    @endcan
</div>
