<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('joint_surveys', function (Blueprint $table) {
            $table->id();

            // One survey per accomplishment. Two would be two client positions
            // on the same work, and whichever was signed last would win by
            // accident.
            $table->foreignId('accomplishment_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('number')->unique();
            $table->date('surveyed_on');

            /*
             * Who will sign, named in advance. Accepting any name at signing
             * time would make the representative field decorative and the
             * signature unattributable — and this signature is what an invoice
             * rests on.
             */
            $table->string('client_representative');
            $table->string('contractor_representative');

            /*
             * Two separate timestamps, because "joint" means both and the
             * client's is the one that binds. A single `signed_at` could not
             * express a survey the contractor has signed and the client has not
             * — which is the ordinary state of affairs for days at a time.
             */
            $table->dateTime('contractor_signed_at')->nullable();
            $table->foreignId('contractor_signed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->dateTime('client_signed_at')->nullable();
            $table->string('client_signed_by')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('joint_surveys');
    }
};
