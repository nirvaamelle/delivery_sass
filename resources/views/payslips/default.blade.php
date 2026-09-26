{{--
    The payslip — slide 7's step 8 output.

    Deliberately plain: no images, no remote fonts, no stylesheets fetched at
    render time. Dompdf runs with remote fetching disabled, and a template that
    needed it would be a template that can reach the network while rendering
    somebody's pay.

    The day table is here because "days paid" alone cannot be checked by the
    person holding the payslip. A carried day is LABELLED — otherwise it shows as
    a day from a month they were already paid for, and they are right to query it.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $run_number }} — {{ $employee_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 3px 4px; border-bottom: 1px solid #ddd; }
        .num { text-align: right; }
        .totals td { border-bottom: none; }
        .grand { font-weight: bold; border-top: 1px solid #111; }
        .carried { color: #7a4a00; }
    </style>
</head>
<body>
    <h1>Payslip</h1>
    <div class="muted">
        {{ $run_number }} &middot; {{ $period_start }} to {{ $period_end }}
    </div>

    <table>
        <tr>
            <td>{{ $employee_name }}</td>
            <td class="num">{{ $employee_number }}</td>
        </tr>
        <tr>
            <td class="muted">{{ $position }}</td>
            <td class="num muted">
                {{ $days_paid }} {{ \Illuminate\Support\Str::plural('day', $days_paid) }} paid
                @if ($carried_days > 0)
                    &middot; <span class="carried">{{ $carried_days }} carried from an earlier cutoff</span>
                @endif
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th class="num">Regular</th>
                <th class="num">Overtime</th>
                <th class="num">Night</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($days as $day)
                <tr>
                    <td>
                        {{ $day['date'] }}
                        @if ($day['carried'])
                            <span class="carried">(carried)</span>
                        @endif
                    </td>
                    <td class="num">{{ $day['hours_regular'] }}</td>
                    <td class="num">{{ $day['hours_overtime'] }}</td>
                    <td class="num">{{ $day['hours_night'] }}</td>
                    <td class="num">{{ $day['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Basic pay</td><td class="num">{{ $basic_pay }}</td></tr>
        <tr><td>Overtime</td><td class="num">{{ $overtime_pay }}</td></tr>
        <tr><td>Night differential</td><td class="num">{{ $night_differential_pay }}</td></tr>
        @if (bccomp($leave_pay, '0', 4) !== 0)
            <tr><td>Paid leave ({{ $leave_days }} days)</td><td class="num">{{ $leave_pay }}</td></tr>
        @endif
        @if (bccomp($taxable_allowances, '0', 4) !== 0)
            <tr><td>Taxable allowances</td><td class="num">{{ $taxable_allowances }}</td></tr>
        @endif
        <tr class="grand"><td>Gross pay</td><td class="num">{{ $gross_pay }}</td></tr>

        <tr><td>SSS</td><td class="num">-{{ $sss }}</td></tr>
        <tr><td>PhilHealth</td><td class="num">-{{ $philhealth }}</td></tr>
        <tr><td>Pag-IBIG</td><td class="num">-{{ $pagibig }}</td></tr>
        <tr><td>Withholding tax</td><td class="num">-{{ $withholding_tax }}</td></tr>
        <tr><td>Total deductions</td><td class="num">-{{ $total_deductions }}</td></tr>

        @if (bccomp($non_taxable_allowances, '0', 4) !== 0)
            <tr><td>Non-taxable allowances</td><td class="num">+{{ $non_taxable_allowances }}</td></tr>
        @endif
        <tr class="grand"><td>Net pay</td><td class="num">{{ $net_pay }}</td></tr>
    </table>
</body>
</html>
