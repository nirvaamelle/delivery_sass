<?php

use App\Domain\Hris\ContractStatus;
use App\Domain\Hris\PayBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('manpower_requisition_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('number')->unique();
            $table->enum('status', ContractStatus::values());

            $table->date('effective_from');
            // Null means open-ended. Project-based hiring runs on fixed terms,
            // and somebody still on site after theirs expired is working
            // uncovered — the same failure as an expired permit.
            $table->date('effective_to')->nullable();

            $table->enum('pay_basis', PayBasis::values());

            /*
             * The agreed rate, encrypted like every other rate in this build —
             * PLAN.md §3, in the first migration that creates the column.
             */
            $table->text('rate');

            $table->string('position')->nullable();

            /*
             * Slide 7's rule lives on these two columns: signed BEFORE the first
             * shift. Null means unsigned, and the gate reads that.
             */
            $table->date('signed_on')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('signed_by')->nullable();
            $table->foreignId('witnessed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One contract per employee per start date. Two covering the same
            // day means two sets of terms, and payroll reads whichever returns
            // first.
            $table->unique(['employee_id', 'effective_from'], 'employment_contracts_start_unique');
            $table->index(['employee_id', 'status']);
        });

        DB::statement('ALTER TABLE employment_contracts
             ADD CONSTRAINT employment_contracts_term_ordered
             CHECK (effective_to IS NULL OR effective_to >= effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_contracts');
    }
};
