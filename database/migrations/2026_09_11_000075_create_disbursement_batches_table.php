<?php

use App\Domain\Hris\DisbursementMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursement_batches', function (Blueprint $table) {
            $table->id();

            /*
             * One batch per register. Two is the payroll paid twice, and both
             * batches would reconcile against the same approved figures — which
             * is what makes that failure hard to see rather than obvious.
             */
            $table->foreignId('payroll_run_id')->unique()->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('method', DisbursementMethod::values());

            /*
             * A total over everybody, so it reveals nobody's pay — the same
             * reasoning that leaves payroll_runs totals in the clear while the
             * lines are encrypted.
             */
            $table->decimal('total_amount', 18, 4);

            /*
             * Where the bank file was written. A relative path on the PRIVATE
             * disk: the file is every employee's name, account number and net pay
             * in one document, and on the public disk it is one guessed URL away
             * from being everybody's payslip.
             */
            $table->string('file_path')->nullable();

            $table->dateTime('prepared_at');
            $table->foreignId('prepared_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // The bank half's evidence: when the file went, and what came back.
            $table->dateTime('transmitted_at')->nullable();
            $table->string('bank_reference')->nullable();
            $table->foreignId('transmitted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursement_batches');
    }
};
