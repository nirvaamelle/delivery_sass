<?php

use App\Domain\Equipment\DepreciationMethod;
use App\Domain\Equipment\EquipmentStatus;
use App\Domain\Equipment\Ownership;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            /*
             * The code is what a foreman writes on a fuel slip and what a
             * mechanic quotes on a repair order. Two machines answering to one
             * code makes every cost on both of them unattributable, so it is
             * unique across the register rather than per organization.
             */
            $table->string('code')->unique();
            $table->string('description');
            $table->string('category')->nullable();
            $table->string('serial_number')->nullable();

            $table->enum('ownership', Ownership::values());
            $table->enum('status', EquipmentStatus::values())->default(EquipmentStatus::Available->value);

            /*
             * Acquired through procurement — which is why F5 is built in Phase 1
             * alongside the PO chain rather than in Phase 4. Nullable because
             * the fleet that exists on day one was bought before this system.
             */
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('acquisition_cost', 18, 4)->default('0.0000');
            $table->decimal('salvage_value', 18, 4)->default('0.0000');
            $table->date('acquired_on')->nullable();

            /*
             * PLACEHOLDER: Part D item 15 — method and life are per machine, not
             * constants, so the client answering is a data change.
             */
            $table->unsignedSmallInteger('useful_life_months')->default(60);
            $table->enum('depreciation_method', DepreciationMethod::values())
                ->default(DepreciationMethod::StraightLine->value);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
