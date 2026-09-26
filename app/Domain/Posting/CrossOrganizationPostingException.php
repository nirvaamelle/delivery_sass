<?php

namespace App\Domain\Posting;

use DomainException;

/**
 * The cost code does not belong to the project's organization.
 *
 * Left unchecked this puts one company's cost into another company's P&L, and
 * nothing downstream ever notices — the row looks entirely well-formed.
 */
class CrossOrganizationPostingException extends DomainException {}
