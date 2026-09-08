<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protected_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            // nginx_vhost | apache_vhost | systemd_unit | supervisor_program
            // | cron | php_fpm_pool | directory | certificate
            $table->string('type')->index();
            $table->string('name');
            $table->string('path')->nullable();

            // Content hash at discovery time. If this changes, something outside
            // JetGrid edited the file — surfaced, never reconciled automatically.
            $table->string('fingerprint', 64)->nullable();

            // A short excerpt for display. Full contents are never copied: this
            // record exists to prove JetGrid saw the file, not to own it.
            $table->text('excerpt')->nullable();

            $table->json('parsed')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protected_resources');
    }
};
