{{--
    Global toast. Trigger from Livewire with:
    $this->dispatch('notify', title: '...', description: '...', type: 'success'|'error', receiptUrl: route('receipts.show', $payment))
--}}
<div
    x-data="{ toast: null, timer: null }"
    x-on:notify.window="toast = { type: 'success', ...$event.detail }; clearTimeout(timer); timer = setTimeout(() => toast = null, 6000)"
    x-show="toast"
    x-cloak
    x-transition.opacity
    class="fixed right-4 bottom-4 left-4 z-30 flex items-center gap-4 rounded-lg bg-ink-900 py-3.5 pr-4 pl-5 text-white shadow-xl sm:left-auto sm:right-6 sm:bottom-6 sm:max-w-[440px]"
    x-bind:class="toast?.type === 'error' && 'border-l-4 border-danger-500'"
    role="status"
    aria-live="polite"
>
    <template x-if="toast?.type === 'error'">
        <x-ui.icon name="circle-alert" class="size-5 text-danger-500" />
    </template>
    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="text-sm font-semibold" x-text="toast?.title"></span>
        <span class="text-[13px] text-ink-300" x-show="toast?.description" x-text="toast?.description"></span>
    </div>
    <template x-if="toast?.receiptUrl">
        <a
            x-bind:href="toast.receiptUrl"
            target="_blank"
            rel="noopener"
            class="inline-flex h-9 items-center rounded-full bg-guardian-500 px-3.5 text-[13px] font-semibold whitespace-nowrap text-white no-underline outline-none hover:bg-guardian-600 hover:text-white hover:no-underline focus-visible:shadow-focus"
        >Imprimir recibo</a>
    </template>
    <button type="button" class="size-8 flex-none cursor-pointer rounded-full text-lg text-ink-300 outline-none hover:text-white focus-visible:shadow-focus" x-on:click="toast = null; clearTimeout(timer)" aria-label="Fechar">×</button>
</div>
