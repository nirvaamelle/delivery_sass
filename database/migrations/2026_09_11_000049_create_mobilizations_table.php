<?php

use App\Domain\Mobilization\MobilizationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobilizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            /*
             * F2's gate, carried on the row: the purchase order this mobilized
             * against. Unique, because two mobilizations on one order is the
             * same crew mobilized twice on paper and both would be chargeable.
             *
             * Non-nullable — a mobilization with no order behind it is a crew
             * on site against nothing, which is the situation the gate exists
             * to prevent.
             */
            $table->foreignId('purchase_order_id')->unique()->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', MobilizationStatus::values());

            $table->date('mobilized_on');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobilizations');
    }
};
