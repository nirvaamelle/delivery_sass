<?php

namespace App\Domain\Approvals;

use App\Models\Approval;
use App\Models\ApprovalMatrix;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The authority matrix, made executable — PLAN.md §3.
 *
 * Reads slide 5's four tiers, routes a document to the approvers its amount
 * calls for, escalates a sole-source purchase one level above, and records who
 * approved what and when. Every approval in every chain lands in one table, so
 * the result is one inbox rather than seven.
 *
 * PLACEHOLDER: Part D item 1 — the deck shows bands rather than amounts on
 * purpose. This class holds the SHAPE of the routing, which is settled; the
 * thresholds it routes against are configuration and stay provisional until B2
 * is answered.
 *
 * Amounts are compared with bccomp throughout. Comparing money as floats is how
 * a purchase sitting exactly on a threshold gets approved one tier too low.
 */
class ApprovalRouter
{
    private const SCALE = 4;

    /**
     * Define one tier of the matrix for a document type.
     *
     * @param  array<int, string>  $approverRoles  ordered — step 1 signs first
     * @param  array<int, string>  $requiredDocuments
     *
     * @throws OverlappingTierException when the band collides with an existing tier
     */
    public function defineTier(
        string $documentType,
        int $tier,
        string $minAmount,
        ?string $maxAmount,
        array $approverRoles,
        array $requiredDocuments,
    ): ApprovalMatrix {
        $this->assertNoOverlap($documentType, $tier, $minAmount, $maxAmount);

        return ApprovalMatrix::query()->updateOrCreate(
            ['document_type' => $documentType, 'tier' => $tier],
            [
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'approver_roles' => array_values($approverRoles),
                'required_documents' => array_values($requiredDocuments),
            ]
        );
    }

    /**
     * The tier whose band contains this amount.
     *
     * @throws NoApprovalTierException when no band contains it
     */
    public function tierFor(string $documentType, string $amount): ApprovalMatrix
    {
        $match = ApprovalMatrix::query()
            ->where('document_type', $documentType)
            ->orderBy('tier')
            ->get()
            ->first(fn (ApprovalMatrix $row): bool => $this->bandContains($row, $amount));

        if ($match === null) {
            throw new NoApprovalTierException(sprintf(
                'No approval tier is defined for a %s of %s. A gap in the bands is a configuration error, not a reason to route to the lowest authority.',
                $documentType,
                $amount
            ));
        }

        return $match;
    }

    /**
     * The tier that will actually approve, sole-source escalation included.
     *
     * Slide 5: a sole-source purchase needs written justification approved one
     * level up. At the top tier there is no level above, so it stays — stated
     * here so it reads as a decision rather than an oversight.
     *
     * @throws NoApprovalTierException when no band contains the amount
     */
    public function routeFor(string $documentType, string $amount, bool $soleSource = false): ApprovalMatrix
    {
        $tier = $this->tierFor($documentType, $amount);

        if (! $soleSource) {
            return $tier;
        }

        $escalated = ApprovalMatrix::query()
            ->where('document_type', $documentType)
            ->where('tier', '>', $tier->tier)
            ->orderBy('tier')
            ->first();

        return $escalated ?? $tier;
    }

    /**
     * Open the pending approval steps for a document.
     *
     * One row per approver role, in the order the matrix lists them, so the
     * inbox can show whose signature is outstanding rather than just that
     * "approval is pending".
     *
     * @return Collection<int, Approval>
     *
     * @throws NoApprovalTierException when no band contains the amount
     */
    public function request(
        Model $document,
        string $documentType,
        string $amount,
        bool $soleSource = false,
    ): Collection {
        $matrix = $this->routeFor($documentType, $amount, $soleSource);

        return DB::transaction(function () use ($document, $documentType, $matrix, $soleSource): Collection {
            $steps = collect();

            foreach (array_values($matrix->approver_roles) as $index => $role) {
                $steps->push(Approval::query()->create([
                    'approvable_type' => $document->getMorphClass(),
                    'approvable_id' => $document->getKey(),
                    'document_type' => $documentType,
                    'tier' => $matrix->tier,
                    'step' => $index + 1,
                    'approver_role' => $role,
                    'decision' => ApprovalDecision::Pending,
                    'sole_source' => $soleSource,
                ]));
            }

            return $steps;
        });
    }

    /**
     * Record an approval.
     *
     * @throws ApproverLacksAuthorityException when the user does not hold the
     *                                         role the step names
     */
    public function approve(Approval $approval, User $user, ?string $remarks = null): Approval
    {
        $this->assertAuthority($approval, $user);

        return $this->decide($approval, $user, ApprovalDecision::Approved, $remarks);
    }

    /**
     * Send the document back for revision, with the reason attached.
     *
     * The reason is required, not optional. A document returned without one
     * cannot be corrected reliably, and in the billing chain the reason is what
     * stops the same deducted item reappearing on the next submission.
     *
     * @throws ApproverLacksAuthorityException when the user does not hold the
     *                                         role the step names
     */
    public function returnForRevision(Approval $approval, User $user, string $reason): Approval
    {
        $this->assertAuthority($approval, $user);

        return $this->decide($approval, $user, ApprovalDecision::Returned, $reason);
    }

    private function decide(
        Approval $approval,
        User $user,
        ApprovalDecision $decision,
        ?string $remarks,
    ): Approval {
        $approval->forceFill([
            'approver_user_id' => $user->getKey(),
            'decision' => $decision,
            'decided_at' => now(),
            'remarks' => $remarks,
        ])->save();

        return $approval->refresh();
    }

    /**
     * @throws ApproverLacksAuthorityException
     */
    private function assertAuthority(Approval $approval, User $user): void
    {
        if (! $user->hasRole($approval->approver_role)) {
            throw new ApproverLacksAuthorityException(sprintf(
                'This step requires the "%s" role; the approver does not hold it.',
                $approval->approver_role
            ));
        }
    }

    private function bandContains(ApprovalMatrix $row, string $amount): bool
    {
        if (bccomp($amount, (string) $row->min_amount, self::SCALE) < 0) {
            return false;
        }

        // A null ceiling is the open-ended top band.
        if ($row->max_amount === null) {
            return true;
        }

        return bccomp($amount, (string) $row->max_amount, self::SCALE) <= 0;
    }

    /**
     * @throws OverlappingTierException
     */
    private function assertNoOverlap(
        string $documentType,
        int $tier,
        string $minAmount,
        ?string $maxAmount,
    ): void {
        $existing = ApprovalMatrix::query()
            ->where('document_type', $documentType)
            ->where('tier', '!=', $tier)
            ->get();

        foreach ($existing as $row) {
            if ($this->overlaps($minAmount, $maxAmount, (string) $row->min_amount, $row->max_amount)) {
                throw new OverlappingTierException(sprintf(
                    'Tier %d (%s to %s) overlaps tier %d (%s to %s) for %s.',
                    $tier,
                    $minAmount,
                    $maxAmount ?? 'unbounded',
                    $row->tier,
                    $row->min_amount,
                    $row->max_amount ?? 'unbounded',
                    $documentType
                ));
            }
        }
    }

    /**
     * Two bands overlap unless one ends strictly before the other begins.
     */
    private function overlaps(
        string $minA,
        ?string $maxA,
        string $minB,
        ?string $maxB,
    ): bool {
        $aEndsBeforeB = $maxA !== null && bccomp($maxA, $minB, self::SCALE) < 0;
        $bEndsBeforeA = $maxB !== null && bccomp($maxB, $minA, self::SCALE) < 0;

        return ! ($aEndsBeforeB || $bEndsBeforeA);
    }
}
