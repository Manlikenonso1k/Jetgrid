<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents')->default(0);

            // Limits. null means unlimited — enforced server-side by policies,
            // never merely hidden in the UI (Feature 8).
            $table->unsignedInteger('max_sites')->nullable();
            $table->unsignedInteger('max_databases')->nullable();
            $table->unsignedBigInteger('max_storage_mb')->nullable();
            $table->unsignedInteger('max_backups')->nullable();
            $table->unsignedInteger('monitor_interval_seconds')->default(300);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
