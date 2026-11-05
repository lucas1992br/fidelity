{{-- Companies report (App\Reports\CompaniesReport): companies, employees and their active dependents. --}}
@extends('pdf.reports.layout')

@section('content')
    <table class="summary">
        @forelse ($totals as $line)
            <tr>
                <td class="strong">{{ $line['company'] }}</td>
                <td class="right">{{ $line['employees'] }} {{ $line['employees'] === 1 ? 'funcionário ativo' : 'funcionários ativos' }}</td>
                <td class="right">{{ $line['dependents'] }} {{ $line['dependents'] === 1 ? 'dependente' : 'dependentes' }}</td>
                <td class="right">{{ \App\Support\Format::money($line['monthly']) }}/mês</td>
            </tr>
        @empty
            <tr><td>Nenhuma empresa encontrada.</td></tr>
        @endforelse
        @if (count($totals) > 1)
            <tr>
                <td class="strong">Total</td>
                <td class="right strong">{{ array_sum(array_column($totals, 'employees')) }} funcionários</td>
                <td class="right strong">{{ array_sum(array_column($totals, 'dependents')) }} dependentes</td>
                <td class="right strong">{{ \App\Support\Format::money(array_reduce(array_column($totals, 'monthly'), fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0.00')) }}/mês</td>
            </tr>
        @endif
    </table>

    @foreach ($companies as $company)
        <div class="section">{{ $company->display_name }} · CNPJ {{ $company->formatted_cnpj }} · vencimento dia {{ $company->due_day }}{{ $company->is_active ? '' : ' · inativa' }}</div>
        @if ($company->employees->isEmpty())
            <div class="empty">Nenhum funcionário cadastrado.</div>
        @else
            <table class="rows">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Cartão / identificação</th>
                        <th>CPF</th>
                        <th>Plano</th>
                        <th>Status do cartão</th>
                        <th class="number">Valor mensal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($company->employees as $employee)
                        @php($card = $employee->currentCard ?? $employee->lastCard)
                        @php($status = \App\Reports\CustomersReport::cardStatusOf($employee)?->label() ?? 'Sem cartão')
                        <tr>
                            <td class="strong">{{ $employee->name }}</td>
                            <td>{{ $card?->number ?? '—' }}</td>
                            <td>{{ $employee->masked_cpf }}</td>
                            <td>{{ $employee->plan->name }}</td>
                            <td>{{ $status }}</td>
                            <td class="number">{{ \App\Support\Format::money($employee->monthly_amount) }}</td>
                        </tr>
                        @foreach ($employee->activeDependents as $dependent)
                            <tr class="dependent">
                                <td>{{ $dependent->name }} · {{ $dependent->kinship->name }}</td>
                                <td>{{ $card ? $card->number.'-'.str_pad((string) $dependent->sequence, 2, '0', STR_PAD_LEFT) : '—' }}</td>
                                <td>{{ $dependent->cpf ? \App\Support\Format::maskCpf($dependent->cpf) : '—' }}</td>
                                <td>{{ $employee->plan->name }}</td>
                                <td>{{ $status }}</td>
                                <td></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
@endsection
