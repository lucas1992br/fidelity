{{-- Cash flow report (App\Reports\CashFlowReportExport): cash basis, by day or month. --}}
@extends('pdf.reports.layout')

@section('content')
    <table class="summary">
        <tr>
            <td><span class="strong">Saldo inicial</span> {{ \App\Support\Format::money($cashFlow['opening_balance']) }}</td>
            <td><span class="strong">Entradas</span> {{ \App\Support\Format::money($cashFlow['total_income']) }}</td>
            <td><span class="strong">Saídas</span> {{ \App\Support\Format::money($cashFlow['total_expense']) }}</td>
            <td><span class="strong">Saldo final</span> {{ \App\Support\Format::money($cashFlow['closing_balance']) }}</td>
        </tr>
    </table>

    <table class="rows">
        <thead>
            <tr>
                <th>{{ $cashFlow['granularity'] === \App\Services\CashFlowReport::Monthly ? 'Mês' : 'Dia' }}</th>
                <th class="number">Entradas</th>
                <th class="number">Saídas</th>
                <th class="number">Saldo</th>
                <th class="number">Saldo acumulado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cashFlow['rows'] as $row)
                <tr>
                    <td class="strong">{{ $row['label'] }}</td>
                    <td class="number">{{ \App\Support\Format::money($row['income']) }}</td>
                    <td class="number">{{ \App\Support\Format::money($row['expense']) }}</td>
                    <td @class(['number', 'negative' => str_starts_with($row['balance'], '-')])>{{ \App\Support\Format::money($row['balance']) }}</td>
                    <td @class(['number strong', 'negative' => str_starts_with($row['cumulative'], '-')])>{{ \App\Support\Format::money($row['cumulative']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="number">{{ \App\Support\Format::money($cashFlow['total_income']) }}</td>
                <td class="number">{{ \App\Support\Format::money($cashFlow['total_expense']) }}</td>
                <td class="number">{{ \App\Support\Format::money($cashFlow['total_balance']) }}</td>
                <td class="number">{{ \App\Support\Format::money($cashFlow['closing_balance']) }}</td>
            </tr>
        </tfoot>
    </table>
@endsection
