<?php

namespace App\Actions\Customers;

use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Card;
use App\Models\CardStatusLog;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

/**
 * Everything the clinic stores about a holder (US-12.4, LGPD access right):
 * personal data, dependents (inactive and removed included), every card with
 * its status history, invoices and payments (refunds included). The export
 * is recorded in the activity log.
 */
class ExportCustomerData
{
    /**
     * @return array{exportado_em: string, titular: array<string, mixed>, dependentes: array<int, array<string, mixed>>, cartoes: array<int, array<string, mixed>>, mensalidades: array<int, array<string, mixed>>, pagamentos: array<int, array<string, mixed>>}
     */
    public function handle(Customer $customer, User $user): array
    {
        $data = [
            'exportado_em' => now()->toIso8601String(),
            'titular' => $this->holder($customer),
            'dependentes' => $this->dependents($customer),
            'cartoes' => $this->cards($customer),
            'mensalidades' => $this->invoices($customer),
            'pagamentos' => $this->payments($customer),
        ];

        activity('customers')
            ->performedOn($customer)
            ->causedBy($user)
            ->event('exported')
            ->log('Exportação dos dados do titular (LGPD)');

        return $data;
    }

    /**
     * Download name, e.g. "titular-42-2026-10-04.json".
     */
    public function filename(Customer $customer): string
    {
        return "titular-{$customer->id}-".today()->toDateString().'.json';
    }

    /**
     * @return array<string, mixed>
     */
    protected function holder(Customer $customer): array
    {
        $plan = $customer->plan;

        return [
            'id' => $customer->id,
            'nome' => $customer->name,
            'cpf' => $customer->cpf,
            'nascimento' => $customer->birth_date->toDateString(),
            'email' => $customer->email,
            'celular' => $customer->phone,
            'telefone_secundario' => $customer->phone_secondary,
            'endereco' => [
                'cep' => $customer->zip_code,
                'logradouro' => $customer->street,
                'numero' => $customer->number,
                'complemento' => $customer->complement,
                'bairro' => $customer->district,
                'cidade' => $customer->city,
                'uf' => $customer->state,
            ],
            'plano' => ['nome' => $plan->name, 'preco' => $plan->price],
            'dia_vencimento' => $customer->due_day,
            'adesao' => $customer->joined_at->toDateString(),
            'cadastrado_em' => $customer->created_at?->toIso8601String(),
            'atualizado_em' => $customer->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function dependents(Customer $customer): array
    {
        return $customer->dependents()->withTrashed()->with('kinship')->get()
            ->each(fn (Dependent $dependent) => $dependent->setRelation('customer', $customer))
            ->map(fn (Dependent $dependent): array => [
                'identificacao' => $dependent->identification,
                'sequencia' => $dependent->sequence,
                'nome' => $dependent->name,
                'cpf' => $dependent->cpf,
                'nascimento' => $dependent->birth_date->toDateString(),
                'parentesco' => $dependent->kinship->name,
                'ativo' => $dependent->is_active,
                'removido_em' => $dependent->deleted_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function cards(Customer $customer): array
    {
        return $customer->cards()->with('statusLogs.user:id,name')->orderBy('assigned_at')->orderBy('id')->get()
            ->map(fn (Card $card): array => [
                'numero' => $card->number,
                'status' => CardStatus::fromId($card->card_status_id)->label(),
                'vigente' => $card->is_current,
                'vinculado_em' => $card->assigned_at?->toDateString(),
                'historico' => $card->statusLogs->map(fn (CardStatusLog $log): array => [
                    'data' => $log->created_at?->toIso8601String(),
                    'de' => $log->from_card_status_id ? CardStatus::fromId($log->from_card_status_id)->label() : null,
                    'para' => CardStatus::fromId($log->to_card_status_id)->label(),
                    'motivo' => $log->reason,
                    'responsavel' => $log->user->name ?? 'sistema',
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function invoices(Customer $customer): array
    {
        return $customer->invoices()->with('plan:id,name')->orderBy('reference_month')->orderBy('id')->get()
            ->map(fn (Invoice $invoice): array => [
                'referencia' => $invoice->reference_month->format('Y-m'),
                'plano' => $invoice->plan->name,
                'valor' => $invoice->amount,
                'vencimento' => $invoice->due_date->toDateString(),
                'status' => InvoiceStatus::fromId($invoice->invoice_status_id)->label(),
                'pago_em' => $invoice->paid_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function payments(Customer $customer): array
    {
        return $customer->payments()->with('invoice:id,reference_month')->orderBy('payments.paid_at')->orderBy('payments.id')->get()
            ->map(fn (Payment $payment): array => [
                'recibo' => $payment->receipt_number,
                'referencia' => $payment->invoice->reference_month->format('Y-m'),
                'pago_em' => $payment->paid_at->toDateString(),
                'valor' => $payment->amount,
                'forma' => PaymentMethod::fromId($payment->payment_method_id)->label(),
                'observacao' => $payment->notes,
                'estornado' => $payment->isRefunded(),
                'estornado_em' => $payment->refunded_at?->toIso8601String(),
                'motivo_estorno' => $payment->refund_reason,
            ])
            ->values()
            ->all();
    }
}
