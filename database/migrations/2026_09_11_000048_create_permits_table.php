<?php

use App\Domain\Mobilization\PermitType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->enum('type', PermitType::values());
            $table->string('number');
            $table->string('issuing_authority');

            $table->date('issued_on');

            /*
             * A third expiry clock in this system, after accreditations and
             * bonds — and like both of them it runs on its own schedule. A site
             * working on a lapsed permit looks exactly like a site working on a
             * live one until somebody checks the date.
             */
            $table->date('expires_on');

            $table->text('remarks')->nullable();
            $table->timestamps();

            // The same permit number cannot be registered twice against one
            // project; a renewal is a new number and its own row.
            $table->unique(['project_id', 'type', 'number'], 'permits_project_type_number_unique');
            $table->index(['project_id', 'type', 'expires_on']);
        });

        /*
         * A permit expiring before it was issued covers nothing, and would read
         * as merely lapsed rather than as the data-entry error it is. Same
         * constraint, same reasoning, as vendor bonds in P1-02.
         */
        DB::statement('ALTER TABLE permits
             ADD CONSTRAINT permits_expires_after_issued
             CHECK (expires_on >= issued_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('permits');
    }
};
