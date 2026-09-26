<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exit gate clause 4's columns.
     *
     * The secret is `text` and encrypted by a cast, the same shape PLAN.md §3
     * requires of vendor bank details and employee government numbers. A TOTP
     * secret in plaintext is a second factor anybody with a database dump holds
     * too.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');

            /*
             * Separate from the secret, because generating one is not enrolling.
             * Somebody who scanned a QR and closed the tab has a secret and no
             * authenticator, and enforcing on the secret alone would lock them
             * out with a factor they never proved they can produce.
             */
            $table->dateTime('two_factor_confirmed_at')->nullable()->after('two_factor_secret');

            /*
             * The last code accepted. A TOTP window is thirty seconds wide, so
             * without this a code read over somebody's shoulder is good for the
             * rest of it.
             */
            $table->text('two_factor_last_code')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_code']);
        });
    }
};
