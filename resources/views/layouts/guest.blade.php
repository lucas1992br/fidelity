@php
    use App\Settings\ClinicSettings;

    $clinicName = rescue(fn (): string => app(ClinicSettings::class)->clinic_name, 'Policlínica', report: false);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? 'Entrar' }} · Cartão Fidelidade</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.png" type="image/png" sizes="192x192">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <main class="flex min-h-screen flex-col items-center justify-center gap-8 px-4 py-10 text-ink-700">
            <img src="{{ asset('images/logo.png') }}" alt="{{ $clinicName }}" class="h-auto w-[280px] max-w-full" data-slot="logo">

            <div class="w-full max-w-[420px]">
                {{ $slot }}
            </div>
        </main>

        <x-ui.toast />
    </body>
</html>
