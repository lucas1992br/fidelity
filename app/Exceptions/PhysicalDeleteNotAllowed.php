<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Payments and financial entries are financial history: a payment is refunded
 * and an entry is cancelled or soft deleted, never removed from the database.
 */
class PhysicalDeleteNotAllowed extends DomainException
{
    public static function for(Model $model): self
    {
        return new self(sprintf(
            '%s #%s cannot be physically deleted; refund or cancel it instead.',
            class_basename($model),
            $model->getKey(),
        ));
    }
}
