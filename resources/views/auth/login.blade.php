<x-layouts::guest title="Entrar">
    <x-ui.card size="hero" padding="p-6 sm:p-8" class="flex flex-col gap-6">
        <div class="flex flex-col gap-1">
            <h1 class="m-0 font-display text-[22px] font-bold text-ink-800">Entrar</h1>
            <p class="m-0 text-sm text-ink-500">Use o e-mail e a senha cadastrados pelo administrador.</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4" novalidate>
            @csrf

            <x-ui.field label="E-mail" for="email" error="email">
                <x-ui.input
                    id="email"
                    name="email"
                    type="email"
                    error="email"
                    :value="old('email')"
                    autocomplete="username"
                    autofocus
                    required
                    class="w-full"
                />
            </x-ui.field>

            <x-ui.field label="Senha" for="password" error="password">
                <x-ui.input
                    id="password"
                    name="password"
                    type="password"
                    error="password"
                    autocomplete="current-password"
                    required
                    class="w-full"
                />
            </x-ui.field>

            <label class="flex cursor-pointer items-center gap-2 text-sm text-ink-700">
                <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded-xs accent-guardian-500">
                Lembrar de mim
            </label>

            <x-ui.button type="submit" size="lg" class="mt-2 w-full">Entrar</x-ui.button>
        </form>
    </x-ui.card>
</x-layouts::guest>
