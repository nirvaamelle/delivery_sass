<?php

namespace App\Domain\Hris;

use App\Domain\Support\Money;
use DomainException;

/**
 * SSS, PhilHealth, Pag-IBIG and withholding — P3-08.
 *
 * PLACEHOLDER: Part D item 9 and B3. The tables live in config/payroll.php and
 * they are the build's approximation of published schedules, not an
 * accountant's. What this class owns is the SHAPE every such schedule shares,
 * and the three places payroll routines habitually get it wrong:
 *
 *   - the SSS salary credit is ROUNDED to its bracket, not truncated — truncating
 *     under-contributes on half of every bracket, and the shortfall is the
 *     employee's benefit, not the company's saving;
 *   - every base has a floor and a ceiling, and both bite;
 *   - withholding taxes the amount OVER a bracket floor, so landing exactly on
 *     the floor costs nothing.
 *
 * And one rule of ORDER: contributions come off first, and tax is computed on
 * what is left. Taxing gross withholds on money the employee never received.
 *
 * Every method takes MONTHLY compensation and returns the share for one cutoff,
 * because the contributions are monthly obligations and slide 7 pays twice a
 * month.
 */
class StatutoryCalculator
{
    /**
     * Monthly compensation projected from one semi-monthly cutoff's gross.
     *
     * PLACEHOLDER: B3 — a projection, because the contribution is monthly and the
     * second cutoff's pay is not known when the first is run. An SOP that
     * deducts the whole month in the second cutoff would use actual monthly pay.
     */
    public function monthlyCompensationFrom(string $cutoffGross): string
    {
        return Money::round(bcmul($cutoffGross, '2', Money::WORKING_SCALE));
    }

    public function sss(string $monthlyCompensation): string
    {
        $table = config('payroll.statutory.sss');

        $steps = bcdiv($monthlyCompensation, (string) $table['msc_step'], Money::WORKING_SCALE);
        $credit = bcmul(Money::round($steps, 0), (string) $table['msc_step'], Money::SCALE);
        $credit = $this->clamp($credit, (string) $table['msc_floor'], (string) $table['msc_ceiling']);

        return $this->perCutoff(Money::multiply($credit, (string) $table['employee_rate']));
    }

    public function philhealth(string $monthlyCompensation): string
    {
        $table = config('payroll.statutory.philhealth');

        $base = $this->clamp($monthlyCompensation, (string) $table['base_floor'], (string) $table['base_ceiling']);

        return $this->perCutoff(Money::multiply($base, (string) $table['employee_rate']));
    }

    public function pagibig(string $monthlyCompensation): string
    {
        $table = config('payroll.statutory.pagibig');

        $cap = (string) $table['max_fund_salary'];
        $base = bccomp($monthlyCompensation, $cap, Money::SCALE) > 0 ? $cap : $monthlyCompensation;

        return $this->perCutoff(Money::multiply($base, (string) $table['employee_rate']));
    }

    /**
     * Withholding on one cutoff's taxable pay.
     */
    public function withholding(string $taxableForCutoff): string
    {
        if (bccomp($taxableForCutoff, '0', Money::SCALE) <= 0) {
            return '0.0000';
        }

        $bracket = null;

        foreach (config('payroll.statutory.withholding_semi_monthly', []) as $row) {
            // Strictly OVER the floor. Tax applies to the excess, so an amount
            // sitting exactly on a floor stays in the bracket below.
            if (bccomp($taxableForCutoff, (string) $row['over'], Money::SCALE) > 0) {
                $bracket = $row;
            }
        }

        if ($bracket === null) {
            return '0.0000';
        }

        $excess = bcsub($taxableForCutoff, (string) $bracket['over'], Money::SCALE);

        return Money::sum((string) $bracket['fixed'], Money::multiply($excess, (string) $bracket['rate']));
    }

    /**
     * The whole deduction set for one cutoff's gross.
     *
     * @return array{sss: string, philhealth: string, pagibig: string, taxable: string, withholding: string, total: string}
     */
    public function breakdown(string $cutoffGross): array
    {
        $monthly = $this->monthlyCompensationFrom($cutoffGross);

        $sss = $this->sss($monthly);
        $philhealth = $this->philhealth($monthly);
        $pagibig = $this->pagibig($monthly);

        $contributions = Money::sum($sss, $philhealth, $pagibig);

        // Contributions first; tax on what is left.
        $taxable = bcsub($cutoffGross, $contributions, Money::SCALE);
        $taxable = bccomp($taxable, '0', Money::SCALE) < 0 ? '0.0000' : $taxable;

        $withholding = $this->withholding($taxable);

        return [
            'sss' => $sss,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'taxable' => $taxable,
            'withholding' => $withholding,
            'total' => Money::sum($contributions, $withholding),
        ];
    }

    /**
     * A monthly obligation, divided across the cutoffs it is deducted in.
     *
     * @throws DomainException when the configured split is not one this build knows
     */
    private function perCutoff(string $monthlyShare): string
    {
        $split = config('payroll.statutory.split', 'half_each_cutoff');

        if ($split !== 'half_each_cutoff') {
            // Refused rather than guessed: an unknown split that silently fell
            // back to halves would deduct the wrong amount from everybody.
            throw new DomainException(sprintf('Statutory split "%s" is not implemented.', $split));
        }

        return Money::round(bcdiv($monthlyShare, '2', Money::WORKING_SCALE));
    }

    private function clamp(string $value, string $floor, string $ceiling): string
    {
        if (bccomp($value, $floor, Money::SCALE) < 0) {
            return bcadd($floor, '0', Money::SCALE);
        }

        if (bccomp($value, $ceiling, Money::SCALE) > 0) {
            return bcadd($ceiling, '0', Money::SCALE);
        }

        return bcadd($value, '0', Money::SCALE);
    }
}
