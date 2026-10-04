@props([
    'label' => null,
    'for' => null,
    'error' => null,
    'hint' => null,
])

<div {{ $attributes->class('flex min-w-0 flex-col gap-1.5') }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="text-[13px] font-semibold text-ink-800">{{ $label }}</label>
    @endif

    {{ $slot }}

    @if ($error)
        @error($error)
            <span class="text-xs font-semibold text-danger-700" role="alert">{{ $message }}</span>
        @else
            @if ($hint)
                <span class="text-xs text-ink-500">{{ $hint }}</span>
            @endif
        @enderror
    @elseif ($hint)
        <span class="text-xs text-ink-500">{{ $hint }}</span>
    @endif
</div>
