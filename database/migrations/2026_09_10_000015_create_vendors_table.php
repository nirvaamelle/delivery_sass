<?php

use App\Domain\Vendors\VendorStatus;
use App\Domain\Vendors\VendorSuspensionReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            $table->string('code')->unique();
            $table->string('name');
            $table->string('tin')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();

            // F11: status carries its own explanation.
            $table->enum('status', VendorStatus::values())
                ->default(VendorStatus::Pending->value);
            $table->enum('status_reason', VendorSuspensionReason::values())->nullable();
            $table->text('status_notes')->nullable();
            $table->foreignId('status_changed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('status_changed_at')->nullable();

            // PLAN.md §3: sensitive columns encrypted at rest via the
            // `encrypted` cast, in the FIRST migration that creates them —
            // vendor bank details are named explicitly. Text, not string,
            // because ciphertext is far longer than the value it hides.
            $table->text('bank_name')->nullable();
            $table->text('bank_account_name')->nullable();
            $table->text('bank_account_number')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
