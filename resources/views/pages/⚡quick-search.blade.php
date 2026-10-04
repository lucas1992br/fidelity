<?php

use App\Support\Navigation;
use App\Support\Prototype\DemoData;
use Livewire\Attributes\Url;
use Illuminate\Contracts\View\View;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $query = '';

    public function render(): View
    {
        return $this->view([
            'results' => DemoData::search($this->query),
            'canSeeBilling' => Navigation::allows('billing.view'),
            'canOpenCustomer' => Navigation::allows('customers.view'),
            'plans' => DemoData::plans(),
            'examples' => ['000123', '000123-02', '000087', 'José'],
        ]);
    }
};
?>

<div class="mx-auto flex w-full max-w-[880px] flex-col gap-5">
    <div class="flex h-16 items-center gap-3.5 rounded-full border-2 border-guardian-500 bg-white px-5.5 shadow-focus">
        <x-ui.icon name="scan-line" class="size-[22px] text-guardian-500" />
        <input
            wire:model.live.debounce.300ms="query"
            autofocus
            placeholder="Digite o nome, CPF ou passe o cartão no leitor"
            aria-label="Buscar cliente"
            class="min-w-0 flex-1 border-0 bg-transparent text-[19px] text-ink-800 outline-none"
        >
        @if ($query !== '')
            <button type="button" wire:click="$set('query', '')" class="h-8 cursor-pointer rounded-full bg-ink-50 px-3 text-[13px] font-semibold text-ink-500">Limpar</button>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2 text-[13px] text-ink-500">
        <span>Experimente:</span>
        @foreach ($examples as $example)
            <button
                type="button"
                wire:key="example-{{ $loop->index }}"
                wire:click="$set('query', '{{ $example }}')"
                class="h-[30px] cursor-pointer rounded-full border border-ink-200 bg-white px-3 font-mono text-xs text-ink-700"
            >{{ $example }}</button>
        @endforeach
    </div>

    @if ($query !== '' && $results === [])
        <div class="flex flex-col items-center gap-1.5 rounded-2xl bg-white p-10 text-center shadow-sm">
            <span class="text-[17px] font-semibold text-ink-800">Nenhum cliente encontrado</span>
            <span class="text-sm text-ink-500">Confira o número do cartão ou busque pelo CPF.</span>
        </div>
    @endif

    @foreach ($results as $result)
        @php
            $customer = $result['customer'];
            $dependents = \App\Support\Prototype\DemoData::dependentsOf($customer);
            $overdue = \App\Support\Prototype\DemoData::invoicesFor($customer['id'])->where('status', \App\Enums\InvoiceStatus::Overdue);
            $next = \App\Support\Prototype\DemoData::invoicesFor($customer['id'])->firstWhere('status', \App\Enums\InvoiceStatus::Open);
            $payable = \App\Support\Prototype\DemoData::firstPayable($customer['id']);
            $suspended = $customer['status'] === \App\Enums\CardStatus::Suspended;
        @endphp

        <div wire:key="result-{{ $customer['id'] }}" class="overflow-hidden rounded-2xl bg-white shadow-md">
            @if ($result['replacedCard'])
                <div class="bg-warning-50 px-6 py-2.5 text-[13px] font-semibold text-warning-700">
                    O cartão {{ $result['replacedCard'] }} foi substituído. Cartão atual: {{ $customer['card'] }}.
                </div>
            @endif

            <div class="flex flex-wrap items-start gap-6 p-6">
                <div class="flex min-w-[260px] flex-1 flex-col gap-1.5">
                    <span class="font-display text-[26px] leading-[1.2] font-bold tracking-tight text-ink-800">{{ $customer['name'] }}</span>
                    <span class="text-sm text-ink-500">
                        CPF {{ $canSeeBilling ? \App\Support\Format::cpf($customer['cpf']) : \App\Support\Format::maskCpf($customer['cpf']) }} · {{ $customer['phone'] }}
                    </span>
                    @if ($result['dependent'])
                        <span class="text-sm font-semibold text-guardian-700">Dependente encontrado: {{ $result['dependent']['name'] }} ({{ $result['dependent']['kinship']->label() }})</span>
                    @endif
                </div>
                <div @class([
                    'flex flex-col items-center gap-1.5 rounded-xl px-7 py-4',
                    match ($customer['status']->color()) {
                        'green' => 'bg-success-50 text-success-700',
                        'amber' => 'bg-warning-50 text-warning-700',
                        'red' => 'bg-danger-50 text-danger-700',
                        'blue' => 'bg-guardian-50 text-guardian-700',
                        default => 'bg-ink-100 text-ink-600',
                    },
                ])>
                    <span class="text-xs font-semibold tracking-[0.08em] uppercase">Cartão {{ $customer['card'] }}</span>
                    <span class="font-display text-[32px] leading-none font-extrabold tracking-tight">{{ $customer['status']->label() }}</span>
                </div>
            </div>

            @if ($canSeeBilling)
                <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] border-t border-ink-100 [&>div]:flex [&>div]:flex-col [&>div]:gap-0.5 [&>div]:px-6 [&>div]:py-4 [&>div:not(:last-child)]:border-r [&>div:not(:last-child)]:border-ink-100">
                    <div>
                        <span class="text-xs text-ink-500">Plano</span>
                        <span class="text-[15px] font-semibold text-ink-800">{{ $customer['plan'] }} · {{ \App\Support\Format::money($plans[$customer['plan']]['price']) }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-ink-500">Próxima mensalidade</span>
                        <span class="text-[15px] font-semibold text-ink-800">{{ $next ? $next['due'].' · '.\App\Support\Format::money($next['amount']) : '—' }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-ink-500">Pendências</span>
                        @if ($overdue->isNotEmpty())
                            <span class="text-[15px] font-semibold text-danger-600">{{ $overdue->count() }} em atraso · {{ \App\Support\Format::money($overdue->sum('amount')) }}</span>
                        @else
                            <span class="text-[15px] font-semibold text-success-700">Nenhuma pendência</span>
                        @endif
                    </div>
                </div>
            @else
                <div class="flex flex-col gap-0.5 border-t border-ink-100 px-6 py-4">
                    <span class="text-xs text-ink-500">Plano</span>
                    <span class="text-[15px] font-semibold text-ink-800">{{ $customer['plan'] }}</span>
                    @if ($suspended)
                        <span class="mt-2 text-sm font-semibold text-warning-700">Cartão suspenso — encaminhar ao financeiro.</span>
                    @endif
                </div>
            @endif

            @if ($dependents !== [])
                <div class="flex flex-col gap-2.5 border-t border-ink-100 px-6 py-4">
                    <span class="text-xs text-ink-500">Dependentes · usam o mesmo cartão</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($dependents as $dependent)
                            <span wire:key="dep-{{ $dependent['code'] }}" class="flex items-center gap-2 rounded-full bg-ink-50 px-3.5 py-1.5 text-[13px]">
                                <span class="font-mono text-xs text-ink-500">{{ $dependent['code'] }}</span>
                                <span class="font-semibold text-ink-800">{{ $dependent['name'] }}</span>
                                <span class="text-ink-500">{{ $dependent['kinship']->label() }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($canOpenCustomer || ($canSeeBilling && $payable))
                <div class="flex flex-wrap gap-2.5 border-t border-ink-100 bg-ink-25 px-6 py-4">
                    @if ($canOpenCustomer)
                        <x-ui.button size="lg" :href="route('customers.show', $customer['id'])" wire:navigate>Abrir ficha</x-ui.button>
                    @endif
                    @if ($canSeeBilling && $payable && $customer['status'] !== \App\Enums\CardStatus::Cancelled)
                        <x-ui.button size="lg" variant="secondary" wire:click="$dispatch('open-payment', { invoice: {{ $payable['id'] }} })">Registrar pagamento</x-ui.button>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
