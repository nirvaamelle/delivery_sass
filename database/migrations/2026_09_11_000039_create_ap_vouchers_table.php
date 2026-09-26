<?php

use App\Domain\Procurement\ApVoucherStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_vouchers', function (Blueprint $table) {
            $table->id();

            /*
             * PLAN.md §5's payment control, stated as a foreign key: a voucher
             * cannot exist without a match behind it. Non-nullable, so no code
             * path — importer, console command, queued job — can raise a payment
             * that skipped the match. Unique, so one match cannot be paid twice.
             */
            $table->foreignId('three_way_match_id')->unique()->constrained()->restrictOnDelete();

            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            // What the match said was payable.
            $table->decimal('gross_amount', 18, 4);

            // F9's shared shape, from the FIRST migration of this table — a tax
            // column added later means restating every posting already made.
            $table->withholdingColumns();

            /*
             * F8. An advance was already paid against this order, so it comes
             * off here. Without the column the vendor is paid twice for the same
             * goods and both payments have a complete document set.
             */
            $table->decimal('advance_offset', 18, 4)->default('0.0000');

            $table->decimal('net_amount', 18, 4);

            $table->enum('status', ApVoucherStatus::values())->default(ApVoucherStatus::Raised->value);

            $table->dateTime('raised_at');
            $table->foreignId('raised_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        /*
         * A negative payable is not a payment, it is a receivable, and it
         * arrives here only when an offset exceeds what is owed. Refused in the
         * database as well as in the service: the service caps the offset, and
         * this is what catches the day somebody writes a second one that does
         * not.
         */
        DB::statement('ALTER TABLE ap_vouchers
             ADD CONSTRAINT ap_vouchers_net_not_negative
             CHECK (net_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_vouchers');
    }
};
