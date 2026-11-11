{{-- Sales by user report (App\Reports\SalesByUserReport): sign-ups and re-enrollments, enrollment fees and commissions. --}}
@extends('pdf.reports.layout')

@section('content')
    <table class="summary">
        <tr>
            <td class="strong">Atendente</td>
            <td class="right strong">Adesões</td>
            <td class="right strong">Reingressos</td>
            <td class="right strong">Valor mensal vendido</td>
            <td class="right strong">Com 1ª mensalidade paga</td>
            <td class="right strong">Taxas de adesão</td>
            <td class="right strong">Comissão devida</td>
        </tr>
        @forelse ($totals as $line)
            <tr>
                <td>{{ $line['user'] }}</td>
                <td class="right">{{ $line['sales'] }}</td>
                <td class="right">{{ $line['reenrollments'] }}</td>
                <td class="right">{{ \App\Support\Format::money($line['monthly']) }}</td>
                <td class="right">{{ $line['paid_sales'] }} · {{ \App\Support\Format::money($line['paid_monthly']) }}</td>
                <td class="right">{{ \App\Support\Format::money($line['enrollment_fees']) }}</td>
                <td class="right">{{ \App\Support\Format::money($line['commission']) }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Nenhuma venda no período.</td></tr>
        @endforelse
    </table>

    @if ($sales->isNotEmpty())
        <table class="rows">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Atendente</th>
                    <th>Tipo</th>
                    <th>Cliente</th>
                    <th>Cartão</th>
                    <th>Plano</th>
                    <th>Empresa</th>
                    <th class="number">Valor mensal</th>
                    <th class="number">Taxa de adesão</th>
                    <th>1ª mensalidade</th>
                    <th class="number">Comissão</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sales as $sale)
                    @php($firstInvoice = \App\Reports\SalesByUserReport::firstInvoiceOf($sale))
                    <tr>
                        <td>{{ $sale->created_at->format('d/m/Y') }}</td>
                        <td>{{ $sale->user->name }}</td>
                        <td>{{ \App\Reports\SalesByUserReport::typeOf($sale) }}</td>
                        <td class="strong">{{ $sale->customer->name }}</td>
                        <td>{{ ($sale->customer->currentCard ?? $sale->customer->lastCard)?->number ?? '—' }}</td>
                        <td>{{ $sale->toPlan->name }}</td>
                        <td>{{ $sale->customer->company?->display_name ?? '—' }}</td>
                        <td class="number">{{ \App\Support\Format::money($sale->to_price) }}</td>
                        <td class="number">{{ \App\Support\Format::money($sale->enrollment_fee ?? '0.00') }}</td>
                        <td>{{ $firstInvoice ? \App\Enums\InvoiceStatus::fromId($firstInvoice->invoice_status_id)->label() : '—' }}</td>
                        <td class="number">{{ \App\Support\Format::money(\App\Reports\SalesByUserReport::commissionDueOf($sale)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
