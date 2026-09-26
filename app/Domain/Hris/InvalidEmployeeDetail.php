<?php

namespace App\Domain\Hris;

use DomainException;

/**
 * A refusal about one specific field of a 201 file.
 *
 * Carries the field so a screen can put the message beside the input that
 * caused it, instead of a banner the user has to match to a field themselves.
 * Still a DomainException, so every caller that already catches those keeps
 * working.
 */
class InvalidEmployeeDetail extends DomainException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
