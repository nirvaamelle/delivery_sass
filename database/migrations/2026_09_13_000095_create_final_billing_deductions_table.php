<?php

use App\Domain\Closeout\FinalDeductionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_billing_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_id')->constrained()->restrictOnDelete();

            $table->enum('type', FinalDeductionType::values());

            /*
             * The back-charge this line came from — UNIQUE, so one charge is
             * deducted once. Nullable because the other three types are
             * judgements made at final billing and have no document behind them
             * yet; the CHECK below makes the pairing exact in both directions.
             */
            $table->foreignId('back_charge_id')->nullable()->unique()->constrained()->restrictOnDelete();

            $table->string('description');
            $table->decimal('amount', 18, 4);

            $table->dateTime('applied_at');
            $table->foreignId('applied_by_user_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['billing_id', 'type']);
        });

        /*
         * A zero deduction is a note in a file and a negative one is an
         * addition to the bill wearing the wrong document.
         */
        DB::statement('
            ALTER TABLE final_billing_deductions
            ADD CONSTRAINT final_billing_deductions_amount_is_positive
            CHECK (amount > 0)');

        /*
         * The derived/entered distinction, in the database. A row typed
         * "back_charge" that names no back-charge is a hand-entered figure
         * wearing the label, and the amount it claims was never computed at
         * punchlist clearing by anybody. The reverse is refused too: a
         * liquidated-damages row pointing at a back-charge would deduct that
         * charge twice under two names.
         */
        DB::statement("
            ALTER TABLE final_billing_deductions
            ADD CONSTRAINT final_billing_deductions_back_charge_is_typed
            CHECK (
                (type = 'back_charge' AND back_charge_id IS NOT NULL)
                OR
                (type <> 'back_charge' AND back_charge_id IS NULL)
            )");
    }

    public function down(): void
    {
        Schema::dropIfExists('final_billing_deductions');
    }
};
