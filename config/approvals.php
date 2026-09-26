<?php

/*
|--------------------------------------------------------------------------
| The four-tier authority matrix
|--------------------------------------------------------------------------
|
| PLACEHOLDER: Part D item 1 — this is B2, the schedule's critical path. The
| deck shows BANDS, not amounts, deliberately, so every peso figure below is the
| build's own invention and the client is expected to overwrite all four.
|
| The figures live here rather than inside the seeder so that answering B2 is a
| CONFIGURATION change and not a code change. That matters more than it looks:
| the difference decides whether the client's answer takes an edit and a deploy
| or a conversation with a developer, and PHASE-PLAN.md Part D item 1 has been
| open since week one.
|
| Amounts are STRINGS. A float literal here would lose exactness before the
| router ever saw it, which defeats DECIMAL(18,4) downstream — and a purchase
| sitting exactly on a threshold, approved one tier too low, is the precise
| failure the matrix exists to prevent.
|
| `ceiling` of null means open-ended: the top tier has no upper limit, because a
| band above the highest authority is an amount nobody can approve.
|
| PLACEHOLDER: Part D item 5 — the role names are provisional too. The deck
| names roles for the seven acquisition steps but not the people, and item 5
| asks who actually owns each.
|
*/

return [

    /*
     * Which documents route through the matrix at all.
     */
    'document_types' => [
        'purchase_requisition',
        'purchase_order',
        'ap_voucher',
    ],

    'tiers' => [

        /*
         * Tier 1 takes ONE approver. PLAN.md §9 names approval fatigue at tier
         * one as a risk and states the mitigation explicitly: it stays a
         * single-canvass, one-working-day path. Adding a second signature here
         * is the change that quietly turns a one-day target into a one-week one.
         */
        [
            'tier' => 1,
            'floor' => '0.0000',
            'ceiling' => '50000.0000',
            'approver_roles' => ['project-manager'],
            'required_documents' => ['canvass'],
        ],

        [
            'tier' => 2,
            'floor' => '50000.0001',
            'ceiling' => '500000.0000',
            'approver_roles' => ['project-manager', 'procurement-head'],
            'required_documents' => ['canvass', 'abstract-of-canvass'],
        ],

        [
            'tier' => 3,
            'floor' => '500000.0001',
            'ceiling' => '5000000.0000',
            'approver_roles' => ['procurement-head', 'finance-manager'],
            'required_documents' => ['abstract-of-canvass', 'bid-tabulation'],
        ],

        [
            'tier' => 4,
            'floor' => '5000000.0001',
            'ceiling' => null,
            'approver_roles' => ['finance-manager', 'managing-director'],
            'required_documents' => ['abstract-of-canvass', 'bid-tabulation', 'board-approval'],
        ],

    ],

];
