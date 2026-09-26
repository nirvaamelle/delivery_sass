<?php

use App\Domain\Procurement\ApVoucherStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of a voucher's life: submitted, paid, cancelled.
 *
 * The table has carried APPROVED, PAID and CANCELLED since it was created, and
 * nothing ever set them — PayablesService could only raise. SUBMITTED is new:
 * approval routes through the authority matrix, so there is a state between
 * "assembled by the clerk" and "signed by the officer".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ap_vouchers', function (Blueprint $table) {
            $table->enum('status', ApVoucherStatus::values())
                ->default(ApVoucherStatus::Raised->value)
                ->change();

            $table->dateTime('submitted_at')->nullable()->after('raised_by_user_id');

            // What the bank statement will be reconciled against. A payment
            // without one cannot be tied to the money that actually left.
            $table->string('payment_reference')->nullable()->after('submitted_at');
            $table->date('paid_at')->nullable()->after('payment_reference');
            $table->foreignId('paid_by_user_id')->nullable()->after('paid_at')
                ->constrained('users')->nullOnDelete();

            $table->dateTime('cancelled_at')->nullable()->after('paid_by_user_id');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancellation_reason')
                ->constrained('users')->nullOnDelete();

            // The cash requirement asks "approved but not yet paid, by project".
            $table->index(['project_id', 'status'], 'ap_vouchers_project_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('ap_vouchers', function (Blueprint $table) {
            $table->dropIndex('ap_vouchers_project_status_index');
            $table->dropConstrainedForeignId('paid_by_user_id');
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn(['submitted_at', 'payment_reference', 'paid_at', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
