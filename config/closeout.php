<?php

/*
|--------------------------------------------------------------------------
| The turnover pack
|--------------------------------------------------------------------------
|
| Slide 9's "documents to close": as-built drawings and manuals, certificate of
| completion, warranty certificates and permits. One line per document, and the
| pack cannot be accepted while a required line is outstanding.
|
| Each line declares a SOURCE, and that is the part worth reading.
|
| `filed` — somebody puts a reference on file and signs for it. Slide 9's
| "named clearer per line", one level down from the checklist itself.
|
| `warranty_register` / `permit_register` — the line is answered by the register
| and cannot be filed by hand at all. A pack that let a clerk tick "warranty
| certificates: on file" while subcontracts sit in the register with no
| certificate against them is the honour system F4 objected to, rebuilt a phase
| later. So the tick is not offered.
|
| Keyed as a flat list rather than per contract type: unlike billing milestones,
| the deck does not vary the turnover pack by contract. If the client turns out
| to need a different pack per project type, this becomes a keyed template the
| way `config/billing.php` is, and the shape is already close.
|
| PLACEHOLDER: which permits specifically must be handed over is not in the
| deck — the same unanswered question `PermitService::hasAnyValid()` records for
| mobilization. The line therefore refuses only the case the build can be sure
| about: a project handing over with no permit on file at all.
|
*/

return [

    'turnover_pack' => [

        [
            'key' => 'as_built_drawings',
            'label' => 'As-built drawings',
            'required' => true,
            'source' => 'filed',
        ],

        [
            'key' => 'operation_manuals',
            'label' => 'Operation and maintenance manuals',
            'required' => true,
            'source' => 'filed',
        ],

        [
            'key' => 'certificate_of_completion',
            'label' => 'Certificate of completion',
            'required' => true,
            'source' => 'filed',
        ],

        [
            'key' => 'warranty_certificates',
            'label' => 'Warranty certificates',
            'required' => true,
            'source' => 'warranty_register',
        ],

        [
            'key' => 'permits',
            'label' => 'Permits and clearances',
            'required' => true,
            'source' => 'permit_register',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | The close-out checklist
    |--------------------------------------------------------------------------
    |
    | Slide 9's three panels, and its structural obligation: "close-out is a
    | checklist with named clearers per line, not a status flag."
    |
    | Every line takes a name, a time and a note. What varies is `evidence`:
    | a line whose fact the build already holds names it here, and the signature
    | is refused while that fact is false. `manual` means the build cannot check
    | it — the line still gets slide 9's baseline, and saying so out loud is
    | better than a check that only looks like one.
    |
    | P5-09 upgraded the two lines that were manual — the final P&L and the
    | scorecards — which is the seam working as intended: their evidence keys
    | replaced `manual` here and no code moved. One line remains manual, and
    | says so: nothing in the build knows whether the as-built drawings actually
    | reached the archive.
    |
    */

    'checklist' => [

        // Documents to close — PMO and the client.
        [
            'key' => 'turnover_accepted',
            'label' => 'Turnover pack accepted by the client',
            'panel' => 'documents',
            'evidence' => 'turnover_accepted',
        ],
        [
            'key' => 'warranty_certificates_registered',
            'label' => 'Warranty certificates registered',
            'panel' => 'documents',
            'evidence' => 'warranties_registered',
        ],
        [
            'key' => 'permits_handed_over',
            'label' => 'Permits and clearances handed over',
            'panel' => 'documents',
            'evidence' => 'permits_on_file',
        ],
        [
            'key' => 'as_built_records_archived',
            'label' => 'As-built drawings and manuals archived',
            'panel' => 'documents',
            'evidence' => 'manual',
        ],

        // Financial close — QS and Finance.
        [
            'key' => 'final_billing_raised',
            'label' => 'Final billing raised with deductions applied',
            'panel' => 'financial',
            'evidence' => 'final_billing_raised',
        ],
        [
            'key' => 'final_billing_collected',
            'label' => 'Final billing collected',
            'panel' => 'financial',
            'evidence' => 'invoices_collected',
        ],
        [
            'key' => 'retention_collected',
            'label' => 'Retention released and collected',
            'panel' => 'financial',
            'evidence' => 'retention_collected',
        ],
        [
            'key' => 'final_project_pl',
            'label' => 'Final project P&L and forecast to completion filed',
            'panel' => 'financial',
            'evidence' => 'final_account_filed',
        ],

        // People and assets — HR and Operations.
        [
            'key' => 'demobilization_complete',
            'label' => 'Site demobilized, plant and IT assets returned',
            'panel' => 'people_and_assets',
            'evidence' => 'demobilization_complete',
        ],
        [
            'key' => 'final_pay_cleared',
            'label' => 'Final pay cleared for everybody on the project',
            'panel' => 'people_and_assets',
            'evidence' => 'demobilization_complete',
        ],
        [
            'key' => 'scorecards_filed',
            'label' => 'Vendor and subcontractor scorecards filed',
            'panel' => 'people_and_assets',
            'evidence' => 'scorecards_filed',
        ],

    ],

];
