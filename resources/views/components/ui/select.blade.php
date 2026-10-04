@props([
    'options' => [],
    'placeholder' => null,
    'error' => null,
])

@php($invalid = $error && $errors->has($error))

<div class="relative flex min-w-0">
    <select {{ $attributes->merge($invalid ? ['aria-invalid' => 'true'] : [])->class([
        'h-11 w-full min-w-0 cursor-pointer appearance-none rounded-md border bg-white pr-10 pl-3.5 text-[15px] text-ink-800 inset-shadow-soft outline-none',
        'border-danger-500 focus:border-danger-500 focus:shadow-focus-danger' => $invalid,
        'border-ink-200 focus:border-guardian-500 focus:shadow-focus' => ! $invalid,
    ]) }}>
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $optionLabel)
            <option value="{{ $value }}">{{ $optionLabel }}</option>
        @endforeach
        {{ $slot }}
    </select>
    <x-ui.icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3.5 size-4 -translate-y-1/2 text-ink-500" />
</div>
