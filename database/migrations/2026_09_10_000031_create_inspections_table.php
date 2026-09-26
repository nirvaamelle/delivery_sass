<?php

use App\Domain\Procurement\InspectionVerdict;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();

            // One inspection per receipt. A second would produce two verdicts on
            // one delivery, and nothing would say which one stock and payment
            // followed.
            $table->foreignId('receiving_report_id')->unique()->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('verdict', InspectionVerdict::values());

            $table->dateTime('inspected_at');
            $table->foreignId('inspected_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
