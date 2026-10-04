@props([
    'tone' => 'gray',
    'dot' => false,
    'size' => 'md',
])

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full font-semibold whitespace-nowrap',
    $size === 'lg' ? 'h-[26px] px-3 text-[13px]' : 'h-6 px-2.5 text-xs',
    match ($tone) {
        'green' => 'bg-success-50 text-success-700',
        'amber' => 'bg-warning-50 text-warning-700',
        'red' => 'bg-danger-50 text-danger-700',
        'blue' => 'bg-guardian-50 text-guardian-700',
        default => 'bg-ink-100 text-ink-600',
    },
]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full bg-current"></span>
    @endif
    {{ $slot }}
</span>
