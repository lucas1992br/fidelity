{{-- Underlined tabs bound to a Livewire property: <x-ui.tabs :tabs="['data' => 'Dados']" :active="$tab" model="tab" /> --}}
@props([
    'tabs' => [],
    'active' => null,
    'model' => null,
])

<div {{ $attributes->class('flex gap-1 overflow-x-auto border-b border-ink-100') }} role="tablist">
    @foreach ($tabs as $key => $label)
        <button
            type="button"
            role="tab"
            aria-selected="{{ $key === $active ? 'true' : 'false' }}"
            wire:key="tab-{{ $key }}"
            @if ($model) wire:click="$set('{{ $model }}', '{{ $key }}')" @endif
            @class([
                'h-11 cursor-pointer border-b-2 px-4 text-sm font-semibold whitespace-nowrap outline-none focus-visible:rounded-t-sm focus-visible:shadow-focus',
                'border-guardian-500 text-guardian-700' => $key === $active,
                'border-transparent text-ink-500 hover:text-ink-700' => $key !== $active,
            ])
        >{{ $label }}</button>
    @endforeach
</div>
