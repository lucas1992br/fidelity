{{-- Payments report (App\Reports\PaymentsReport): by method, refunded ones out of the totals. --}}
@extends('pdf.reports.layout')

@section('content')
    <table class="summary">
        @foreach ($totals['methods'] as $line)
            <tr>
                <td class="strong">{{ $line['method']->label() }}</td>
                <td class="right">{{ $line['count'] }} {{ $line['count'] === 1 ? 'pagamento' : 'pagamentos' }}</td>
                <td class="right">{{ \App\Support\Format::money($line['amount']) }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="strong">Total recebido</td>
            <td class="right strong">{{ $totals['total']['count'] }} {{ $totals['total']['count'] === 1 ? 'pagamento' : 'pagamentos' }}</td>
            <td class="right strong">{{ \App\Support\Format::money($totals['total']['amount']) }}</td>
        </tr>
        @if ($totals['refunded']['count'] > 0)
            <tr>
                <td class="muted">Estornados (fora do total)</td>
                <td class="right muted">{{ $totals['refunded']['count'] }}</td>
                <td class="right muted">{{ \App\Support\Format::money($totals['refunded']['amount']) }}</td>
            </tr>
        @endif
    </table>

    @forelse ($payments as $methodId => $methodPayments)
        <div class="section">{{ \App\Enums\PaymentMethod::fromId($methodId)->label() }}</div>
        <table class="rows">
            <thead>
                <tr>
                    <th>Recibo</th>
                    <th>Data</th>
                    <th>Titular</th>
                    <th>Referência</th>
                    <th>Situação</th>
                    <th class="number">Valor</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($methodPayments as $payment)
                    <tr @class(['refunded' => $payment->isRefunded()])>
                        <td>#{{ $payment->receipt_number }}</td>
                        <td>{{ \App\Support\Format::date($payment->paid_at) }}</td>
                        <td class="strong">{{ $payment->invoice->customer->name }}</td>
                        <td>{{ $payment->referencePeriod() }}</td>
                        <td>{{ $payment->isRefunded() ? 'Estornado em '.$payment->refunded_at->format('d/m/Y') : 'Recebido' }}</td>
                        <td class="number">{{ \App\Support\Format::money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <div class="empty">Nenhum pagamento no período.</div>
    @endforelse
@endsection
