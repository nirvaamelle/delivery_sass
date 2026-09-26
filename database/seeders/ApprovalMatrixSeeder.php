<?php

namespace Database\Seeders;

use App\Domain\Approvals\ApprovalRouter;
use Illuminate\Database\Seeder;

/**
 * The four-tier authority matrix — slide 5.
 *
 * The bands themselves now live in `config/approvals.php`, so answering B2
 * (PHASE-PLAN.md Part D item 1) is a configuration change rather than a code
 * change. That distinction decides whether the client's answer takes an edit or
 * a conversation with a developer, and the item has been open since week one.
 *
 * Seeded through `ApprovalRouter::defineTier()` rather than by direct insert, so
 * the overlap check runs here exactly as it will for the admin screen. A seeder
 * able to write a matrix the service would reject is a seeder that quietly
 * breaks routing — and it would break it for whatever numbers the client
 * eventually supplies, not just for these.
 */
class ApprovalMatrixSeeder extends Seeder
{
    public function run(): void
    {
        $router = app(ApprovalRouter::class);

        /** @var array<int, string> $documentTypes */
        $documentTypes = config('approvals.document_types', []);

        /** @var array<int, array{tier: int, floor: string, ceiling: ?string, approver_roles: array<int, string>, required_documents: array<int, string>}> $tiers */
        $tiers = config('approvals.tiers', []);

        foreach ($documentTypes as $documentType) {
            foreach ($tiers as $tier) {
                $router->defineTier(
                    $documentType,
                    $tier['tier'],
                    $tier['floor'],
                    $tier['ceiling'],
                    $tier['approver_roles'],
                    $tier['required_documents'],
                );
            }
        }
    }
}
