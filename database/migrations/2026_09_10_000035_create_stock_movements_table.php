<?php

use App\Domain\Procurement\StockMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_card_id')->constrained()->cascadeOnDelete();

            $table->enum('type', StockMovementType::values());

            // Signed: receipts positive, issuances negative, adjustments either.
            // One column rather than in/out pairs, so the balance is a sum
            // rather than a difference somebody can get backwards.
            $table->decimal('quantity', 18, 4);

            // What caused it, polymorphic — a receiving report, an issuance, a
            // physical count. Every movement traces to a document.
            $table->string('source_document_type', 191)->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();

            $table->dateTime('moved_at');
            $table->foreignId('moved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['stock_card_id', 'moved_at']);
            $table->index(['source_document_type', 'source_document_id'], 'stock_movements_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
