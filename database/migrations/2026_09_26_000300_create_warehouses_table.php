<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            /*
             * The code is what appears on a delivery receipt and what a
             * dispatcher says on the phone. Unique per company, not globally:
             * two organizations each calling their first site WH-01 is normal.
             */
            $table->string('code', 32);
            $table->string('name');
            $table->text('address')->nullable();

            /*
             * Closed sites are deactivated, never deleted — gate visits, pick
             * tasks and trip plans point here, and history has to keep
             * resolving after a site closes.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'warehouses_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
