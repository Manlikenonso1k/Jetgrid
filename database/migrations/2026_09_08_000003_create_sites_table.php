<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('domain')->unique();
            $table->string('display_name')->nullable();

            // managed | adopted_protected. The second is set only by discovery.
            $table->string('management_mode')->default('adopted_protected')->index();

            // Denormalised copy of the above so a protection check never depends
            // on a string comparison being written correctly at every call site.
            $table->boolean('is_protected')->default(true)->index();

            $table->string('document_root')->nullable();
            $table->string('php_version')->nullable();
            $table->string('server_user')->nullable();
            $table->string('database_name')->nullable();
            $table->string('vhost_path')->nullable();

            $table->string('status')->default('unknown')->index();
            $table->unsignedTinyInteger('health_score')->default(0);
            $table->string('beacon_color')->default('grey');

            // Resource footprint — drives house size on the 3D grid.
            $table->unsignedInteger('ram_mb')->default(0);
            $table->unsignedBigInteger('disk_bytes')->default(0);
            $table->unsignedInteger('requests_per_minute')->default(0);

            $table->boolean('maintenance_mode')->default(false);
            $table->boolean('basic_auth_enabled')->default(false);
            $table->unsignedInteger('pending_updates')->default(0);

            // Where this record came from: 'discovery' or 'jetgrid'.
            $table->string('source')->default('discovery');
            $table->timestamp('discovered_at')->nullable();

            // Grid coordinates, assigned once so houses do not jump around
            // between polls.
            $table->integer('grid_x')->nullable();
            $table->integer('grid_z')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
