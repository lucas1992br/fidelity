@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'icon' => null,
])

@php
    $classes = [
        'inline-flex items-center justify-center gap-2 rounded-full font-semibold whitespace-nowrap no-underline transition-colors duration-200 ease-standard cursor-pointer hover:no-underline disabled:cursor-not-allowed disabled:opacity-40',
        match ($size) {
            'sm' => 'h-9 px-3.5 text-[13px]',
            'md' => 'h-10 px-4 text-[13px]',
            'lg' => 'h-11 px-5 text-sm',
        },
        match ($variant) {
            'primary' => 'bg-guardian-500 text-white hover:bg-guardian-600 hover:text-white',
            'secondary' => 'border border-ink-200 bg-white text-ink-800 hover:bg-ink-50 hover:text-ink-800',
            'soft' => 'bg-guardian-50 text-guardian-700 hover:bg-guardian-100 hover:text-guardian-700',
            'ghost' => 'bg-transparent text-ink-500 hover:bg-ink-50 hover:text-ink-500',
            'link' => 'bg-transparent px-0 text-guardian-600 hover:text-guardian-700 hover:underline',
        },
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon) <x-ui.icon :name="$icon" class="size-4" /> @endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>
        @if ($icon) <x-ui.icon :name="$icon" class="size-4" /> @endif
        {{ $slot }}
    </button>
@endif
