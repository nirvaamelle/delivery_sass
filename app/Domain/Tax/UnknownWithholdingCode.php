<?php

namespace App\Domain\Tax;

use DomainException;

/**
 * The document names a withholding code that is not in the rate table.
 *
 * Deliberately fatal rather than falling back to zero. Under-withholding does
 * not fail loudly at the time — it surfaces as a liability when the return is
 * filed, which is far too late to find it.
 */
class UnknownWithholdingCode extends DomainException {}
