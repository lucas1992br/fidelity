@props(['name'])

@php
    $svg = preg_replace(
        ['/<!--.*?-->\s*/s', '/\s(class|width|height)="[^"]*"/'],
        '',
        file_get_contents(resource_path("svg/lucide/{$name}.svg")),
    );
@endphp

{!! str_replace('<svg', '<svg aria-hidden="true" '.$attributes->class(['size-[18px] shrink-0'])->toHtml(), $svg) !!}
