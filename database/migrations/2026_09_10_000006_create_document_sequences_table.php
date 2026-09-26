<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();

            // One counter row per document type per year. PLAN.md §3: the
            // number comes from this row under a lock, never from MAX(id) + 1
            // on the documents table.
            $table->string('document_type', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);

            $table->timestamps();

            // The unique key is what makes the row findable and what stops two
            // concurrent first-issues from creating two counters for one type.
            $table->unique(['document_type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
