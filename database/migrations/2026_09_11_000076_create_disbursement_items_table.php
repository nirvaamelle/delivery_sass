<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disbursement_batch_id')->constrained()->cascadeOnDelete();

            /*
             * One payment per payroll line, ever. The same reasoning as the
             * unique key on payroll_line_days: paying one line twice is the
             * failure a payroll defect turns into, so the database holds it
             * rather than a check some future path could forget.
             */
            $table->foreignId('payroll_line_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            // Encrypted, like every other per-person amount — one person's net
            // pay is their rate to anybody with their days beside it.
            $table->text('amount');

            /*
             * Null until the money actually left. For a bank batch that is when
             * the file is transmitted; for cash it is when somebody signs, and
             * NOT before — cash without an acknowledgment is indistinguishable
             * from cash still in the drawer.
             */
            $table->dateTime('released_at')->nullable();

            $table->date('acknowledged_on')->nullable();
            $table->string('acknowledged_by')->nullable();
            $table->foreignId('witnessed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['disbursement_batch_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursement_items');
    }
};
