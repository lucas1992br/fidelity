{{-- Customers report (App\Reports\CustomersReport): holders and active dependents. --}}
@extends('pdf.reports.layout')

@section('content')
    <table class="summary">
        @forelse ($totals as $line)
            <tr>
                <td class="strong">{{ $line['plan'] }}</td>
                <td class="right">{{ $line['holders'] }} {{ $line['holders'] === 1 ? 'titular' : 'titulares' }}</td>
                <td class="right">{{ $line['dependents'] }} {{ $line['dependents'] === 1 ? 'dependente' : 'dependentes' }}</td>
            </tr>
        @empty
            <tr><td>Nenhum titular encontrado.</td></tr>
        @endforelse
        @if (count($totals) > 1)
            <tr>
                <td class="strong">Total</td>
                <td class="right strong">{{ array_sum(array_column($totals, 'holders')) }} titulares</td>
                <td class="right strong">{{ array_sum(array_column($totals, 'dependents')) }} dependentes</td>
            </tr>
        @endif
    </table>

    @if ($customers->isNotEmpty())
        <table class="rows">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Cartão / identificação</th>
                    <th>CPF</th>
                    <th>Plano</th>
                    <th>Status do cartão</th>
                    <th>Telefone</th>
                    <th>Adesão</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($customers as $customer)
                    @php($card = $customer->currentCard ?? $customer->lastCard)
                    @php($status = \App\Reports\CustomersReport::cardStatusOf($customer)?->label() ?? 'Sem cartão')
                    <tr>
                        <td class="strong">{{ $customer->name }}</td>
                        <td>{{ $card?->number ?? '—' }}</td>
                        <td>{{ $customer->masked_cpf }}</td>
                        <td>{{ $customer->plan->name }}</td>
                        <td>{{ $status }}</td>
                        <td>{{ \App\Support\Format::phone($customer->phone) }}</td>
                        <td>{{ \App\Support\Format::date($customer->joined_at) }}</td>
                    </tr>
                    @foreach ($customer->activeDependents as $dependent)
                        <tr class="dependent">
                            <td>{{ $dependent->name }} · {{ $dependent->kinship->name }}</td>
                            <td>{{ $card ? $card->number.'-'.str_pad((string) $dependent->sequence, 2, '0', STR_PAD_LEFT) : '—' }}</td>
                            <td>{{ $dependent->cpf ? \App\Support\Format::maskCpf($dependent->cpf) : '—' }}</td>
                            <td>{{ $customer->plan->name }}</td>
                            <td>{{ $status }}</td>
                            <td></td>
                            <td></td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
