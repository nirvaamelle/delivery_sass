<?php

namespace App\Domain\Access;

use DomainException;

/**
 * A refusal that belongs to one field, so a form can show it beside that field.
 */
class InvalidAccountDetail extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
