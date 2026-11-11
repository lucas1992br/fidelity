<?php

use App\Queries\DelinquentsQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Delinquents screen (US-8.5): individual holders with overdue invoices and
 * companies with overdue bills (in place of their employees), longest delay
 * first, with a WhatsApp link to charge them and PDF/Excel exports.
 * Requires `billing.view`.
 */
new class extends Component
{
    public function mount(): void
    {
        $this->authorize('billing.view');
    }

    /**
     * Render again after a payment, which may take the holder off the list.
     */
    #[On('payment-registered')]
    public function refreshDelinquents(): void {}

    public function render(): View
    {
        $delinquents = app(DelinquentsQuery::class);

        return $this->view([
            'delinquents' => $delinquents->get(),
            'summary' => $delinquents->summary(),
        ]);
    }
};
?>

<x-ui.card class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-2.5">
        <span class="flex-1 text-sm text-ink-500">
            {{ \App\Queries\DelinquentsQuery::summaryLabel($summary) }}
        </span>
        <x-ui.button variant="secondary" :href="route('billing.delinquents.pdf')" target="_blank" rel="noopener">Exportar PDF</x-ui.button>
        <x-ui.button variant="secondary" :href="route('billing.delinquents.excel')">Exportar Excel</x-ui.button>
    </div>

    @if ($delinquents->isEmpty())
        <x-ui.empty-state icon="circle-check" title="Nenhum inadimplente" description="Os titulares com mensalidades e as empresas com faturas em atraso aparecem aqui." />
    @else
        <x-ui.table :columns="['Titular / empresa', 'WhatsApp', 'Faturas', 'Total', 'Maior atraso', 'Cartão']" min-width="min-w-[760px]">
            @foreach ($delinquents as $delinquent)
                <tr wire:key="delinquent-{{ $delinquent->kind }}-{{ $delinquent->id }}">
                    <td>
                        <a href="{{ $delinquent->url() }}" wire:navigate class="font-semibold text-ink-800 no-underline">{{ $delinquent->name }}</a>
                        @if ($delinquent->isCompany())
                            <div class="text-xs text-ink-500">Fatura da empresa</div>
                        @endif
                    </td>
                    <td>
                        @if ($delinquent->phone)
                            <a href="{{ \App\Support\Format::whatsappUrl($delinquent->phone) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 font-semibold text-success-700">
                                <x-ui.icon name="message-circle" class="size-3.5" />
                                {{ \App\Support\Format::phone($delinquent->phone) }}
                            </a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $delinquent->overdueCount }}</td>
                    <td class="font-semibold text-ink-800">{{ \App\Support\Format::money($delinquent->overdueAmount) }}</td>
                    <td class="font-semibold text-danger-600">{{ $delinquent->maxDaysOverdue }} {{ $delinquent->maxDaysOverdue === 1 ? 'dia' : 'dias' }}</td>
                    <td>
                        @if ($delinquent->isCompany())
                            <x-ui.badge tone="red" dot>{{ $delinquent->situation() }}</x-ui.badge>
                        @elseif ($delinquent->cardStatus)
                            <x-ui.badge :tone="$delinquent->cardStatus->color()" dot>{{ $delinquent->cardStatus->label() }}</x-ui.badge>
                        @else
                            <x-ui.badge dot>Sem cartão</x-ui.badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
</x-ui.card>
