<?php

namespace App\Domain\Equipment;

use DomainException;

/**
 * The machine cannot be depreciated — it is not owned, or it has no depreciable
 * base left.
 */
class NotDepreciableException extends DomainException {}
