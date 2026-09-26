{{--
    Slide 8's consolidation outputs, on one page.

    Three questions per project, kept visually separate because they are
    separate questions and a reader answers one at a time:

      · the P&L — what the month EARNED and COST
      · cost per point of accomplishment — whether the work is running to plan
      · the cash requirement — what NEXT month needs

    F16 exists because the third was missing while the first was not, so it is
    given equal weight here rather than tucked into a footer.

    **Why this page carries its own CSS.** Filament ships a PRECOMPILED
    stylesheet containing only the classes Filament itself uses. Arbitrary
    Tailwind utilities written in a custom page — `grid-cols-2`, `text-end`,
    `py-1` — are not in it and silently do nothing, which is exactly how this
    page came to render as unaligned running text. Compiling a custom theme
    would work but adds a build step to every deploy for one screen. Real CSS,
    scoped to this page and drawn from Filament's own custom properties, keeps
    the palette and the dark theme correct without one.
--}}
<x-filament-panels::page>

    @php
        /** Money as a finance reader expects it: symbol, grouped, two places. */
        $peso = fn (string|float|null $amount): string => '₱'.number_format((float) $amount, 2);
        /** Costs in accounting parentheses, so a negative is never a stray dash. */
        $bracket = fn (string|float|null $amount): string => '('.number_format((float) $amount, 2).')';
    @endphp

    <style>
        .pnl { --pnl-rule: var(--color-gray-200); --pnl-muted: var(--color-gray-500); --pnl-strong: var(--color-gray-950); }
        .dark .pnl { --pnl-rule: rgba(255, 255, 255, .1); --pnl-muted: var(--color-gray-400); --pnl-strong: #fff; }

        /* ---- portfolio line ------------------------------------------- */
        .pnl-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
            gap: 1.5rem 2rem;
        }
        .pnl-summary dt {
            font-size: .6875rem;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--pnl-muted);
        }
        .pnl-summary dd { margin: .25rem 0 0; }
        .pnl-summary .pnl-figure {
            font-size: 1.5rem;
            font-weight: 600;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
            color: var(--pnl-strong);
        }
        .pnl-summary .pnl-caption { font-size: .75rem; color: var(--pnl-muted); margin-top: .125rem; }

        /* ---- the two columns ------------------------------------------ */
        .pnl-cols { display: grid; gap: 2rem; }
        @media (min-width: 1024px) { .pnl-cols { grid-template-columns: 1fr 1fr; gap: 2.5rem; } }

        .pnl-h3 {
            font-size: .8125rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--pnl-muted);
            margin: 0 0 .625rem;
        }
        .pnl-h3 + .pnl-h3, .pnl-table + .pnl-h3 { margin-top: 1.75rem; }

        /* ---- figure tables -------------------------------------------- */
        .pnl-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .pnl-table th,
        .pnl-table td { padding: .3125rem 0; vertical-align: baseline; }
        .pnl-table th { text-align: start; font-weight: 400; color: var(--pnl-strong); }
        .pnl-table td {
            text-align: end;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            padding-left: 1rem;
            color: var(--pnl-strong);
        }

        .pnl-table .pnl-indent th { padding-inline-start: 1rem; color: var(--pnl-muted); }
        .pnl-table .pnl-indent td { color: var(--pnl-muted); }

        .pnl-table .pnl-sub th,
        .pnl-table .pnl-sub td { border-top: 1px solid var(--pnl-rule); }

        .pnl-table .pnl-total th,
        .pnl-table .pnl-total td {
            border-top: 2px solid var(--pnl-rule);
            padding-top: .5rem;
            font-weight: 600;
            color: var(--pnl-strong);
        }

        .pnl-note { font-size: .75rem; color: var(--pnl-muted); font-weight: 400; }

        /* Filament exposes no --color-success-600; it exposes the CLASS
           fi-color-success, which remaps the generic --color-* ramp. Pairing
           that class with var(--color-600) keeps this page on whatever palette
           the panel is configured with.

           The selectors are deliberately specific. `.pnl-table td` (0,1,1) and
           `.pnl-summary .pnl-figure` (0,2,0) both set a colour, and a bare
           `.pnl-pos` (0,1,0) loses to both — the variable resolved correctly and
           the text still came out near-black. These win outright. */
        .pnl .pnl-figure.pnl-pos,
        .pnl .pnl-figure.pnl-neg,
        .pnl-table td.pnl-pos,
        .pnl-table td.pnl-neg { color: var(--color-600); }

        .dark .pnl .pnl-figure.pnl-pos,
        .dark .pnl .pnl-figure.pnl-neg,
        .dark .pnl-table td.pnl-pos,
        .dark .pnl-table td.pnl-neg { color: var(--color-400); }

        /* ---- retention, deliberately outside the net ------------------ */
        .pnl-aside {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 1.5rem;
            margin-top: .875rem;
            padding-top: .875rem;
            border-top: 1px dashed var(--pnl-rule);
            font-size: .8125rem;
            color: var(--pnl-muted);
        }
        .pnl-aside span:last-child { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pnl-aside small { display: block; font-size: .6875rem; margin-top: .125rem; }

        .pnl-code { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: .8125rem; color: var(--pnl-muted); }

        .pnl-empty { text-align: center; padding: 1.5rem 0; }
        .pnl-empty p { margin: 0; font-size: .875rem; }
        .pnl-empty p:first-child { font-weight: 500; color: var(--pnl-strong); }
        .pnl-empty p:last-child { color: var(--pnl-muted); margin-top: .25rem; }
    </style>

    <div class="pnl">

        {{-- Portfolio line. Answers "how did the company do" before the reader
             starts down the projects, so one bad project is read in context. --}}
        <x-filament::section>
            <x-slot name="heading">{{ $period }}</x-slot>
            <x-slot name="description">
                Summed from the project cost ledger across every project in view. Nothing here is stored.
            </x-slot>

            @php
                // A negative requirement is not a requirement: collections exceed
                // what has to go out. Saying "surplus" is the reader's answer;
                // a minus sign in a column of figures is the one thing that gets
                // missed.
                $cashSurplus = bccomp($totals['cash'], '0', 4) < 0;
            @endphp

            <dl class="pnl-summary">
                <div>
                    <dt>Revenue</dt>
                    <dd class="pnl-figure">{{ $peso($totals['revenue']) }}</dd>
                </div>

                <div>
                    <dt>Cost</dt>
                    <dd class="pnl-figure">{{ $peso($totals['cost']) }}</dd>
                </div>

                <div>
                    <dt>Gross profit</dt>
                    <dd class="pnl-figure {{ bccomp($totals['gross_profit'], '0', 4) >= 0 ? 'fi-color-success pnl-pos' : 'fi-color-danger pnl-neg' }}">
                        {{ $peso($totals['gross_profit']) }}
                    </dd>
                    {{-- "Nothing billed" and "no margin" are different statements. --}}
                    <dd class="pnl-caption">
                        {{ $totals['margin_percent'] === null ? 'Nothing billed this month' : $totals['margin_percent'].'% margin' }}
                    </dd>
                </div>

                <div>
                    <dt>{{ $cashSurplus ? 'Cash surplus' : 'Cash required' }}</dt>
                    <dd class="pnl-figure">{{ $peso($cashSurplus ? bcmul($totals['cash'], '-1', 4) : $totals['cash']) }}</dd>
                    <dd class="pnl-caption">
                        {{ $cashSurplus ? 'Collections exceed next month\'s outgoings' : 'Next month, net of collections' }}
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        @forelse ($rows as $row)
            @php
                $pnl = $row['pnl'];
                $cash = $row['cash'];
                $unit = $row['unit'];
                $profitable = bccomp($pnl['gross_profit'], '0', 4) >= 0;
            @endphp

            <x-filament::section>
                <x-slot name="heading">
                    <span class="pnl-code">{{ $row['project']->code }}</span>
                    {{ $row['project']->name }}
                </x-slot>

                <x-slot name="afterHeader">
                    <x-filament::badge :color="$profitable ? 'success' : 'danger'">
                        {{ $pnl['margin_percent'] }}% margin
                    </x-filament::badge>
                </x-slot>

                <div class="pnl-cols">

                    {{-- What the month earned and cost. --}}
                    <div>
                        <h3 class="pnl-h3">Profit and loss</h3>

                        <table class="pnl-table">
                            <tbody>
                                <tr>
                                    <th scope="row">Revenue</th>
                                    <td>{{ $peso($pnl['revenue']) }}</td>
                                </tr>

                                @foreach (['material' => 'Material', 'subcontract' => 'Subcontract', 'labor' => 'Labour', 'overhead' => 'Overhead'] as $key => $label)
                                    <tr class="pnl-indent">
                                        <th scope="row">{{ $label }}</th>
                                        <td>{{ $bracket($pnl[$key]) }}</td>
                                    </tr>
                                @endforeach

                                <tr class="pnl-sub pnl-indent">
                                    <th scope="row">Total cost</th>
                                    <td>{{ $bracket($pnl['total_cost']) }}</td>
                                </tr>

                                <tr class="pnl-total">
                                    <th scope="row">Gross profit</th>
                                    <td class="{{ $profitable ? 'fi-color-success pnl-pos' : 'fi-color-danger pnl-neg' }}">{{ $peso($pnl['gross_profit']) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        {{-- PLAN.md §7 step 9. Life to date, not for the month: one
                             month's cost against the whole project's percentage is a
                             meaningless ratio. "Nothing verified" rather than zero —
                             zero cost per point would read as free work. --}}
                        <h3 class="pnl-h3">Cost per 1% accomplished</h3>

                        <table class="pnl-table">
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        Actual
                                        <span class="pnl-note">· {{ $unit['verified_percent'] }}% verified, life to date</span>
                                    </th>
                                    <td>
                                        @if ($unit['measurable'])
                                            {{ $peso($unit['cost_per_percent']) }}
                                        @else
                                            <span class="pnl-note">Nothing verified yet</span>
                                        @endif
                                    </td>
                                </tr>

                                <tr class="pnl-indent">
                                    <th scope="row">Budgeted</th>
                                    <td>
                                        {{ $unit['budgeted_cost_per_percent'] === null ? 'No open budget' : $peso($unit['budgeted_cost_per_percent']) }}
                                    </td>
                                </tr>

                                @if ($unit['variance_percent'] !== null)
                                    @php $overrunning = bccomp((string) $unit['variance_percent'], '0', 2) > 0; @endphp
                                    <tr class="pnl-total">
                                        <th scope="row">Against plan</th>
                                        <td class="{{ $overrunning ? 'fi-color-danger pnl-neg' : 'fi-color-success pnl-pos' }}">
                                            {{ $overrunning ? '+' : '' }}{{ $unit['variance_percent'] }}%
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>

                    {{-- What next month needs. F16: this was the missing half. --}}
                    <div>
                        <h3 class="pnl-h3">Cash requirement</h3>

                        <table class="pnl-table">
                            <tbody>
                                <tr>
                                    <th scope="row">Committed on orders</th>
                                    <td>{{ $peso($cash['committed']) }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">Open payables</th>
                                    <td>{{ $peso($cash['payables']) }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">Approved payroll</th>
                                    <td>{{ $peso($cash['payroll']) }}</td>
                                </tr>
                                <tr class="pnl-indent">
                                    <th scope="row">Expected collections</th>
                                    <td>{{ $bracket($cash['expected_collections']) }}</td>
                                </tr>

                                @php $surplus = bccomp($cash['net_requirement'], '0', 4) < 0; @endphp
                                <tr class="pnl-total">
                                    <th scope="row">{{ $surplus ? 'Net surplus' : 'Net requirement' }}</th>
                                    <td>{{ $peso($surplus ? bcmul($cash['net_requirement'], '-1', 4) : $cash['net_requirement']) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        {{-- Reported, and deliberately NOT in the net: the client holds
                             it until the defects liability period ends, so counting it
                             as cash would overstate what can actually be spent. --}}
                        <div class="pnl-aside">
                            <span>
                                Retention held
                                <small>Owed to us, not collectible until the defects period ends</small>
                            </span>
                            <span>{{ $peso($cash['retention_held']) }}</span>
                        </div>
                    </div>

                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <div class="pnl-empty">
                    <p>No projects to report on</p>
                    <p>Open a project, and its consolidation appears here once costs are posted.</p>
                </div>
            </x-filament::section>
        @endforelse

    </div>

</x-filament-panels::page>
