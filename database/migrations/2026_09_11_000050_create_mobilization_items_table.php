<?php

use App\Domain\Mobilization\ChecklistItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobilization_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mobilization_id')->constrained()->cascadeOnDelete();

            $table->enum('item', ChecklistItem::values());
            $table->boolean('required');

            /*
             * Null means outstanding. A boolean `done` column would lose WHEN,
             * and the question asked after an incident is when the safety
             * officer was actually assigned, not whether a box is ticked today.
             */
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();
            $table->timestamps();

            // One row per item per mobilization. A duplicate would let the
            // completion check pass on a list that is short one real item.
            $table->unique(['mobilization_id', 'item'], 'mobilization_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobilization_items');
    }
};
