<?php

use App\Domain\Opex\ExpenseStatus;
use App\Domain\Posting\LedgerCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            /*
             * Slide 8's control, as a foreign key: "an expense with no cost code
             * is not booked." Non-nullable, so no code path — importer, console
             * command, queued job — can create an uncoded expense. PLAN.md §1
             * asks every document to carry one.
             */
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', ExpenseStatus::values());
            $table->enum('category', LedgerCategory::values());

            $table->decimal('amount', 18, 4);

            /*
             * The document's own date, and the period it therefore belongs to.
             * Stamped rather than derived on read, because an expense dated 3 May
             * and booked on 27 May belongs to May and is late — the distinction
             * the cutoff resolver has enforced since P0-08.
             */
            $table->date('incurred_on');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            // "No receipt, not booked."
            $table->string('receipt_reference');
            $table->string('description');
            $table->string('payee')->nullable();

            $table->foreignId('captured_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->foreignId('returned_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->dateTime('posted_at')->nullable();
            $table->foreignId('project_cost_ledger_entry_id')->nullable()
                ->constrained('project_cost_ledger')->nullOnDelete();

            $table->timestamps();

            $table->index(['project_id', 'period_year', 'period_month']);
        });

        /*
         * One BOOKED receipt per project per period. Booking the same receipt
         * twice is the commonest way an expense is double counted, and both rows
         * look entirely ordinary.
         *
         * The subtlety is that a RETURNED expense must not hold the slot. Slide 8
         * says a returned expense is "not booked" — and if the returned row kept
         * the uniqueness, then clearing a bar raised in error would leave the
         * corrected expense permanently unbookable, which is the opposite of what
         * clearing a bar is for.
         *
         * So the key is over a stored generated column that is NULL while the
         * expense is returned. MySQL treats every NULL in a unique index as
         * distinct, so any number of returned rows may share a receipt while only
         * one live one may — the same technique P0-08 used to make a nullable
         * project_id behave in a unique index.
         */
        DB::statement("
            ALTER TABLE expenses
            ADD COLUMN booked_receipt_reference VARCHAR(255)
            GENERATED ALWAYS AS (
                CASE WHEN status = 'returned' THEN NULL ELSE receipt_reference END
            ) STORED
        ");

        DB::statement('
            ALTER TABLE expenses
            ADD CONSTRAINT expenses_booked_receipt_period_unique
            UNIQUE (project_id, period_year, period_month, booked_receipt_reference)
        ');

        DB::statement('ALTER TABLE expenses
             ADD CONSTRAINT expenses_amount_positive
             CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
