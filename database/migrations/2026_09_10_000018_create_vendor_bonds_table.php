<?php

use App\Domain\Vendors\BondType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_bonds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            $table->enum('type', BondType::values());
            $table->decimal('amount', 18, 4);

            // F15's point: the bond's expiry is its OWN, independent of the
            // accreditation's. A current accreditation with a lapsed
            // performance bond is exactly the combination that gets a
            // subcontract awarded against no cover.
            $table->date('effective_at');
            $table->date('expires_at');

            $table->string('reference')->nullable();
            $table->string('issuer')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'type', 'expires_at']);
        });

        // A bond that expires before it takes effect covers nothing, and would
        // read as merely lapsed rather than as the data-entry error it is.
        DB::statement(
            'ALTER TABLE vendor_bonds
             ADD CONSTRAINT vendor_bonds_period_order
             CHECK (expires_at >= effective_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bonds');
    }
};
