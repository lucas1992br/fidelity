<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Acesso negado · Cartão Fidelidade</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.png" type="image/png" sizes="192x192">

        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body>
        <main class="flex min-h-screen flex-col items-center justify-center gap-8 px-4 py-10">
            <img src="{{ asset('images/logo.png') }}" alt="Policlínica" class="h-auto w-[240px] max-w-full" data-slot="logo">
            <x-ui.card size="hero" padding="px-6 py-14" class="flex w-full max-w-[520px] flex-col items-center gap-2 text-center">
                <span class="mb-2 flex size-12 items-center justify-center rounded-full bg-danger-50 text-danger-700">
                    <x-ui.icon name="shield-alert" class="size-6" />
                </span>
                <span class="text-xs font-semibold tracking-[0.08em] text-ink-400 uppercase">Erro 403</span>
                <h1 class="m-0 font-display text-2xl leading-[1.2] font-bold tracking-tight text-ink-800">Você não tem permissão para acessar esta página</h1>
                <p class="m-0 max-w-[420px] text-sm text-ink-500">Seu perfil não dá acesso a esta tela. Se precisar dela, fale com o administrador do sistema.</p>
                <x-ui.button :href="route('home')" size="lg" class="mt-3">Ir para a página inicial</x-ui.button>
            </x-ui.card>
        </main>
    </body>
</html>
