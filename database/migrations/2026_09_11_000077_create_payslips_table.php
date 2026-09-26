<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();

            // One payslip per line. Re-rendering overwrites the same row rather
            // than accumulating versions of one cutoff's statement.
            $table->foreignId('payroll_line_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            // Private disk, relative path. A payslip is one person's pay; the
            // public disk would make it a URL.
            $table->string('file_path');

            $table->dateTime('rendered_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
