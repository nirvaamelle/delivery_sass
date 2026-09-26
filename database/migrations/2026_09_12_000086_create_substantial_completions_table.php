<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('substantial_completions', function (Blueprint $table) {
            $table->id();

            /*
             * UNIQUE, not merely indexed. Substantial completion happens once.
             * A second certificate would give the defects liability period two
             * start dates and retention two release dates, and the close-out
             * report no way to say which one it was written against.
             */
            $table->foreignId('project_id')->unique()->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            // The document's own date, not the date somebody typed it in. Every
            // other document in this build makes the same distinction.
            $table->date('certified_on');

            /*
             * NOT nullable, and restrictOnDelete rather than nullOnDelete.
             * Slide 9's obligation is that close-out names who cleared each item
             * and when; a certificate whose certifier evaporated when an account
             * was tidied up is exactly the anonymous paperwork it forbids. The
             * user row cannot be deleted while it is the signature on a
             * certificate — which is the correct answer for an audit trail.
             */
            $table->foreignId('certified_by_user_id')->constrained('users')->restrictOnDelete();

            // Who accepted on the client's side. Free text because the client's
            // representative is not a user of this system.
            $table->string('client_representative')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('substantial_completions');
    }
};
