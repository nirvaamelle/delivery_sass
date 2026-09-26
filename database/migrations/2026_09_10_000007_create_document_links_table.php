<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_links', function (Blueprint $table) {
            $table->id();

            // Polymorphic on both ends. The spine has to span every chain: a PO
            // points back at a PR and a bid tabulation, a receiving report at a
            // PO, an AP voucher at all three. Written out rather than using
            // morphs() so the type column stays short enough for the composite
            // unique key below.
            $table->string('predecessor_type', 191);
            $table->unsignedBigInteger('predecessor_id');
            $table->string('successor_type', 191);
            $table->unsignedBigInteger('successor_id');

            $table->timestamps();

            // One edge per pair. A duplicate would double-count the document in
            // any trace, and the P&L is assembled from these traces.
            $table->unique(
                ['predecessor_type', 'predecessor_id', 'successor_type', 'successor_id'],
                'document_links_edge_unique'
            );

            // Traversal runs in both directions — "what came before this" and
            // "what did this produce" are both asked constantly.
            $table->index(['predecessor_type', 'predecessor_id'], 'document_links_predecessor_index');
            $table->index(['successor_type', 'successor_id'], 'document_links_successor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_links');
    }
};
