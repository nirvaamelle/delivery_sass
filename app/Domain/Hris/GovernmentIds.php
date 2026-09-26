<?php

namespace App\Domain\Hris;

/**
 * Philippine government identification numbers, checked by digit count.
 *
 * The main value of an employee form is catching a typo before it reaches a
 * remittance file, where a wrong SSS or PhilHealth number means contributions
 * credited to nobody. Dashes and spaces are allowed and the value is stored as
 * entered; only the digits are counted.
 *
 * Deliberately a digit-count check, not a checksum. The agencies' check-digit
 * rules are not published in a form this build can rely on, and a wrong
 * checksum rule would refuse real numbers — worse than accepting a typo that a
 * digit count would still catch most of the time.
 */
class GovernmentIds
{
    /**
     * field => [label, allowed digit counts]
     *
     * @var array<string, array{0: string, 1: array<int, int>}>
     */
    public const RULES = [
        'sss_number' => ['SSS', [10]],
        'philhealth_number' => ['PhilHealth', [12]],
        'pagibig_number' => ['Pag-IBIG', [12]],
        'tin' => ['TIN', [9, 12]],
    ];

    /**
     * What is wrong with a value, or null when it is fine or blank.
     *
     * Blank is not a problem here: a government number may be unknown at hire,
     * and on update a blank field means "unchanged".
     */
    public static function problemWith(string $field, mixed $value): ?string
    {
        if (! isset(self::RULES[$field]) || $value === null || trim((string) $value) === '') {
            return null;
        }

        [$label, $counts] = self::RULES[$field];
        $value = trim((string) $value);

        if (preg_match('/^[0-9\- ]+$/', $value) !== 1) {
            return sprintf('%s number may contain only digits, dashes and spaces.', $label);
        }

        $digits = strlen((string) preg_replace('/\D/', '', $value));

        if (! in_array($digits, $counts, true)) {
            return sprintf(
                '%s number must have %s digits; this one has %d.',
                $label,
                implode(' or ', $counts),
                $digits,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidEmployeeDetail for the first malformed government number
     */
    public static function assertValid(array $attributes): void
    {
        foreach (array_keys(self::RULES) as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $problem = self::problemWith($field, $attributes[$field]);

            if ($problem !== null) {
                throw new InvalidEmployeeDetail($field, $problem);
            }
        }
    }
}
