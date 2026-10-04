{{--
    Payment receipt rendered by dompdf (ReceiptController). dompdf does not
    read Tailwind, so the design system tokens are inlined as plain CSS.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Recibo nº {{ $payment->receipt_number }}</title>
    <style>
        @page { margin: 40px 44px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #232834; }
        .header { border-bottom: 3px solid #2e78f2; padding-bottom: 14px; }
        .header td { vertical-align: top; }
        .clinic-name { font-size: 18px; font-weight: bold; color: #112d63; }
        .muted { color: #6a7385; }
        .receipt-label { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: #1a4da8; }
        .receipt-number { font-size: 22px; font-weight: bold; color: #112d63; }
        .title { margin: 24px 0 6px; font-size: 20px; font-weight: bold; color: #232834; }
        .amount-box { margin: 14px 0 18px; padding: 16px 18px; border-radius: 12px; background: #eaf2ff; }
        .amount { font-size: 24px; font-weight: bold; color: #112d63; }
        .amount-words { margin-top: 4px; color: #1a4da8; }
        table { width: 100%; border-collapse: collapse; }
        .fields td { padding: 8px 0; border-top: 1px solid #e8ecf3; }
        .fields td.label { width: 34%; color: #6a7385; }
        .fields td.value { font-weight: bold; }
        .refunded { margin-top: 14px; padding: 10px 14px; border-radius: 8px; background: #fdecec; color: #962727; font-weight: bold; }
        .signature { margin-top: 64px; text-align: center; }
        .signature-line { width: 60%; margin: 0 auto 6px; border-top: 1px solid #8e97a8; }
        .footer { margin-top: 28px; font-size: 10px; color: #8e97a8; text-align: center; }
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
            <td style="text-align: right; width: 34%;">
                <div class="receipt-label">Recibo nº</div>
                <div class="receipt-number">{{ $payment->receipt_number }}</div>
            </td>
        </tr>
    </table>

    <div class="title">Recibo de pagamento</div>
    <div class="muted">Recebemos de {{ $customer->name }} o valor abaixo, referente à mensalidade do cartão fidelidade.</div>

    <div class="amount-box">
        <div class="amount">{{ \App\Support\Format::money($payment->amount) }}</div>
        <div class="amount-words">({{ \App\Support\Format::moneyInWords($payment->amount) }})</div>
    </div>

    <table class="fields">
        <tr><td class="label">Titular</td><td class="value">{{ $customer->name }}</td></tr>
        <tr><td class="label">CPF</td><td class="value">{{ $customer->formatted_cpf }}</td></tr>
        <tr><td class="label">Cartão</td><td class="value">{{ $card?->number ?? '—' }}</td></tr>
        <tr><td class="label">Referência</td><td class="value">{{ $invoice->reference_month->format('m/Y') }}</td></tr>
        <tr><td class="label">Vencimento</td><td class="value">{{ \App\Support\Format::date($invoice->due_date) }}</td></tr>
        <tr><td class="label">Forma de pagamento</td><td class="value">{{ $method->label() }}</td></tr>
        <tr><td class="label">Data do pagamento</td><td class="value">{{ \App\Support\Format::date($payment->paid_at) }}</td></tr>
    </table>

    @if ($payment->isRefunded())
        <div class="refunded">Pagamento estornado em {{ \App\Support\Format::date($payment->refunded_at) }}.</div>
    @endif

    <div class="signature">
        <div class="signature-line"></div>
        <div>{{ $clinic->clinic_name }}</div>
    </div>

    <div class="footer">Emitido em {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
