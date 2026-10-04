@props([
    'title',
    'subtitle' => null,
    'close',
])

<div
    class="fixed inset-0 z-20 flex items-center justify-center bg-ink-900/40 p-6"
    wire:click.self="{{ $close }}"
    x-on:keydown.escape.window="$wire.{{ $close }}()"
    role="dialog"
    aria-modal="true"
>
    <div class="flex w-full max-w-[480px] flex-col rounded-2xl bg-white shadow-xl">
        <div class="flex flex-col gap-1 px-6 pt-6">
            <span class="font-display text-[22px] font-bold text-ink-800">{{ $title }}</span>
            @if ($subtitle)
                <span class="text-sm text-ink-500">{{ $subtitle }}</span>
            @endif
        </div>
        <div class="flex flex-col gap-4 px-6 py-5">
            {{ $slot }}
        </div>
        <div class="flex justify-end gap-2.5 px-6 pt-4 pb-6">
            <x-ui.button variant="ghost" size="lg" wire:click="{{ $close }}">Cancelar</x-ui.button>
            {{ $actions }}
        </div>
    </div>
</div>
