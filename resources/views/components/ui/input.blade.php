@props(['label' => null])

@if ($label)
    <label class="flex flex-col gap-1.5 text-[13px] font-semibold text-ink-800">
        {{ $label }}
        <input {{ $attributes->class('h-11 rounded-md border border-ink-200 bg-white px-3.5 text-[15px] font-normal text-ink-800 inset-shadow-soft outline-none focus:border-guardian-500 focus:shadow-focus') }}>
    </label>
@else
    <input {{ $attributes->class('h-11 rounded-md border border-ink-200 bg-white px-4 text-sm text-ink-800 inset-shadow-soft outline-none focus:border-guardian-500 focus:shadow-focus') }}>
@endif
