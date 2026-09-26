<?php

use App\Domain\Procurement\SubcontractStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcontracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', SubcontractStatus::values())
                ->default(SubcontractStatus::Awarded->value);

            $table->string('scope_of_work');
            $table->decimal('contract_amount', 18, 4);

            $table->date('works_start');
            $table->date('works_end');

            // The bond relied on at award, captured as a reference. Bonds get
            // renewed and replaced; this records which one was current when the
            // award decision was made.
            $table->foreignId('performance_bond_id')->nullable()
                ->constrained('vendor_bonds')->nullOnDelete();

            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE subcontracts
             ADD CONSTRAINT subcontracts_works_period_order
             CHECK (works_end >= works_start)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subcontracts');
    }
};
