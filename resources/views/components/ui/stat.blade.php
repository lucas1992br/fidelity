@props([
    'label',
    'value',
    'note' => null,
    'icon' => null,
    'tone' => 'blue',
    'alert' => false,
])

<x-ui.card class="flex flex-col gap-2.5">
    <div class="flex items-center gap-2.5 text-[13px] font-medium text-ink-500">
        @if ($icon)
            <span @class([
                'flex size-8 items-center justify-center rounded-md',
                match ($tone) {
                    'green' => 'bg-success-50 text-success-700',
                    'amber' => 'bg-warning-50 text-warning-700',
                    'red' => 'bg-danger-50 text-danger-700',
                    'blue' => 'bg-guardian-50 text-guardian-700',
                    default => 'bg-ink-100 text-ink-600',
                },
            ])>
                <x-ui.icon :name="$icon" class="size-4" />
            </span>
        @endif
        {{ $label }}
    </div>
    <div class="font-display text-[28px] leading-[1.1] font-bold tracking-tight text-ink-800">{{ $value }}</div>
    @if ($note)
        <div @class(['text-xs', 'font-semibold text-warning-700' => $alert, 'text-ink-500' => ! $alert])>{{ $note }}</div>
    @endif
</x-ui.card>
