{{-- Global toast. Trigger from Livewire with: $this->dispatch('notify', title: '...', description: '...', receipt: 1043) --}}
<div
    x-data="{ toast: null, timer: null }"
    x-on:notify.window="toast = $event.detail; clearTimeout(timer); timer = setTimeout(() => toast = null, 6000)"
    x-show="toast"
    x-cloak
    x-transition.opacity
    class="fixed right-6 bottom-6 z-30 flex max-w-[440px] items-center gap-4 rounded-lg bg-ink-900 py-3.5 pr-4 pl-5 text-white shadow-xl"
    role="status"
>
    <div class="flex flex-1 flex-col gap-0.5">
        <span class="text-sm font-semibold" x-text="toast?.title"></span>
        <span class="text-[13px] text-ink-300" x-show="toast?.description" x-text="toast?.description"></span>
    </div>
    <template x-if="toast?.receipt">
        {{-- Prototype: the PDF receipt (ReceiptController) arrives with the billing phase. --}}
        <button
            type="button"
            x-on:click="$dispatch('notify', { title: 'Abrindo recibo em PDF', description: 'Recibo nº ' + toast.receipt })"
            class="inline-flex h-9 cursor-pointer items-center rounded-full bg-guardian-500 px-3.5 text-[13px] font-semibold whitespace-nowrap text-white hover:bg-guardian-600"
        >Imprimir recibo</button>
    </template>
    <button type="button" class="size-8 cursor-pointer text-lg text-ink-300" x-on:click="toast = null" aria-label="Fechar">×</button>
</div>
