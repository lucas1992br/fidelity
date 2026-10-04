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
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <main class="flex min-h-screen flex-col items-center justify-center gap-8 px-4 py-10 text-ink-700">
            <div class="flex items-center gap-3">
                <div class="flex size-11 flex-none items-center justify-center rounded-md bg-guardian-500 text-white">
                    <x-ui.icon name="heart-pulse" class="size-6" />
                </div>
                <div class="flex flex-col leading-[1.15]">
                    <span class="font-display text-xl font-bold tracking-[-0.01em] text-ink-800">{{ $clinicName }}</span>
                    <span class="text-[13px] text-ink-500">Cartão fidelidade</span>
                </div>
            </div>

            <div class="w-full max-w-[420px]">
                {{ $slot }}
            </div>
        </main>

        <x-ui.toast />
    </body>
</html>
