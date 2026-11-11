{{--
    Due date period filter of a Livewire page using App\Livewire\Concerns\FiltersDuePeriod:
    previous/next month, a whole month shortcut, a free interval of days and the
    "include earlier overdue" switch. `label` is the page's periodLabel().
--}}
@props(['label'])

<x-ui.card class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex flex-1 flex-col">
            <span class="text-xs font-semibold tracking-[0.08em] text-ink-500 uppercase">Vencimento</span>
            <span class="font-display text-xl font-bold text-ink-800">{{ $label }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <x-ui.button variant="secondary" size="sm" icon="chevron-left" wire:click="previousMonth" aria-label="Mês anterior" />
            <x-ui.input type="month" wire:model.live="month" aria-label="Mês" class="w-44" />
            <x-ui.button variant="secondary" size="sm" icon="chevron-right" wire:click="nextMonth" aria-label="Próximo mês" />
            <x-ui.button variant="link" size="sm" wire:click="currentMonth">Mês atual</x-ui.button>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2.5">
        <label class="flex items-center gap-2 text-[13px] font-semibold text-ink-700">
            De
            <x-ui.input type="date" wire:model.live="from" aria-label="Vencimento a partir de" class="w-44" />
        </label>
        <label class="flex items-center gap-2 text-[13px] font-semibold text-ink-700">
            até
            <x-ui.input type="date" wire:model.live="until" aria-label="Vencimento até" class="w-44" />
        </label>
        <label class="flex items-center gap-2 text-[13px] font-semibold text-ink-700">
            <input type="checkbox" wire:model.live="includeEarlierOverdue" class="size-4 accent-guardian-500">
            Incluir vencidas antes do período
        </label>
    </div>
</x-ui.card>
