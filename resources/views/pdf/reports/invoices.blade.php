{{-- Invoices report (App\Reports\InvoicesReport): holder invoices and company bills due in the period. --}}
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
                    <th>Titular / empresa</th>
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
                @foreach ($invoices as $row)
                    <tr>
                        <td class="strong">{{ $row->name }}@if ($row->isCompanyBill()) <span>(empresa, {{ $row->employees }} {{ $row->employees === 1 ? 'funcionário' : 'funcionários' }})</span>@endif</td>
                        <td>{{ $row->cardNumber ?? '—' }}</td>
                        <td>{{ $row->planName ?? 'Fatura da empresa' }}</td>
                        <td>{{ $row->referenceMonth->format('m/Y') }}</td>
                        <td>{{ \App\Support\Format::date($row->dueDate) }}</td>
                        <td>{{ $row->status->label() }}</td>
                        <td class="number strong">{{ \App\Support\Format::money($row->amount) }}</td>
                        <td>{{ \App\Support\Format::date($row->paidAt) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
