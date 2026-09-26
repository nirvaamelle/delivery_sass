<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // Self-referencing WBS tree. Nullable parent = a root division.
            $table->foreignId('parent_id')->nullable()
                ->constrained('cost_codes')->restrictOnDelete();

            $table->string('code');
            $table->string('name');
            $table->timestamps();

            // Scoped, not global — Part D item 10 may make organizations a real
            // tenant boundary, and each tenant owns its own WBS.
            $table->unique(['organization_id', 'code']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_codes');
    }
};
