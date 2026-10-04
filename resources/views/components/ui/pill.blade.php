@props(['active' => false])

<button type="button" {{ $attributes->class([
    'h-9 cursor-pointer rounded-full border px-3.5 text-[13px] font-semibold',
    'border-guardian-500 bg-guardian-500 text-white' => $active,
    'border-ink-200 bg-white text-ink-700 hover:bg-ink-50' => ! $active,
]) }}>{{ $slot }}</button>
