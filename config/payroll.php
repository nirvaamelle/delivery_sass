<?php

/*
|--------------------------------------------------------------------------
| Payroll rules
|--------------------------------------------------------------------------
|
| B3 on the schedule: "payroll rules must be the SOP's rules, not the deck's
| defaults, before the first real cutoff." B3 has NOT been issued. Everything in
| this file is therefore a default the client is expected to replace, and it is
| collected in one file for exactly that reason — so replacing it is an edit and
| a test run (P3-13), not a search through payroll code.
|
| This is more dangerous than the approval bands were. An approval band routed
| wrongly is embarrassing; a payroll rule applied wrongly is somebody's pay, and
| they notice on the day it is released.
|
| Every amount and rate is a STRING, for the same reason config/withholding.php
| uses strings: a float literal loses exactness before bcmath ever sees it.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Hours and premiums
    |----------------------------------------------------------------------
    |
    | PLACEHOLDER: Part D item 9 and B3. These are the Philippine Labor Code
    | defaults the deck's jurisdiction implies (Part D item 11 confirms the
    | jurisdiction). A company SOP may pay above them; it may not pay below.
    |
    */

    // The day past which hours become overtime.
    'standard_hours_per_day' => '8.00',

    // Premium on the hourly rate for overtime on an ordinary day: 25%.
    'overtime_premium' => '0.25',

    // Night differential on the hourly rate: 10%, for 22:00–06:00.
    'night_differential_rate' => '0.10',
    'night_window' => ['start' => '22:00', 'end' => '06:00'],

    /*
     * Working days a year, used to turn a monthly rate into a daily one. 261 is
     * a five-day week; a six-day site uses 313. Which one applies is exactly the
     * kind of rule only the SOP can settle.
     */
    'monthly_divisor' => '261',

    /*
    |----------------------------------------------------------------------
    | Cutoffs and release
    |----------------------------------------------------------------------
    |
    | PLACEHOLDER: Part D item 4 — the deck's own assumption, stated as one:
    | 1–15 and 16–end of month, DTR closing one day after cutoff, release
    | three working days after that.
    |
    */

    'cutoffs' => [
        ['start_day' => 1, 'end_day' => 15],
        ['start_day' => 16, 'end_day' => 'last'],
    ],

    'dtr_closes_days_after_cutoff' => 1,
    'release_working_days_after_close' => 3,

    /*
    |----------------------------------------------------------------------
    | Register variance — F10
    |----------------------------------------------------------------------
    |
    | PLACEHOLDER: B3. Slide 7 requires "variance against last cutoff explained
    | per project" and names no tolerance, so the default is zero: every
    | project whose labour cost moved is explained. A tolerance is a share of
    | the PREVIOUS cutoff's figure, and a variance exactly on it is within it.
    |
    */

    'variance' => [
        'tolerance_percent' => '0.00',
    ],

    /*
    |----------------------------------------------------------------------
    | Statutory contributions and withholding
    |----------------------------------------------------------------------
    |
    | PLACEHOLDER: Part D item 9 — "statutory deductions: jurisdiction and whose
    | computation tables we follow." Unanswered.
    |
    | These are the BUILD'S approximation of published Philippine schedules
    | current at the time of writing. They are not an accountant's tables and
    | must not be treated as one: the SSS bracket rounding, the PhilHealth floor
    | and ceiling and the BIR graduated table all change by circular, and the
    | client's payroll officer is the person who knows which circular they are
    | on. Monthly contributions are split evenly across the two cutoffs — also
    | a default; some employers deduct the whole amount in one cutoff.
    |
    */

    'statutory' => [

        'split' => 'half_each_cutoff',

        // Employee share of the monthly salary credit.
        'sss' => [
            'employee_rate' => '0.05',
            'msc_floor' => '5000.00',
            'msc_ceiling' => '35000.00',
            'msc_step' => '500.00',
        ],

        // Employee share of the monthly premium, on a floored and capped base.
        'philhealth' => [
            'employee_rate' => '0.025',
            'base_floor' => '10000.00',
            'base_ceiling' => '100000.00',
        ],

        // Employee share, on compensation capped at the maximum fund salary.
        'pagibig' => [
            'employee_rate' => '0.02',
            'max_fund_salary' => '10000.00',
        ],

        /*
         * The semi-monthly graduated withholding table, as [over, fixed, rate]:
         * tax is `fixed + rate × (taxable − over)` for the highest `over` the
         * taxable amount exceeds.
         */
        'withholding_semi_monthly' => [
            ['over' => '0.00', 'fixed' => '0.00', 'rate' => '0.00'],
            ['over' => '10417.00', 'fixed' => '0.00', 'rate' => '0.15'],
            ['over' => '16667.00', 'fixed' => '937.50', 'rate' => '0.20'],
            ['over' => '33333.00', 'fixed' => '4270.70', 'rate' => '0.25'],
            ['over' => '83333.00', 'fixed' => '16770.70', 'rate' => '0.30'],
            ['over' => '333333.00', 'fixed' => '91770.70', 'rate' => '0.35'],
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Benefits
    |----------------------------------------------------------------------
    |
    | PLACEHOLDER: benefits are not a PHASE-PLAN.md Part D item at all, so the
    | client has never been asked (DECISIONS-PENDING.md, unnumbered). These are
    | the Labor Code minimums plus the build's own assumptions:
    |
    |   - Service incentive leave: five days a leave year after twelve months of
    |     service, the year running from the hiring anniversary. No carry-over,
    |     no cash conversion of unused days.
    |   - 13th month: one twelfth of basic pay plus paid leave on approved and
    |     released registers in the year. Computed and reported, not paid out.
    |   - Allowances (rules in AllowanceService, nothing to configure): an
    |     amount per cutoff, paid in full for any cutoff in force, taxable ones
    |     into gross and non-taxable ones into net.
    |
    */

    'benefits' => [

        'service_incentive_leave' => [
            'days_per_year' => '5',
            'after_months_of_service' => 12,
        ],

        'thirteenth_month' => [
            // Per year, applied to the 13th month alone.
            'tax_exempt_ceiling' => '90000.00',
        ],

    ],

];
