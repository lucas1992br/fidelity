@props([
    'rows' => 3,
    'error' => null,
])

@php($invalid = $error && $errors->has($error))

<textarea rows="{{ $rows }}" {{ $attributes->merge($invalid ? ['aria-invalid' => 'true'] : [])->class([
    'w-full min-w-0 resize-y rounded-md border bg-white px-3.5 py-2.5 text-[15px] text-ink-800 inset-shadow-soft outline-none',
    'border-danger-500 focus:border-danger-500 focus:shadow-focus-danger' => $invalid,
    'border-ink-200 focus:border-guardian-500 focus:shadow-focus' => ! $invalid,
]) }}>{{ $slot }}</textarea>
