<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * External reachability monitoring.
 *
 * Every table here is keyed by site_id and lives beside the sites table rather
 * than adding columns to it. That is deliberate: an Adopted — Protected site
 * must be monitorable without the monitor ever writing to the site row, so
 * these checks cannot trip Site::MONITORING_FIELDS no matter what they record.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Append-only history. One row per check, per site, per run.
        Schema::create('domain_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('check_type');             // dns | nameserver | whois | external_http | tls
            $table->string('status');                 // ok | failing | unknown
            $table->string('severity')->default('info');
            $table->string('summary');
            $table->json('observed')->nullable();
            $table->json('expected')->nullable();
            $table->timestamp('checked_at')->index();

            $table->index(['site_id', 'check_type', 'checked_at']);
        });

        /*
         * The alert state machine. Alert discipline lives here rather than in the
         * checker: without a durable prior state there is no way to tell a new
         * failure from the same failure seen 288 times a day.
         */
        Schema::create('domain_check_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('check_type');
            $table->string('state')->default('unknown');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('failing_since')->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->string('last_alerted_state')->nullable();
            $table->string('last_summary')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'check_type']);
        });

        /*
         * Nameserver baseline, recorded from the first successful lookup and never
         * hardcoded. "Changed from what we saw before" is the only definition of a
         * suspicious NS change that works across registrars.
         */
        Schema::create('domain_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->json('nameservers');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique('site_id');
        });

        // WHOIS/RDAP is rate limited upstream, so the last answer is cached here
        // and re-read for 24h rather than re-fetched per scheduler tick.
        Schema::create('whois_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->boolean('lookup_ok')->default(false);
            $table->string('source')->nullable();      // rdap | whois43
            $table->timestamp('expires_at')->nullable();
            $table->json('statuses')->nullable();
            $table->json('nameservers')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('fetched_at')->index();
            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whois_snapshots');
        Schema::dropIfExists('domain_baselines');
        Schema::dropIfExists('domain_check_states');
        Schema::dropIfExists('domain_check_results');
    }
};
