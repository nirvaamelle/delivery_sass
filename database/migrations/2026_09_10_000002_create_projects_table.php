<?php

use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // The project code is half of the handoff spine — PLAN.md §1: every
            // document carries the project code and the cost code. Unique at the
            // database, because two projects sharing a code merge two P&Ls.
            $table->string('code')->unique();
            $table->string('name');
            $table->string('client_name');

            // Enum columns, not free strings. PLAN.md §5 requires controls to
            // survive a queued job or a CSV import, neither of which passes
            // through an Eloquent cast.
            $table->enum('phase', ProjectPhase::values())
                ->default(ProjectPhase::ProjectAcquisition->value);
            $table->enum('status', ProjectStatus::values())
                ->default(ProjectStatus::Active->value);

            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
