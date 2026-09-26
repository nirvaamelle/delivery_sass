<?php

/*
|--------------------------------------------------------------------------
| Audit-trail fixtures — P6-02
|--------------------------------------------------------------------------
*/

use App\Domain\Audit\ActivityLogExporter;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

function activityExport(): ActivityLogExporter
{
    return app(ActivityLogExporter::class);
}

/**
 * A real logged change, written through the model's own audit trail rather
 * than inserted — a fixture that wrote an `activity_log` row directly would
 * prove the exporter can read rows nothing in the app produces.
 */
function loggedChange(?User $causer = null, string $description = 'Renamed'): User
{
    $causer = $causer ?? User::factory()->create();
    auth()->login($causer);

    $subject = User::factory()->create(['name' => 'Before']);
    $subject->update(['name' => $description]);

    return $causer;
}

/**
 * The written file, split into lines with the trailing blank dropped.
 *
 * @return array<int, string>
 */
function exportLines(string $path): array
{
    $contents = Storage::disk('local')->get($path);

    return array_values(array_filter(explode("\n", (string) $contents), fn (string $line): bool => trim($line) !== ''));
}

/**
 * Would a spreadsheet evaluate this cell as a formula?
 *
 * The unit is the CELL, not the row: a payload sitting inside a JSON blob whose
 * cell begins with a brace is text, and the guard deliberately leaves it alone
 * rather than corrupting JSON an auditor may want to parse.
 */
function formulaLead(string $cell): bool
{
    if ($cell === '') {
        return false;
    }

    return in_array($cell[0], ['=', '+', '@'], true)
        || ($cell[0] === '-' && preg_match('/^[+-]?[\d,.]+$/', $cell) !== 1);
}
