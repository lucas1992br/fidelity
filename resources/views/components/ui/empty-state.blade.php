@props([
    'title',
    'description' => null,
    'icon' => 'inbox',
])

<div {{ $attributes->class('flex flex-col items-center gap-2 rounded-md bg-ink-50 px-6 py-10 text-center') }}>
    @if ($icon)
        <span class="mb-1 flex size-11 items-center justify-center rounded-full bg-white text-ink-400 shadow-xs">
            <x-ui.icon :name="$icon" class="size-5" />
        </span>
    @endif
    <span class="text-[15px] font-semibold text-ink-800">{{ $title }}</span>
    @if ($description)
        <span class="max-w-[420px] text-sm text-ink-500">{{ $description }}</span>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-3 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
