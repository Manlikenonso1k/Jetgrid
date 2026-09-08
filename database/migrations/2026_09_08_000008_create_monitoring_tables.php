<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Server- and site-level time series behind the sparklines.
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key')->index();      // cpu, ram, disk, load, cpu_credits
            $table->double('value');
            $table->string('unit')->nullable();
            $table->timestamp('recorded_at')->index();

            $table->index(['key', 'recorded_at']);
        });

        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->boolean('ok')->default(false);
            $table->string('error')->nullable();
            $table->timestamp('checked_at')->index();
        });

        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('schedule');
            $table->text('command');
            $table->string('run_as')->nullable();
            // Adopted crons are listed and locked (Feature 10).
            $table->boolean('is_protected')->default(true)->index();
            $table->string('source')->default('discovery');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('alert_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');       // mail | telegram | webhook
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_channels');
        Schema::dropIfExists('cron_jobs');
        Schema::dropIfExists('health_checks');
        Schema::dropIfExists('metrics');
    }
};
