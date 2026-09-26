<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();

            // Slide 10's handoff rule: every document carries the project code.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            /*
             * F7's first half: "a register that links the certificate to the
             * VENDOR". Everything the claim side does — the suspension, the
             * scorecard, the history of how a supplier's promises held up —
             * hangs off this column.
             */
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();

            /*
             * What the certificate was issued against. Nullable because a
             * warranty can arrive with goods bought before this system existed,
             * or under a contract rather than an order — but RESTRICT rather
             * than nullOnDelete, because the order is the only join that says
             * what was actually bought, and a certificate that quietly lost it
             * is a promise about nothing in particular.
             */
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subcontract_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            /*
             * The vendor's OWN number on the certificate — what gets produced
             * when a claim is argued. Unique per vendor and not globally: the
             * reference belongs to the supplier's numbering, and two suppliers
             * both issuing "0001" is ordinary.
             */
            $table->string('certificate_reference');

            // What is covered. A claim is argued against this, and "warranty,
            // 1 year" covers whatever the vendor later says it covers.
            $table->text('scope');

            /*
             * Coverage is a PERIOD. The question the register exists to answer
             * is whether a failure happened inside it, so both ends are stored
             * and neither is derived from a duration that would have to be
             * recomputed against a start date somebody edited.
             */
            $table->date('starts_on');
            $table->date('ends_on');

            // "Collected at turnover" — when the paper actually arrived, and
            // from whom it was taken. Not the same date as coverage starting.
            $table->dateTime('received_at');
            $table->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(['vendor_id', 'certificate_reference']);

            /*
             * Not redundant with the primary key. It is what lets
             * `warranty_claims` carry a COMPOSITE foreign key on
             * (warranty_id, vendor_id): a claim cannot point at another
             * vendor's certificate, enforced by the database rather than by
             * whoever remembered to check. A claim on the wrong promise
             * suspends the wrong company.
             */
            $table->unique(['id', 'vendor_id'], 'warranties_id_vendor_unique');

            $table->index(['project_id', 'ends_on']);
        });

        /*
         * An inverted period is in force on no date at all, so every claim
         * under it is denied and the register reads as though the certificate
         * had never been collected — a failure that looks like paperwork rather
         * than like a bug. Equal dates are allowed: a one-day warranty is odd,
         * not wrong.
         */
        DB::statement('
            ALTER TABLE warranties
            ADD CONSTRAINT warranties_coverage_period_is_ordered
            CHECK (ends_on >= starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
