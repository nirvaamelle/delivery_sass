<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            // PLACEHOLDER: Part D item 10 — multi-company or single company is
            // unanswered. Every scoped table carries organization_id from the
            // first migration, so the column is a label today and can become a
            // tenant boundary later without a data migration. Retrofitting the
            // column after the ledger has rows is the expensive path.
            $table->string('code')->unique();
            $table->string('name');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
