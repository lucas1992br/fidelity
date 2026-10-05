{{-- Invoices report (App\Reports\InvoicesReport): invoices due in the period. --}}
@extends('pdf.reports.layout')

@section('content')
    <table class="summary">
        <tr>
            <td><span class="strong">Total</span> {{ $summary['total']['count'] }} · {{ \App\Support\Format::money($summary['total']['amount']) }}</td>
            <td><span class="strong">Pagas</span> {{ $summary['paid']['count'] }} · {{ \App\Support\Format::money($summary['paid']['amount']) }}</td>
            <td><span class="strong">Em aberto</span> {{ $summary['open']['count'] }} · {{ \App\Support\Format::money($summary['open']['amount']) }}</td>
            <td><span class="strong">Em atraso</span> {{ $summary['overdue']['count'] }} · {{ \App\Support\Format::money($summary['overdue']['amount']) }}</td>
        </tr>
    </table>

    @if ($invoices->isEmpty())
        <div class="empty">Nenhuma mensalidade no período.</div>
    @else
        <table class="rows">
            <thead>
                <tr>
                    <th>Titular</th>
                    <th>Cartão</th>
                    <th>Plano</th>
                    <th>Referência</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th class="number">Valor</th>
                    <th>Pago em</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td class="strong">{{ $invoice->customer->name }}</td>
                        <td>{{ ($invoice->customer->currentCard ?? $invoice->customer->lastCard)?->number ?? '—' }}</td>
                        <td>{{ $invoice->plan->name }}</td>
                        <td>{{ $invoice->reference_month->format('m/Y') }}</td>
                        <td>{{ \App\Support\Format::date($invoice->due_date) }}</td>
                        <td>{{ \App\Enums\InvoiceStatus::fromId($invoice->invoice_status_id)->label() }}</td>
                        <td class="number strong">{{ \App\Support\Format::money($invoice->amount) }}</td>
                        <td>{{ \App\Support\Format::date($invoice->paid_at) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
