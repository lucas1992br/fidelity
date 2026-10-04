@props([
    'columns' => [],
    'minWidth' => 'min-w-[680px]',
])

<div class="overflow-x-auto">
    <table {{ $attributes->class(['w-full border-collapse text-left text-sm', $minWidth]) }}>
        <thead>
            <tr class="text-xs text-ink-500">
                @foreach ($columns as $column)
                    <th @class(['p-2 font-semibold', 'text-right' => str_starts_with($column, '>')])>{{ ltrim($column, '>') }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="[&>tr]:border-t [&>tr]:border-ink-100 [&_td]:px-2 [&_td]:py-3">
            {{ $slot }}
        </tbody>
    </table>
</div>
