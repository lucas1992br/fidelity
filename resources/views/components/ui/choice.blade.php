@props(['active' => false])

<button type="button" {{ $attributes->class([
    'h-11 cursor-pointer rounded-md border text-sm font-semibold',
    'border-guardian-500 bg-guardian-50 text-guardian-700' => $active,
    'border-ink-200 bg-white text-ink-700 hover:bg-ink-50' => ! $active,
]) }}>{{ $slot }}</button>
