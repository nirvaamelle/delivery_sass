<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('close_out_checklists', function (Blueprint $table) {
            $table->id();

            // One per project. The close-out report is this document; two of
            // them is two reports, and the project closes over one of them.
            $table->foreignId('project_id')->unique()->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            $table->dateTime('opened_at');
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('close_out_checklists');
    }
};
