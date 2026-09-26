<?php

use App\Domain\Equipment\EquipmentCostType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();

            /*
             * Non-nullable, both of them. PLAN.md §1: every document carries the
             * project code and the cost code. A fuel slip that carries neither
             * is a number in a report nobody can trace to a site.
             */
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            // The assignment that was live on the date — the record of WHY this
            // project is the one carrying the cost.
            $table->foreignId('equipment_assignment_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->enum('type', EquipmentCostType::values());
            $table->decimal('amount', 18, 4);
            $table->date('incurred_on');

            $table->string('reference')->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['equipment_id', 'incurred_on']);
            $table->index(['project_id', 'incurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_costs');
    }
};
