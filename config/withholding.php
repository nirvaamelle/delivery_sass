<?php

/*
|--------------------------------------------------------------------------
| Expanded withholding tax rates
|--------------------------------------------------------------------------
|
| PLACEHOLDER: Part D item 14 — the client has not yet confirmed which EWT
| rates apply to supplier payments, nor whether creditable withholding is
| expected on client collections. These are the Philippine defaults implied by
| the deck's peso approval tiers and its SSS / PhilHealth / Pag-IBIG / BIR
| references, and slide 12 says every threshold in the deck is a default the
| client may overwrite.
|
| Rates are STRINGS, deliberately. A float literal here would lose exactness
| before the calculator ever saw the value, which defeats the whole point of
| DECIMAL(18,4) downstream. Six decimal places, so a rate can be expressed to
| the basis point.
|
| Correcting these after the ledger has rows means restating postings — this is
| the Part D item BUILD-LOOP.md calls the most urgent for exactly that reason.
|
*/

return [

    'rates' => [
        // Purchase of goods from a supplier.
        'goods' => '0.010000',

        // Purchase of services, including subcontracted works.
        'services' => '0.020000',

        // Professional fees — engineers, surveyors, consultants.
        'professional' => '0.100000',

        // Rental of equipment, plant or premises.
        'rental' => '0.050000',
    ],

    /*
    | The rate applied when a document names no code. Zero, not a guess: an
    | unstated rate must never be invented, and the Gates service is the right
    | place to require a code on documents that need one.
    */
    'default_code' => null,

];
