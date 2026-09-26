<?php

use App\Domain\Hris\PayBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->enum('basis', PayBasis::values());

            /*
             * Encrypted, like the government numbers, and for a sharper reason:
             * the rate is the one field that tells a reader what everybody in
             * the company is worth relative to everybody else.
             *
             * `text` rather than DECIMAL(18,4) — the ciphertext is what the
             * column holds. The money contract is still kept: the value is a
             * decimal STRING before it is encrypted and after it is decrypted,
             * and it never becomes a float on the way through. What is lost is
             * the ability to SUM this column in SQL, which is a fair trade:
             * nothing should be totalling salaries with a query.
             */
            $table->text('rate');

            /*
             * A rate is a HISTORY, not a column. Payroll does not ask what
             * somebody earns; it asks what they were earning during the period
             * being computed — and a mutable figure answers only for today,
             * silently restating every run ever made against it.
             */
            $table->date('effective_from');

            $table->foreignId('set_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();

            $table->timestamps();

            // One rate per person per day. Two means payroll picks by query
            // order, and somebody is paid whichever the database returned first.
            $table->unique(['employee_id', 'effective_from'], 'employee_rates_effective_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_rates');
    }
};
