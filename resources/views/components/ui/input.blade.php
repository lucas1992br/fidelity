@props([
    'label' => null,
    'mask' => null,
    'error' => null,
])

@php
    $invalid = $error && $errors->has($error);

    $maskAttributes = match ($mask) {
        'cpf' => ['x-mask' => '999.999.999-99', 'inputmode' => 'numeric'],
        'phone' => ['x-mask:dynamic' => "\$input.replace(/\\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-99999'", 'inputmode' => 'tel'],
        'zip' => ['x-mask' => '99999-999', 'inputmode' => 'numeric'],
        'date' => ['x-mask' => '99/99/9999', 'inputmode' => 'numeric', 'placeholder' => 'dd/mm/aaaa'],
        'money' => ['x-mask:dynamic' => "\$money(\$input, ',', '.')", 'inputmode' => 'decimal'],
        default => [],
    };

    $attributes = $attributes->merge($maskAttributes)->merge($invalid ? ['aria-invalid' => 'true'] : []);

    $stateClasses = $invalid
        ? 'border-danger-500 focus:border-danger-500 focus:shadow-focus-danger'
        : 'border-ink-200 focus:border-guardian-500 focus:shadow-focus';
@endphp

@if ($label)
    <label class="flex min-w-0 flex-col gap-1.5 text-[13px] font-semibold text-ink-800">
        {{ $label }}
        <input {{ $attributes->class(['h-11 max-w-full min-w-0 rounded-md border bg-white px-3.5 text-[15px] font-normal text-ink-800 inset-shadow-soft outline-none', $stateClasses]) }}>
    </label>
@else
    <input {{ $attributes->class(['h-11 max-w-full min-w-0 rounded-md border bg-white px-4 text-sm text-ink-800 inset-shadow-soft outline-none', $stateClasses]) }}>
@endif
