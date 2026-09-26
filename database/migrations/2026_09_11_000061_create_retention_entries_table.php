<?php

use App\Domain\Billing\RetentionEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();

            /*
             * The billing this retention came from. Unique among WITHHELD
             * entries — enforced in the service, since a release has no billing
             * — because two entries for one billing double what the company
             * believes the client is holding.
             */
            $table->foreignId('billing_id')->nullable()->constrained()->restrictOnDelete();

            $table->enum('type', RetentionEntryType::values());

            /*
             * Signed: withheld positive, released negative. The balance is a SUM
             * rather than a difference somebody can get backwards — the same
             * rule the stock card follows, and for the same reason.
             */
            $table->decimal('amount', 18, 4);

            $table->date('entry_date');
            $table->string('reference')->nullable();

            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_entries');
    }
};
