<?php

use App\Domain\Vendors\ValidationVerdict;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_validation_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            $table->date('visited_at');
            $table->enum('verdict', ValidationVerdict::values());
            $table->text('findings')->nullable();

            $table->foreignId('visited_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Every visit is kept. A vendor that failed one and passed the next
            // has a history worth reading; overwriting keeps the flattering
            // half only.
            $table->index(['vendor_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_validation_visits');
    }
};
