<?php

namespace App\Domain\Closeout;

/**
 * What the build can check before it accepts a signature on a checklist line.
 *
 * Slide 9's rule — "named clearers per line, not a status flag" — stops a
 * status flag. It does not stop a signature that is not true, and on the last
 * day of a project everybody wants the line signed. So a line whose fact this
 * system already holds names the evidence here, and clearing it is refused
 * while that fact is false. The person still signs; nobody is replaced by a
 * query.
 *
 * `Manual` is the honest name for the rest: a line real enough to be on the
 * checklist and not yet backed by anything the build can read. It gets slide
 * 9's baseline — a name, a time and a note.
 *
 * P5-09 took two lines out of that category, which is the seam working as
 * intended: the final P&L and the filed scorecards became facts the build
 * holds, so `final_account_filed` and `scorecards_filed` replaced `manual` in
 * the config and nothing else moved.
 */
enum CloseOutEvidence: string
{
    case Manual = 'manual';
    case TurnoverAccepted = 'turnover_accepted';
    case WarrantiesRegistered = 'warranties_registered';
    case PermitsOnFile = 'permits_on_file';
    case FinalBillingRaised = 'final_billing_raised';
    case InvoicesCollected = 'invoices_collected';
    case RetentionCollected = 'retention_collected';
    case DemobilizationComplete = 'demobilization_complete';
    case FinalAccountFiled = 'final_account_filed';
    case ScorecardsFiled = 'scorecards_filed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isChecked(): bool
    {
        return $this !== self::Manual;
    }
}
