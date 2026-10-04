@php
    use App\Support\Navigation;

    $current = Navigation::current();
    $pageTitle = $title ?? $current['item']['label'] ?? config('app.name');
    $crumb = $crumb ?? $current['group'] ?? '';
    $user = auth()->user();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $pageTitle }} · Cartão Fidelidade</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body x-data x-on:keydown.f2.window.prevent="Livewire.navigate('{{ route('quick-search') }}')">
        <div class="flex min-h-screen text-ink-700">
            <aside class="sticky top-0 flex h-screen w-64 flex-none flex-col gap-5 overflow-y-auto border-r border-ink-100 bg-white px-3 py-5 compact:w-[72px]">
                <div class="flex items-center gap-2.5 px-2">
                    <div class="flex size-9 flex-none items-center justify-center rounded-md bg-guardian-500 text-white">
                        <x-ui.icon name="heart-pulse" class="size-5" />
                    </div>
                    <div class="flex flex-col leading-[1.15] compact:hidden">
                        <span class="font-display text-[17px] font-bold tracking-[-0.01em] text-ink-800">Policlínica</span>
                        <span class="text-xs text-ink-500">Cartão fidelidade</span>
                    </div>
                </div>

                <nav class="flex flex-col gap-4">
                    @foreach (Navigation::visibleGroups() as $group)
                        <div class="flex flex-col gap-0.5" wire:key="nav-{{ $group['title'] }}">
                            <div class="px-3 pb-1.5 text-[11px] font-semibold tracking-[0.08em] text-ink-400 uppercase compact:hidden">{{ $group['title'] }}</div>
                            @foreach ($group['items'] as $item)
                                @php($active = $current && $current['item']['route'] === $item['route'])
                                <a
                                    href="{{ route($item['route']) }}"
                                    wire:navigate
                                    wire:key="nav-{{ $item['route'] }}"
                                    title="{{ $item['label'] }}"
                                    @class([
                                        'flex min-h-10 items-center gap-3 rounded-md px-3 text-sm no-underline transition-colors duration-200 ease-standard hover:no-underline',
                                        'bg-guardian-50 font-semibold text-guardian-700 hover:text-guardian-700' => $active,
                                        'font-medium text-ink-700 hover:bg-ink-50 hover:text-ink-700' => ! $active,
                                    ])
                                >
                                    <x-ui.icon :name="$item['icon']" />
                                    <span class="flex-1 compact:hidden">{{ $item['label'] }}</span>
                                    @if ($item['route'] === 'billing.delinquents')
                                        <span class="rounded-full bg-danger-50 px-2 py-0.5 text-[11px] font-bold text-danger-700 compact:hidden">{{ \App\Support\Prototype\DemoData::delinquents()->count() }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </nav>
            </aside>

            <main class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-5 flex flex-wrap items-center gap-4 border-b border-ink-100 bg-white/85 px-8 py-3.5 backdrop-blur-md">
                    <div class="flex min-w-[180px] flex-1 flex-col">
                        <span class="text-xs text-ink-500">{{ $crumb }}</span>
                        <h1 class="m-0 font-display text-2xl leading-[1.2] font-bold tracking-tight text-ink-800">{{ $pageTitle }}</h1>
                    </div>

                    <a
                        href="{{ route('quick-search') }}"
                        wire:navigate
                        class="flex h-10 min-w-60 items-center gap-2.5 rounded-full border border-ink-200 bg-white px-3.5 text-sm text-ink-400 no-underline inset-shadow-soft hover:text-ink-400 hover:no-underline"
                    >
                        <x-ui.icon name="search" class="size-4" />
                        <span class="flex-1">Nome, CPF ou cartão</span>
                        <kbd class="rounded-xs bg-ink-50 px-1.5 py-0.5 font-mono text-[11px]">F2</kbd>
                    </a>

                    <div class="flex items-center gap-2.5">
                        <x-ui.avatar :initials="$user ? \App\Support\Format::initials($user->name) : 'CF'" size="sm" />
                        <div class="flex flex-col leading-[1.2]">
                            <span class="text-[13px] font-semibold text-ink-800">{{ $user?->name ?? 'Protótipo' }}</span>
                            <span class="text-xs text-ink-500">{{ $user ? 'Usuário' : 'Sem autenticação' }}</span>
                        </div>
                    </div>
                </header>

                <div class="flex w-full max-w-[1280px] flex-col gap-6 px-8 pt-7 pb-16">
                    {{ $slot }}
                </div>
            </main>
        </div>

        <livewire:billing.payment-modal />
        <x-ui.toast />
    </body>
</html>
