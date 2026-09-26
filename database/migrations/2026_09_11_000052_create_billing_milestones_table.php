<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_schedule_id')->constrained()->cascadeOnDelete();

            $table->string('code');
            $table->string('name');

            /*
             * The share of the contract sum this milestone bills. A percentage,
             * not an amount: a variation order that changes the contract sum
             * would otherwise leave five stale figures behind it.
             */
            $table->decimal('percentage', 5, 2);

            $table->string('trigger');

            /*
             * The verified accomplishment a progress milestone cannot bill
             * below — the number P2-05's gate reads. Nullable because the
             * downpayment bills on a signed contract rather than on progress.
             *
             * Stored rather than parsed out of the name: "50% progress" is a
             * label, and a label is not a control.
             */
            $table->decimal('accomplishment_threshold', 5, 2)->nullable();

            $table->unsignedTinyInteger('sequence');

            $table->timestamps();

            $table->unique(['billing_schedule_id', 'code'], 'billing_milestones_code_unique');
            $table->unique(['billing_schedule_id', 'sequence'], 'billing_milestones_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_milestones');
    }
};
