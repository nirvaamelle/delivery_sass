<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bid_tabulations', function (Blueprint $table) {
            $table->id();

            // One tabulation per RFQ. A second would produce a second
            // recommendation for one solicitation, and nothing would say which
            // one the award followed.
            $table->foreignId('rfq_id')->unique()->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            $table->foreignId('recommended_vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->decimal('recommended_amount', 18, 4);

            // Evidence, not a cache. Recomputing this from today's rows would
            // answer a different question than "what was on the table when this
            // was decided" — quotes can be added to an RFQ after the fact.
            $table->unsignedSmallInteger('quotes_compared');
            $table->boolean('sole_source')->default(false);

            $table->dateTime('tabulated_at');
            $table->foreignId('tabulated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_tabulations');
    }
};
