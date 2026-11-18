<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "zoho" = the record already existed in Zoho Books and was linked by a
 * Super Admin. Its details are owned by Zoho: they are read from there and
 * never overwritten by this application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_mappings', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('zoho_entity');
        });
    }

    public function down(): void
    {
        Schema::table('zoho_mappings', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
