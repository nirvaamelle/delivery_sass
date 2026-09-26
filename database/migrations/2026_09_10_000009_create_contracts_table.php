<?php

use App\Domain\Contracts\ContractStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', ContractStatus::values())
                ->default(ContractStatus::Draft->value);

            // PLAN.md §4, project spine: NOA, NTP, contract sum, retention %, DLP.
            $table->date('noa_date')->nullable();
            $table->date('ntp_date')->nullable();
            $table->decimal('contract_sum', 18, 4)->default('0.0000');

            // PLACEHOLDER: Part D item 3 — retention rate and defects liability
            // period. PLAN.md §5 assumes 10% retention; the DLP length is
            // unstated in the deck, so a year is used until the client says
            // otherwise. Both are per-contract columns rather than constants
            // precisely so overwriting them is a data change, not a code change.
            $table->decimal('retention_rate', 9, 6)->default('0.100000');
            $table->unsignedSmallInteger('defects_liability_days')->default(365);

            // When the contract actually became binding. Distinct from
            // created_at: the record exists long before it is signed.
            $table->dateTime('signed_at')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
