<?php

use App\Domain\Hris\ManpowerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manpower_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', ManpowerStatus::values());

            $table->string('position');
            $table->unsignedSmallInteger('headcount');
            $table->date('required_by');

            $table->text('justification')->nullable();

            $table->foreignId('requested_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('approval_remarks')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manpower_requisitions');
    }
};
