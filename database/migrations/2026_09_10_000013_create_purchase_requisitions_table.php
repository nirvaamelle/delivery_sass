<?php

use App\Domain\Requisitions\RequisitionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            // From the Numbering service — gapless, per year. Unique here as
            // well as counted there: the counter guarantees no duplicate is
            // issued, this guarantees none is ever stored.
            $table->string('number')->unique();

            $table->enum('status', RequisitionStatus::values())
                ->default(RequisitionStatus::Draft->value);

            $table->decimal('total_amount', 18, 4)->default('0.0000');

            $table->foreignId('raised_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisitions');
    }
};
