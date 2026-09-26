<?php

use App\Domain\Opex\CashAdvanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', CashAdvanceStatus::values());

            $table->decimal('amount', 18, 4);
            $table->date('released_on');

            // Money leaving before anything is spent has to say what it is for —
            // the same rule F8's vendor advance follows on the procurement side.
            $table->text('purpose');

            $table->foreignId('released_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * F17's stamp: when the unliquidated balance was charged to payroll,
             * and how much. Recorded here rather than only on the payroll side so
             * an advance can never be swept twice — and so a site clerk can see
             * why their next payslip is short.
             *
             * There is deliberately NO `outstanding` column. The balance is what
             * was released less what has been liquidated, and only the
             * liquidations answer that; a stored figure is only as true as the
             * last routine that wrote it.
             */
            $table->date('charged_to_payroll_on')->nullable();
            $table->decimal('charged_amount', 18, 4)->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['project_id', 'released_on']);
        });

        DB::statement('ALTER TABLE cash_advances
             ADD CONSTRAINT cash_advances_amount_positive
             CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advances');
    }
};
