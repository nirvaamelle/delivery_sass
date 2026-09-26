<?php

/*
|--------------------------------------------------------------------------
| Billing milestones and their required document sets
|--------------------------------------------------------------------------
|
| Slide 6's five milestones, each with its own trigger and — the part F4 is
| about — its own REQUIRED DOCUMENTS. The finding is that PLAN.md has
| `billing_milestones` but nothing storing what must be attached before
| submission opens, which leaves "no billing without a verified statement of
| accomplishment" as the only enforced gate and the other four document
| requirements on the honour system.
|
| Keyed by contract type because PHASE-PLAN.md Part D item 2 may change the
| milestones themselves. A lump-sum contract and a cost-plus one do not bill on
| the same schedule, and hard-coding slide 6's five would make the client's
| answer a code change.
|
| PLACEHOLDER: Part D item 2 — the client has not confirmed whether these five
| milestones apply to every contract, nor what a second contract type would
| look like. `default` is slide 6 verbatim. Percentages must total 100: a
| schedule that does not is a contract either under-billed or over-billed by
| construction, and nobody notices until the final billing does not balance.
|
*/

return [

    'contract_types' => [

        'default' => [

            [
                'code' => 'downpayment_30',
                'name' => '30% downpayment',
                'percentage' => '30.00',
                'trigger' => 'Contract signed, NTP issued',
                'accomplishment_threshold' => null,
                'documents' => [
                    ['key' => 'billing_form', 'label' => 'Billing form', 'required' => true],
                    ['key' => 'signed_contract', 'label' => 'Signed contract', 'required' => true],
                    ['key' => 'invoice_request', 'label' => 'Invoice request', 'required' => true],
                ],
            ],

            [
                'code' => 'progress_50',
                'name' => '50% progress',
                'percentage' => '20.00',
                'trigger' => 'Verified accomplishment reaches 50%',
                // The billing gate reads this: a progress milestone cannot be
                // billed on a percentage the site has not actually reached.
                'accomplishment_threshold' => '50.00',
                'documents' => [
                    ['key' => 'accomplishment_report', 'label' => 'Accomplishment report', 'required' => true],
                    ['key' => 'joint_survey', 'label' => 'Joint survey', 'required' => true],
                    ['key' => 'photos', 'label' => 'Progress photographs', 'required' => true],
                ],
            ],

            [
                'code' => 'progress_75',
                'name' => '75% progress',
                'percentage' => '25.00',
                'trigger' => 'Verified accomplishment reaches 75%',
                'accomplishment_threshold' => '75.00',
                'documents' => [
                    ['key' => 'accomplishment_report', 'label' => 'Accomplishment report', 'required' => true],
                    ['key' => 's_curve', 'label' => 'Updated S-curve', 'required' => true],
                ],
            ],

            [
                'code' => 'prefinal_95',
                'name' => '95% pre-final',
                'percentage' => '20.00',
                'trigger' => 'Substantial completion, punchlist issued',
                'accomplishment_threshold' => '95.00',
                'documents' => [
                    ['key' => 'punchlist', 'label' => 'Punchlist', 'required' => true],
                    ['key' => 'as_built_draft', 'label' => 'Draft as-built drawings', 'required' => true],
                ],
            ],

            [
                'code' => 'final_100',
                'name' => '100% final',
                'percentage' => '5.00',
                'trigger' => 'Punchlist cleared, turnover accepted',
                'accomplishment_threshold' => '100.00',
                'documents' => [
                    ['key' => 'certificate_of_completion', 'label' => 'Certificate of completion', 'required' => true],
                    ['key' => 'warranty', 'label' => 'Warranty', 'required' => true],
                    ['key' => 'clearances', 'label' => 'Clearances', 'required' => true],
                    // Optional, and here to prove the distinction is real: the
                    // completion rule must be able to tell "nice to have" from
                    // "submission does not open without it".
                    ['key' => 'turnover_photos', 'label' => 'Turnover photographs', 'required' => false],
                ],
            ],

        ],

    ],

    /*
     * Retention withheld on every billing, released after the defects liability
     * period — slide 6. Per-contract on the contract row; this is the default
     * a new contract starts from. PLACEHOLDER: Part D item 3.
     */
    'retention_rate' => '10.00',

];
