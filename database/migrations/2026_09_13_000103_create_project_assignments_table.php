<?php

use App\Domain\Projects\ProjectRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who works on which project — the table PLAN.md §3's row-level policies
     * read, deferred here from P0-14 with the reason recorded on `User`.
     */
    public function up(): void
    {
        Schema::create('project_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            /*
             * CASCADE on the user, unlike almost every other foreign key in
             * this build. An assignment is not a record of anything that
             * happened — it is a live permission — so it should disappear with
             * the account rather than outlive it as a grant pointing at nobody.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('role', ProjectRole::values());

            $table->date('assigned_on');
            $table->foreignId('assigned_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One person, one role, one project. Two rows would count the
            // project twice in every scope subquery.
            $table->unique(['project_id', 'user_id'], 'project_assignments_person_unique');
            $table->index(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_assignments');
    }
};
