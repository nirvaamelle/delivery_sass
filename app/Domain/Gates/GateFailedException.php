<?php

namespace App\Domain\Gates;

use DomainException;

/**
 * One or more preconditions refused the transition.
 *
 * Carries every failure, not just the one that happened to be checked first —
 * see Gatekeeper::assert().
 */
class GateFailedException extends DomainException
{
    /**
     * @param  array<string, string>  $failures  precondition name => message
     */
    public function __construct(
        private readonly array $failures,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
