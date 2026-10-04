@props([
    'tone' => 'info',
    'icon' => null,
])

@php
    $icon ??= match ($tone) {
        'success' => 'circle-check',
        'warning' => 'triangle-alert',
        'danger' => 'circle-alert',
        default => 'info',
    };
@endphp

<div {{ $attributes->class([
    'flex items-start gap-2.5 rounded-md px-3.5 py-3 text-[13px] font-semibold',
    match ($tone) {
        'success' => 'bg-success-50 text-success-700',
        'warning' => 'bg-warning-50 text-warning-700',
        'danger' => 'bg-danger-50 text-danger-700',
        default => 'bg-guardian-50 text-guardian-700',
    },
]) }} @if ($tone === 'danger') role="alert" @endif>
    <x-ui.icon :name="$icon" class="mt-px size-4" />
    <div class="min-w-0 flex-1">{{ $slot }}</div>
</div>
