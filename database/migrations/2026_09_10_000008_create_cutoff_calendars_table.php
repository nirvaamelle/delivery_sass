<?php

use App\Domain\Cutoffs\CutoffType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutoff_calendars', function (Blueprint $table) {
            $table->id();

            // NULL means the organization-wide calendar. A row naming a project
            // is that project's override — the per-project half of F3.
            // RESTRICT, not CASCADE: MySQL refuses a cascading foreign key on a
            // column that a stored generated column depends on, and project_key
            // below is generated from this one. Restrict is the better rule for
            // an ERP regardless - a project with a configured cutoff period is
            // closed, never deleted out from under its postings.
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();

            $table->enum('cutoff_type', CutoffType::values());

            // The period being closed…
            $table->date('period_start');
            $table->date('period_end');

            // …and the moment after which nothing may book into it. This is not
            // necessarily period_end: the OPEX cutoff falls on day 26, before
            // the month it closes has even finished.
            $table->dateTime('cutoff_at');

            $table->timestamps();

            // MySQL unique indexes treat every NULL as distinct, so a unique key
            // over project_id would happily accept two organization-wide rows
            // for one period — and an ambiguous cutoff is no cutoff. Uniqueness
            // is enforced over a generated column where the default rows collapse
            // onto a single value.
            $table->unsignedBigInteger('project_key')->storedAs('coalesce(project_id, 0)');
            $table->unique(['project_key', 'cutoff_type', 'period_start'], 'cutoff_calendars_period_unique');

            $table->index(['cutoff_type', 'period_start', 'period_end'], 'cutoff_calendars_lookup_index');
        });

        // Enforced by the database, not by a model hook: an inverted period
        // would silently match no document date at all, and the failure would
        // surface as "no calendar configured" months later.
        DB::statement(
            'ALTER TABLE cutoff_calendars
             ADD CONSTRAINT cutoff_calendars_period_order
             CHECK (period_end >= period_start)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cutoff_calendars');
    }
};
