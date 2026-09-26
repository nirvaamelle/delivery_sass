<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_matrix', function (Blueprint $table) {
            $table->id();

            // A plain identifier, not a class name. Phase 0 has to be able to
            // define the matrix for documents that Phase 1 and Phase 2 will
            // introduce — the authority matrix is configuration and precedes
            // the code that uses it.
            $table->string('document_type', 64);
            $table->unsignedTinyInteger('tier');

            // PLACEHOLDER: Part D item 1. The deck shows bands, not amounts,
            // deliberately. These columns hold whatever the client confirms;
            // until then the seeder's values are the build's own invention and
            // every routing test is provisional. This is B2.
            $table->decimal('min_amount', 18, 4);

            // NULL is the open-ended top band — there is no ceiling above the
            // highest tier.
            $table->decimal('max_amount', 18, 4)->nullable();

            // Ordered. Slide 5 puts two approvers on most tiers; Tier 1 keeps
            // one, because PLAN.md §9 names approval fatigue there as a risk
            // and its mitigation is to add no signatures to that path.
            $table->json('approver_roles');

            // The document set the tier requires before it will consider the
            // request at all.
            $table->json('required_documents');

            $table->timestamps();

            $table->unique(['document_type', 'tier']);
            $table->index(['document_type', 'min_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_matrix');
    }
};
