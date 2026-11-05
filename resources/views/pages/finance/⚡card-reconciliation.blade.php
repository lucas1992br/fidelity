<?php

use App\Actions\Billing\ImportCardStatement;
use App\Actions\Billing\ReconcileCardTransaction;
use App\Enums\CardTransactionResult;
use App\Models\CardTransaction;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Card reconciliation: import the Granito statement (`ImportCardStatement`),
 * which settles the invoices of the holders and companies whose card is
 * known, list the transactions by result, link a pending transaction to a
 * holder or company (`ReconcileCardTransaction`, the card is remembered) and
 * retry the pending ones (e.g. after the month's invoices are generated).
 * Every entry point requires `billing.pay`.
 */
new class extends Component
{
    use WithFileUploads;
    use WithPagination;

    /**
     * @var UploadedFile|null
     */
    public $statement = null;

    #[Url]
    public string $result = 'pending';

    /**
     * Summary of the last import, shown above the list.
     *
     * @var array{file: string, created: int, duplicates: int, paid: int, pending: int, ignored: int, errors: list<string>}|null
     */
    public ?array $summary = null;

    /**
     * Pending transaction being linked to a payer in the modal.
     */
    #[Locked]
    public ?int $linkingId = null;

    public string $payerSearch = '';

    public function mount(): void
    {
        $this->authorize('billing.pay');
    }

    public function updatedResult(): void
    {
        $this->resetPage();
    }

    public function import(ImportCardStatement $importCardStatement): void
    {
        $this->authorize('billing.pay');

        $this->validate(
            ['statement' => ['required', 'file', 'extensions:txt,csv', 'mimes:txt,csv', 'max:5120']],
            ['statement.required' => 'Selecione o arquivo do extrato.'],
            ['statement' => 'arquivo'],
        );

        try {
            ['import' => $import, 'errors' => $errors] = $importCardStatement->handle(
                (string) $this->statement->get(),
                $this->statement->getClientOriginalName(),
                auth()->user(),
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['statement' => collect($exception->errors())->flatten()->all()]);
        }

        $counts = $import->transactions()->toBase()->selectRaw('result, count(*) as total')->groupBy('result')->pluck('total', 'result');

        $this->summary = [
            'file' => $import->file_name,
            'created' => $import->transactions_count,
            'duplicates' => $import->duplicates_count,
            'paid' => (int) ($counts[CardTransactionResult::Paid->value] ?? 0),
            'pending' => (int) ($counts[CardTransactionResult::Pending->value] ?? 0),
            'ignored' => (int) ($counts[CardTransactionResult::Ignored->value] ?? 0),
            'errors' => $errors,
        ];

        $this->reset('statement');
        $this->result = $this->summary['pending'] > 0 ? 'pending' : '';
        $this->resetPage();
        $this->dispatch('notify', title: 'Extrato importado', description: "{$this->summary['paid']} baixa(s) · {$this->summary['pending']} pendente(s)");
    }

    /**
     * Try the pending transactions again, e.g. after new invoices or cards.
     */
    public function retryPending(ReconcileCardTransaction $reconcileCardTransaction): void
    {
        $this->authorize('billing.pay');

        $pending = CardTransaction::query()->withResult(CardTransactionResult::Pending)->orderBy('occurred_at')->orderBy('id')->get();
        $paid = $pending
            ->map(fn (CardTransaction $transaction): CardTransaction => $reconcileCardTransaction->handle($transaction, auth()->user()))
            ->filter(fn (CardTransaction $transaction): bool => $transaction->result === CardTransactionResult::Paid)
            ->count();

        $this->dispatch('notify', title: 'Pendentes reprocessadas', description: "{$paid} de {$pending->count()} baixada(s).");
    }

    public function openLink(int $transactionId): void
    {
        $this->authorize('billing.pay');

        $this->linkingId = CardTransaction::query()->withResult(CardTransactionResult::Pending)->findOrFail($transactionId)->id;
        $this->reset('payerSearch');
        $this->resetValidation();
    }

    public function closeLink(): void
    {
        $this->reset('linkingId', 'payerSearch');
        $this->resetValidation();
    }

    /**
     * Settle the transaction being linked with the chosen holder or company.
     */
    public function link(string $payerType, int $payerId, ReconcileCardTransaction $reconcileCardTransaction): void
    {
        $this->authorize('billing.pay');

        $transaction = $this->linkingTransaction ?? abort(404);
        $payer = $payerType === 'company' ? Company::query()->findOrFail($payerId) : Customer::query()->findOrFail($payerId);

        $transaction = $reconcileCardTransaction->handle($transaction, auth()->user(), $payer);

        if ($transaction->result !== CardTransactionResult::Paid) {
            $this->addError('payerSearch', $transaction->message);

            return;
        }

        $this->dispatch('notify', title: 'Pagamento registrado', description: 'Cartão '.$transaction->maskedNumber().' vinculado; os próximos serão baixados automaticamente.');
        $this->closeLink();
    }

    #[Computed]
    public function linkingTransaction(): ?CardTransaction
    {
        return $this->linkingId ? CardTransaction::query()->withResult(CardTransactionResult::Pending)->find($this->linkingId) : null;
    }

    /**
     * Holders and companies matching the search in the link modal.
     *
     * @return Collection<int, array{type: string, id: int, name: string, detail: string}>
     */
    #[Computed]
    public function payerOptions(): Collection
    {
        if (mb_strlen(trim($this->payerSearch)) < 2) {
            return collect();
        }

        $companies = Company::query()->search($this->payerSearch)->orderBy('name')->limit(5)->get()
            ->map(fn (Company $company): array => ['type' => 'company', 'id' => $company->id, 'name' => $company->display_name, 'detail' => 'Empresa · CNPJ '.$company->formatted_cnpj]);

        $customers = Customer::query()->with(['currentCard', 'company'])->search($this->payerSearch)->orderBy('name')->limit(8)->get()
            ->map(fn (Customer $customer): array => ['type' => 'customer', 'id' => $customer->id, 'name' => $customer->name, 'detail' => collect([
                'Cartão '.($customer->currentCard?->number ?? '—'),
                $customer->masked_cpf,
                $customer->company ? 'funcionário de '.$customer->company->display_name : null,
            ])->filter()->implode(' · ')]);

        return $companies->concat($customers)->values();
    }

    public function render(): View
    {
        $filter = CardTransactionResult::tryFrom($this->result);

        return $this->view([
            'transactions' => CardTransaction::query()
                ->with(['payment.company', 'payment.invoice.customer'])
                ->when($filter, fn ($query, CardTransactionResult $result) => $query->withResult($result))
                ->latest('occurred_at')
                ->latest('id')
                ->paginate(30),
            'resultFilters' => [
                'pending' => 'Pendentes',
                'paid' => 'Baixados',
                'ignored' => 'Ignorados',
                '' => 'Todos',
            ],
            'pendingCount' => CardTransaction::query()->withResult(CardTransactionResult::Pending)->count(),
        ]);
    }
};
?>

<div class="flex flex-col gap-5">
    <x-ui.card class="flex flex-col gap-4">
        <x-ui.card-title>Importar extrato da Granito</x-ui.card-title>
        <p class="m-0 text-sm text-ink-500">
            Envie o .txt exportado do banco. Transações aprovadas de cartões já conhecidos dão baixa na mensalidade em aberto mais antiga do cliente (ou na fatura da empresa).
            Cartões novos ficam pendentes para você vincular uma vez; nos meses seguintes a baixa é automática. A mesma transação nunca é importada duas vezes.
        </p>
        <div class="flex flex-wrap items-end gap-3">
            <x-ui.field label="Arquivo .txt ou .csv" for="statement-file" error="statement" class="min-w-60 flex-1">
                <input id="statement-file" type="file" accept=".txt,.csv,text/plain,text/csv" wire:model="statement" class="text-sm text-ink-700 file:mr-3 file:h-9 file:cursor-pointer file:rounded-full file:border file:border-ink-200 file:bg-white file:px-3.5 file:text-[13px] file:font-semibold file:text-ink-800">
            </x-ui.field>
            <x-ui.button icon="arrow-down-left" wire:click="import" wire:loading.attr="disabled" wire:target="import,statement">Importar e dar baixa</x-ui.button>
        </div>

        @if ($summary)
            <x-ui.callout :tone="$summary['pending'] > 0 || $summary['errors'] !== [] ? 'warning' : 'success'" wire:key="import-summary">
                <span class="font-semibold">{{ $summary['file'] }}:</span>
                {{ $summary['created'] }} transação(ões) nova(s) · {{ $summary['paid'] }} baixada(s) · {{ $summary['pending'] }} pendente(s) · {{ $summary['ignored'] }} ignorada(s)
                @if ($summary['duplicates'] > 0) · {{ $summary['duplicates'] }} já importada(s) @endif
                @if ($summary['errors'] !== [])
                    <ul class="m-0 mt-1.5 pl-4 text-[13px]">
                        @foreach ($summary['errors'] as $error)
                            <li wire:key="import-error-{{ $loop->index }}">{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.callout>
        @endif
    </x-ui.card>

    <x-ui.card class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-2.5">
            <div class="flex flex-1 flex-wrap gap-1.5">
                @foreach ($resultFilters as $value => $label)
                    <x-ui.pill wire:key="result-{{ $value ?: 'all' }}" :active="$result === $value" wire:click="$set('result', '{{ $value }}')">{{ $label }}</x-ui.pill>
                @endforeach
            </div>
            @if ($pendingCount > 0)
                <x-ui.button variant="secondary" wire:click="retryPending" wire:loading.attr="disabled" wire:target="retryPending">Reprocessar pendentes ({{ $pendingCount }})</x-ui.button>
            @endif
        </div>

        @if ($transactions->isEmpty())
            <x-ui.empty-state icon="credit-card" title="Nenhuma transação" description="Importe o extrato da Granito para conciliar os pagamentos de cartão." />
        @else
            <x-ui.table :columns="['Data', 'ID Granito', 'Tipo', 'Cartão', 'Valor', 'Status', 'Resultado', '>Ações']" min-width="min-w-[980px]">
                @foreach ($transactions as $transaction)
                    @php($payment = $transaction->payment)
                    <tr wire:key="transaction-{{ $transaction->id }}">
                        <td class="whitespace-nowrap">{{ $transaction->occurred_at->format('d/m/Y H:i') }}</td>
                        <td class="font-mono text-[13px]">{{ $transaction->external_id }}</td>
                        <td>{{ $transaction->type }}</td>
                        <td class="font-mono text-[13px] whitespace-nowrap">{{ $transaction->brand }} {{ $transaction->maskedNumber() }}</td>
                        <td class="font-semibold text-ink-800">{{ \App\Support\Format::money($transaction->amount) }}</td>
                        <td>{{ $transaction->status }}</td>
                        <td>
                            <div class="flex flex-col gap-0.5">
                                <x-ui.badge :tone="$transaction->result->color()">{{ $transaction->result->label() }}</x-ui.badge>
                                @if ($payment)
                                    @if ($payment->company)
                                        <a href="{{ route('companies.show', $payment->company) }}" wire:navigate class="text-xs font-semibold text-guardian-700">{{ $payment->company->display_name }}</a>
                                    @else
                                        <a href="{{ route('customers.show', $payment->invoice->customer) }}" wire:navigate class="text-xs font-semibold text-guardian-700">{{ $payment->invoice->customer->name }}</a>
                                    @endif
                                @elseif ($transaction->message)
                                    <span class="max-w-72 text-xs text-ink-500">{{ $transaction->message }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if ($transaction->result === \App\Enums\CardTransactionResult::Pending)
                                <x-ui.button variant="soft" size="sm" wire:click="openLink({{ $transaction->id }})">Vincular e dar baixa</x-ui.button>
                            @elseif ($payment)
                                <x-ui.button variant="link" size="sm" :href="route('receipts.show', $payment)" target="_blank" rel="noopener">Recibo nº {{ $payment->receipt_number }}</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            {{ $transactions->links() }}
        @endif
    </x-ui.card>

    @if ($this->linkingTransaction)
        @php($linking = $this->linkingTransaction)
        <x-ui.modal
            title="Vincular pagamento"
            :subtitle="$linking->brand.' '.$linking->maskedNumber().' · '.\App\Support\Format::money($linking->amount).' · '.$linking->type.' · '.$linking->occurred_at->format('d/m/Y')"
            close="closeLink"
        >
            <x-ui.field label="Cliente ou empresa" for="payer-search" error="payerSearch" hint="Busque por nome, CPF, nº do cartão fidelidade ou CNPJ.">
                <x-ui.input id="payer-search" wire:model.live.debounce.300ms="payerSearch" error="payerSearch" autocomplete="off" autofocus />
            </x-ui.field>

            @if ($this->payerOptions->isNotEmpty())
                <ul class="m-0 flex max-h-72 list-none flex-col divide-y divide-ink-100 overflow-y-auto rounded-md border border-ink-200 p-0">
                    @foreach ($this->payerOptions as $option)
                        <li wire:key="payer-{{ $option['type'] }}-{{ $option['id'] }}" class="flex items-center gap-3 px-3.5 py-2.5">
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span class="text-sm font-semibold text-ink-800">{{ $option['name'] }}</span>
                                <span class="text-xs text-ink-500">{{ $option['detail'] }}</span>
                            </div>
                            <x-ui.button size="sm" wire:click="link('{{ $option['type'] }}', {{ $option['id'] }})" wire:loading.attr="disabled" wire:target="link">Dar baixa</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            @elseif (mb_strlen(trim($payerSearch)) >= 2)
                <span class="text-sm text-ink-500">Nenhum cliente ou empresa encontrado.</span>
            @endif

            <span class="text-xs text-ink-500">O cartão fica lembrado para o pagador: nas próximas importações a baixa é automática.</span>

            <x-slot:actions></x-slot:actions>
        </x-ui.modal>
    @endif
</div>
