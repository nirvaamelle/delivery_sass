<?php

namespace App\Domain\Billing;

use DomainException;

/**
 * The milestone's required document set is incomplete — F4.
 *
 * Its own type because the billing screen, an importer and a queued job all
 * need to tell "the evidence is not on file yet" apart from every other reason
 * a submission can be refused. The first is a QS's to-do list; the others are
 * errors.
 */
class MissingMilestoneDocumentsException extends DomainException {}
