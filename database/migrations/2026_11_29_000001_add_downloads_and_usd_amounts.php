<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of software licensing:
 *  - the USD value of a storefront order (optional; revenue reports), and
 *  - release packages stored on this server, downloaded through single-use
 *    signed links that the storefront requests for an entitled license.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('software_orders', function (Blueprint $table) {
            $table->bigInteger('amount_usd_minor')->nullable();
        });

        Schema::table('software_releases', function (Blueprint $table) {
            $table->string('package_path', 500)->nullable();   // on the private "local" disk
            $table->string('package_name', 200)->nullable();   // file name offered to the browser
        });

        Schema::create('software_download_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token_hash', 64)->unique();        // sha256 of the token in the URL; the token itself is never stored
            $table->foreignId('software_license_id')->constrained()->restrictOnDelete();
            $table->foreignId('software_release_id')->constrained()->restrictOnDelete();
            $table->string('requested_by', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_download_links');

        Schema::table('software_releases', fn (Blueprint $table) => $table->dropColumn(['package_path', 'package_name']));
        Schema::table('software_orders', fn (Blueprint $table) => $table->dropColumn('amount_usd_minor'));
    }
};
