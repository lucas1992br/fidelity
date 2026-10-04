{{-- Pagination in the design system. Livewire renders it through vendor/livewire/tailwind with $livewire = true. --}}
@php
    $livewire ??= false;
    $pageName = $paginator->getPageName();
    $linkClasses = 'inline-flex h-9 min-w-9 items-center justify-center rounded-full px-3 text-[13px] font-semibold no-underline outline-none transition-colors duration-200 ease-standard hover:no-underline focus-visible:shadow-focus';
@endphp

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Paginação" class="flex flex-wrap items-center justify-between gap-3">
        <p class="m-0 text-[13px] text-ink-500">
            <span class="sm:hidden">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
            <span class="hidden sm:inline">
                {{ __('Showing') }}
                <span class="font-semibold text-ink-700">{{ $paginator->firstItem() }}</span>
                {{ __('to') }}
                <span class="font-semibold text-ink-700">{{ $paginator->lastItem() }}</span>
                {{ __('of') }}
                <span class="font-semibold text-ink-700">{{ $paginator->total() }}</span>
                {{ __('results') }}
            </span>
        </p>

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="{{ $linkClasses }} cursor-default text-ink-300" aria-disabled="true" aria-label="Página anterior">
                    <x-ui.icon name="chevron-left" class="size-4" />
                </span>
            @else
                <a
                    href="{{ $paginator->previousPageUrl() }}"
                    rel="prev"
                    @if ($livewire) wire:click.prevent="previousPage('{{ $pageName }}')" @endif
                    class="{{ $linkClasses }} text-ink-700 hover:bg-ink-50 hover:text-ink-800"
                    aria-label="Página anterior"
                >
                    <x-ui.icon name="chevron-left" class="size-4" />
                </a>
            @endif

            <div class="hidden items-center gap-1 sm:flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="{{ $linkClasses }} cursor-default text-ink-400" aria-disabled="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="{{ $linkClasses }} bg-guardian-500 text-white" aria-current="page" wire:key="paginator-{{ $pageName }}-page{{ $page }}">{{ $page }}</span>
                            @else
                                <a
                                    href="{{ $url }}"
                                    @if ($livewire) wire:click.prevent="gotoPage({{ $page }}, '{{ $pageName }}')" @endif
                                    wire:key="paginator-{{ $pageName }}-page{{ $page }}"
                                    class="{{ $linkClasses }} text-ink-700 hover:bg-ink-50 hover:text-ink-800"
                                    aria-label="Ir para a página {{ $page }}"
                                >{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </div>

            @if ($paginator->hasMorePages())
                <a
                    href="{{ $paginator->nextPageUrl() }}"
                    rel="next"
                    @if ($livewire) wire:click.prevent="nextPage('{{ $pageName }}')" @endif
                    class="{{ $linkClasses }} text-ink-700 hover:bg-ink-50 hover:text-ink-800"
                    aria-label="Próxima página"
                >
                    <x-ui.icon name="chevron-right" class="size-4" />
                </a>
            @else
                <span class="{{ $linkClasses }} cursor-default text-ink-300" aria-disabled="true" aria-label="Próxima página">
                    <x-ui.icon name="chevron-right" class="size-4" />
                </span>
            @endif
        </div>
    </nav>
@endif
