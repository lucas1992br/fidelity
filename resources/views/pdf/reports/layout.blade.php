{{--
    Layout of the report PDFs rendered by dompdf (App\Reports\Report::downloadPdf):
    clinic header, report title, period, issue date and footer. dompdf does not
    read Tailwind, so the design system tokens are inlined as plain CSS.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $report::title() }}</title>
    <style>
        @page { margin: 32px 36px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #232834; }
        table { width: 100%; border-collapse: collapse; }
        .header { border-bottom: 3px solid #2e78f2; padding-bottom: 12px; }
        .header td { vertical-align: top; }
        .clinic-name { font-size: 17px; font-weight: bold; color: #112d63; }
        .muted { color: #6a7385; }
        .title { font-size: 18px; font-weight: bold; color: #112d63; text-align: right; }
        .right { text-align: right; }
        .summary { margin: 18px 0 12px; padding: 10px 14px; border-radius: 8px; background: #eef4fe; color: #112d63; }
        .summary td { padding: 2px 6px; }
        .section { margin: 16px 0 6px; font-size: 12px; font-weight: bold; color: #112d63; }
        .rows th { padding: 6px 5px; font-size: 9px; text-align: left; color: #6a7385; border-bottom: 1px solid #d5dbe5; }
        .rows td { padding: 6px 5px; border-bottom: 1px solid #e8ecf3; }
        .rows .number { text-align: right; }
        .rows tfoot td { font-weight: bold; border-top: 1px solid #d5dbe5; border-bottom: none; }
        .strong { font-weight: bold; }
        .negative { color: #c53030; }
        .refunded { color: #8e97a8; text-decoration: line-through; }
        .dependent td { color: #4a5263; }
        .dependent td:first-child { padding-left: 18px; }
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
            <td style="width: 45%;">
                <div class="title">{{ $report::title() }}</div>
                <div class="muted right">{{ $report->periodLabel() }}</div>
                <div class="muted right">Emitido em {{ now()->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    @yield('content')

    <div class="footer">{{ $clinic->clinic_name }} · Cartão fidelidade</div>
</body>
</html>
