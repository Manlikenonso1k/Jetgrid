<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('domain')->index();
            $table->json('sans')->nullable();
            $table->string('issuer')->nullable();
            $table->string('serial')->nullable();

            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable()->index();

            $table->string('status')->default('unknown')->index();
            $table->string('challenge_type')->nullable(); // http-01 | dns-01

            // False for adopted certs. JetGrid displays these and issues no
            // command against them, ever.
            $table->boolean('is_managed')->default(false)->index();

            $table->string('cert_path')->nullable();
            $table->timestamp('last_renewal_attempt_at')->nullable();
            $table->timestamp('last_renewed_at')->nullable();
            $table->unsignedTinyInteger('renewal_failures')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();
        });

        // Let's Encrypt allows 5 duplicate certificates per registered domain
        // per week. We track issuance ourselves and refuse the 6th rather than
        // discovering the limit by being rate-limited.
        Schema::create('certificate_issuances', function (Blueprint $table) {
            $table->id();
            $table->string('domain_set_hash', 64)->index();
            $table->string('domain')->index();
            $table->boolean('succeeded')->default(false);
            $table->timestamp('attempted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_issuances');
        Schema::dropIfExists('certificates');
    }
};
