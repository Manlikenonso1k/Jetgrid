<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('type');          // database | files
            $table->string('destination');   // s3 | local
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum')->nullable();

            $table->string('status')->default('pending');
            $table->timestamp('retention_until')->nullable()->index();

            // Feature 9: a backup nobody has restored is a hypothesis. The
            // verify action restores into a scratch directory and records here.
            $table->timestamp('verified_at')->nullable();
            $table->string('verify_result')->nullable();
            $table->text('verify_output')->nullable();

            $table->timestamps();
        });

        // Safety constraint #4: every config file JetGrid writes is copied first.
        Schema::create('config_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('original_path');
            $table->string('backup_path');
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Stored inline as well as on disk, so a one-click restore still
            // works if the backup directory is lost.
            $table->longText('contents')->nullable();

            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index('original_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_backups');
        Schema::dropIfExists('backups');
    }
};
