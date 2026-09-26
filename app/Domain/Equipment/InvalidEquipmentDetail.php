<?php

namespace App\Domain\Equipment;

use DomainException;

/**
 * A refusal that belongs to one field, so a form can show it beside that field.
 */
class InvalidEquipmentDetail extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
