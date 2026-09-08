<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local projects live in their own table rather than in `sites`.
 *
 * A site is a domain on a managed or adopted server; a local project is a folder
 * on this machine. They share almost no columns, and more importantly they have
 * opposite safety rules — every write to a site goes through the protected gate,
 * while a local project has no server-side existence to protect. Folding them
 * together would mean one model carrying two contradictory sets of invariants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_projects', function (Blueprint $table) {
            $table->id();

            // The canonical path is the identity. L1 requires it to be
            // normalised before it is stored so the same folder cannot be
            // imported twice under two spellings.
            $table->string('path', 1024);
            $table->string('path_key', 64)->unique();

            $table->string('name');
            $table->string('declared_name')->nullable();
            $table->string('scan_root', 1024);

            $table->string('type')->index();
            $table->string('type_marker');
            $table->string('framework')->nullable();
            $table->string('framework_version')->nullable();
            $table->boolean('has_docker')->default(false);

            $table->string('install_state')->default('ready')->index();
            $table->json('blockers')->nullable();
            $table->string('package_manager')->nullable();
            $table->string('dev_script')->nullable();

            // Resolved by PortResolver; port_source records which layer of L4
            // produced it. port_override is the only one a human sets.
            $table->unsignedInteger('port')->nullable();
            $table->string('port_source')->nullable();
            $table->unsignedInteger('port_override')->nullable();
            $table->boolean('auto_port')->default(false);

            $table->string('run_state')->default('stopped')->index();
            $table->string('detection_layer')->default('none');
            $table->boolean('attributed')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->string('process_name')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();

            // Set when JETGRID started the process. Null means whatever is on
            // the port got there some other way.
            $table->timestamp('started_at')->nullable();
            $table->string('log_path', 1024)->nullable();
            $table->text('last_error')->nullable();

            $table->string('git_branch')->nullable();
            $table->boolean('git_dirty')->nullable();

            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('probed_at')->nullable();
            $table->timestamp('source_modified_at')->nullable();

            // Grid coordinates, assigned once so houses do not jump between polls.
            $table->integer('grid_x')->nullable();
            $table->integer('grid_z')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_projects');
    }
};
