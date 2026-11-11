{{--
    Delinquents report rendered by dompdf (DelinquentsExportController and
    the delinquency report, App\Reports\DelinquencyReport). dompdf
    does not read Tailwind, so the design system tokens are inlined as plain CSS.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Inadimplentes</title>
    <style>
        @page { margin: 32px 36px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #232834; }
        table { width: 100%; border-collapse: collapse; }
        .header { border-bottom: 3px solid #2e78f2; padding-bottom: 12px; }
        .header td { vertical-align: top; }
        .clinic-name { font-size: 17px; font-weight: bold; color: #112d63; }
        .muted { color: #6a7385; }
        .title { font-size: 18px; font-weight: bold; color: #112d63; text-align: right; }
        .summary { margin: 18px 0 12px; padding: 10px 14px; border-radius: 8px; background: #fdecec; color: #962727; font-weight: bold; }
        .rows th { padding: 7px 6px; font-size: 10px; text-align: left; color: #6a7385; border-bottom: 1px solid #d5dbe5; }
        .rows td { padding: 7px 6px; border-bottom: 1px solid #e8ecf3; }
        .rows .number { text-align: right; }
        .strong { font-weight: bold; }
        .late { font-weight: bold; color: #c53030; }
        .empty { padding: 24px; text-align: center; color: #6a7385; }
        .footer { margin-top: 20px; font-size: 9px; color: #8e97a8; text-align: center; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="clinic-name">{{ $clinic->clinic_name }}</div>
                @if ($clinic->clinic_cnpj)
                    <div class="muted">CNPJ {{ $clinic->clinic_cnpj }}</div>
                @endif
                @if ($clinic->clinic_address)
                    <div class="muted">{{ $clinic->clinic_address }}</div>
                @endif
                @if ($clinic->clinic_phone)
                    <div class="muted">Telefone {{ $clinic->clinic_phone }}</div>
                @endif
            </td>
            <td style="width: 40%;">
                <div class="title">Inadimplentes</div>
                <div class="muted" style="text-align: right;">Emitido em {{ now()->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <div class="summary">
        {{ \App\Queries\DelinquentsQuery::summaryLabel($summary) }}
    </div>

    @if ($delinquents->isEmpty())
        <div class="empty">Nenhum titular ou empresa com faturas em atraso.</div>
    @else
        <table class="rows">
            <thead>
                <tr>
                    <th>Titular / empresa</th>
                    <th>Telefone</th>
                    <th class="number">Faturas</th>
                    <th class="number">Total</th>
                    <th>Vencimento mais antigo</th>
                    <th class="number">Maior atraso</th>
                    <th>Cartão / situação</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($delinquents as $delinquent)
                    <tr>
                        <td class="strong">{{ $delinquent->isCompany() ? $delinquent->name.' (empresa)' : $delinquent->name }}</td>
                        <td>{{ $delinquent->phone ? \App\Support\Format::phone($delinquent->phone) : '—' }}</td>
                        <td class="number">{{ $delinquent->overdueCount }}</td>
                        <td class="number strong">{{ \App\Support\Format::money($delinquent->overdueAmount) }}</td>
                        <td>{{ \App\Support\Format::date($delinquent->oldestDueDate) }}</td>
                        <td class="number late">{{ $delinquent->maxDaysOverdue }} {{ $delinquent->maxDaysOverdue === 1 ? 'dia' : 'dias' }}</td>
                        <td>{{ $delinquent->situation() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">{{ $clinic->clinic_name }} · Cartão fidelidade</div>
</body>
</html>
