<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Settings\ClinicSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ReceiptController extends Controller
{
    /**
     * Stream the payment receipt as a PDF (US-9.3). It is rendered on demand
     * and never stored, so any payment can be printed again at any time.
     */
    public function __invoke(Payment $payment, ClinicSettings $settings): Response
    {
        Gate::authorize('viewReceipt', $payment);

        $payment->load(['invoice.customer.currentCard', 'invoice.customer.lastCard', 'company', 'invoices']);

        return Pdf::loadView('pdf.receipt', [
            'payment' => $payment,
            'invoice' => $payment->invoice,
            'customer' => $payment->invoice->customer,
            'company' => $payment->company,
            'card' => $payment->invoice->customer->currentCard ?? $payment->invoice->customer->lastCard,
            'method' => PaymentMethod::fromId($payment->payment_method_id),
            'clinic' => $settings,
        ])->stream("recibo-{$payment->receipt_number}.pdf");
    }
}
