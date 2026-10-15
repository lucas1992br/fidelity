<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Link between a payment and each invoice (month) it covers.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $payment_id
 */
class InvoicePayment extends Pivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'invoice_payment';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;
}
