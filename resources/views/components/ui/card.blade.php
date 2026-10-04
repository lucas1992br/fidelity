@props([
    'padding' => 'p-5',
    'size' => 'lg',
])

<div {{ $attributes->class([
    'bg-white',
    $padding,
    $size === 'hero' ? 'rounded-2xl shadow-md' : 'rounded-lg shadow-sm',
]) }}>
    {{ $slot }}
</div>
