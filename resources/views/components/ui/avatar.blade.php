@props([
    'initials',
    'size' => 'md',
    'tone' => 'blue',
])

<div {{ $attributes->class([
    'flex shrink-0 items-center justify-center rounded-full font-bold',
    match ($size) {
        'sm' => 'size-9 text-xs',
        'md' => 'size-10 text-[13px]',
        'xl' => 'size-16 font-display text-[22px]',
    },
    match ($tone) {
        'coral' => 'bg-embrace-50 text-embrace-700',
        'gray' => 'bg-ink-100 text-ink-600',
        default => 'bg-guardian-50 text-guardian-700',
    },
]) }}>{{ $initials }}</div>
