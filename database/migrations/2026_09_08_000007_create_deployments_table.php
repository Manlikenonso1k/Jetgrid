<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('repository')->nullable();
            $table->string('branch')->default('main');
            $table->string('commit_sha', 40)->nullable();
            $table->string('commit_message')->nullable();

            // Envoyer-style atomic releases: build into releases/<id>, then
            // repoint the `current` symlink. Rollback repoints it back.
            $table->string('release_path')->nullable();
            $table->string('previous_release_path')->nullable();

            $table->string('status')->default('queued')->index();
            $table->longText('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
