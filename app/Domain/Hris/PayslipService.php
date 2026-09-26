<?php

namespace App\Domain\Hris;

use App\Models\PayrollLine;
use App\Models\PayrollLineDay;
use App\Models\Payslip;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Payslips — slide 7's step 8 output.
 *
 * **Rendered only from an APPROVED register.** A payslip is a statement of what
 * somebody is being paid; issued off a computed register it states a figure the
 * variance review might still change, and the employee has it in writing before
 * anybody decided it was right.
 *
 * **A carried day is labelled as carried.** This is where the held-day mechanic
 * from P3-05 finally reaches the person it was built for. Without the label the
 * payslip shows a day from a month they were already paid for, and the first
 * person to notice is the one holding it.
 *
 * Written to the private disk. The public disk would make one person's pay a URL.
 */
class PayslipService
{
    /**
     * Render the PDF and record it.
     *
     * @throws DomainException when the register has not been approved
     */
    public function render(PayrollLine $line): Payslip
    {
        $run = $line->run()->sole();

        if (! in_array($run->status, [PayrollRunStatus::Approved, PayrollRunStatus::Released], true)) {
            throw new DomainException(sprintf(
                'Payroll run %s is %s. A payslip issued off an unapproved register states a figure the review might still change.',
                $run->number,
                $run->status->value,
            ));
        }

        $summary = $this->summaryFor($line);

        $options = new Options;
        // No remote fetching: a payslip template must not be able to pull
        // anything over the network at render time.
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(View::make('payslips.default', $summary)->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        $path = sprintf('payslips/%s-%s.pdf', $run->number, $line->employee()->sole()->employee_number);

        Storage::disk('local')->put($path, (string) $dompdf->output());

        return Payslip::query()->updateOrCreate(
            ['payroll_line_id' => $line->getKey()],
            [
                'employee_id' => $line->employee_id,
                'file_path' => $path,
                'rendered_at' => now(),
            ],
        );
    }

    /**
     * Everything the payslip prints.
     *
     * Built here rather than in the template so the figures can be asserted in a
     * test without parsing a PDF — and so a second template renders the same
     * numbers rather than its own interpretation of them.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(PayrollLine $line): array
    {
        $run = $line->run()->sole();
        $employee = $line->employee()->sole();

        $days = PayrollLineDay::query()
            ->where('payroll_line_id', $line->getKey())
            ->orderBy('work_date')
            ->get();

        return [
            'run_number' => $run->number,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->fullName(),
            'position' => $employee->position,
            'days_paid' => $line->days_paid,
            'carried_days' => $line->carried_days,
            'basic_pay' => (string) $line->basic_pay,
            'overtime_pay' => (string) $line->overtime_pay,
            'night_differential_pay' => (string) $line->night_differential_pay,
            // Null on lines computed before benefits existed.
            'leave_pay' => (string) ($line->leave_pay ?? '0.0000'),
            'leave_days' => (string) ($line->leave_days ?? '0.0'),
            'taxable_allowances' => (string) ($line->taxable_allowances ?? '0.0000'),
            'non_taxable_allowances' => (string) ($line->non_taxable_allowances ?? '0.0000'),
            'gross_pay' => (string) $line->gross_pay,
            'sss' => (string) $line->sss_contribution,
            'philhealth' => (string) $line->philhealth_contribution,
            'pagibig' => (string) $line->pagibig_contribution,
            'withholding_tax' => (string) $line->withholding_tax,
            'total_deductions' => (string) $line->total_deductions,
            'net_pay' => (string) $line->net_pay,
            'days' => $days->map(fn (PayrollLineDay $day): array => [
                'date' => $day->work_date->toDateString(),
                'carried' => (bool) $day->carried,
                'hours_regular' => (string) $day->hours_regular,
                'hours_overtime' => (string) $day->hours_overtime,
                'hours_night' => (string) $day->hours_night,
                'amount' => (string) $day->amount,
            ])->all(),
        ];
    }
}
