<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how an identity was confirmed (match_on_card, nia_register, ...).
 * Unlike the provider reference it is not purged at retention expiry, so the
 * company can always show which L.I. 2523 method was used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_verifications', function (Blueprint $table) {
            $table->string('verification_method', 30)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_verifications', function (Blueprint $table) {
            $table->dropColumn('verification_method');
        });
    }
};
