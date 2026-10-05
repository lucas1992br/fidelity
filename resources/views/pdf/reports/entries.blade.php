{{-- Entries report (App\Reports\EntriesReport): entries of the period by category, without cancelled ones. --}}
@extends('pdf.reports.layout')

@section('content')
    <table class="rows" style="margin-top: 18px;">
        <thead>
            <tr>
                <th>Categoria</th>
                <th>Tipo</th>
                <th class="number">Lançamentos</th>
                <th class="number">Pago</th>
                <th class="number">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($totals['categories'] as $line)
                <tr>
                    <td class="strong">{{ $line['category'] }}</td>
                    <td>{{ $line['type']->label() }}</td>
                    <td class="number">{{ $line['count'] }}</td>
                    <td class="number">{{ \App\Support\Format::money($line['paid']) }}</td>
                    <td class="number strong">{{ \App\Support\Format::money($line['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Nenhum lançamento no período.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">Receitas</td>
                <td class="number">{{ \App\Support\Format::money($totals['income']) }}</td>
            </tr>
            <tr>
                <td colspan="4">Despesas</td>
                <td class="number">{{ \App\Support\Format::money($totals['expense']) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($entries->isNotEmpty())
        <div class="section">Lançamentos</div>
        <table class="rows">
            <thead>
                <tr>
                    <th>Categoria</th>
                    <th>Descrição</th>
                    <th>Fornecedor / pagador</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th>Pago em</th>
                    <th class="number">Valor</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entries as $entry)
                    <tr>
                        <td>{{ $entry->category->name }}</td>
                        <td class="strong">{{ $entry->description }}</td>
                        <td>{{ $entry->supplier_or_payer ?? '—' }}</td>
                        <td>{{ \App\Support\Format::date($entry->due_date) }}</td>
                        <td>{{ \App\Enums\EntryStatus::fromId($entry->entry_status_id)->label() }}</td>
                        <td>{{ \App\Support\Format::date($entry->paid_at) }}</td>
                        <td class="number">{{ \App\Support\Format::money($entry->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
