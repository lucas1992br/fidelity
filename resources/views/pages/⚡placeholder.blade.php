<?php

use Livewire\Component;

/**
 * Stand-in for routes whose screens arrive in later phases.
 */
new class extends Component
{
    //
};
?>

<div class="flex flex-col items-center gap-2 rounded-2xl bg-white px-6 py-14 text-center shadow-sm">
    <span class="text-[17px] font-semibold text-ink-800">Esta tela ainda não foi desenhada</span>
    <span class="max-w-[420px] text-sm text-ink-500">Ela está em construção e chega em uma próxima etapa do sistema. Enquanto isso, use as telas de atendimento, cartões e faturamento.</span>
    <x-ui.button :href="route('home')" wire:navigate size="lg" class="mt-3">Voltar ao painel</x-ui.button>
</div>
