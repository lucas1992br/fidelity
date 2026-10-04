@props([
    'value' => 0,
    'max' => 100,
    'label' => null,
])

@php($percent = $max > 0 ? (int) round(min(max($value / $max, 0), 1) * 100) : 0)

<div
    {{ $attributes->class('h-2 overflow-hidden rounded-full bg-ink-100') }}
    role="progressbar"
    aria-valuemin="0"
    aria-valuemax="{{ $max }}"
    aria-valuenow="{{ $value }}"
    @if ($label) aria-label="{{ $label }}" @endif
>
    <div class="h-full rounded-full bg-guardian-500" style="width: {{ $percent }}%"></div>
</div>
