<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPS installation scheduling: the technicians who fit devices (each with a
 * pinned home base so the nearest one can be suggested) and the installation
 * jobs they are booked onto. A job's `status` is its kanban column and
 * `position` its place within that column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('skills', 160)->nullable();
            $table->string('base_address', 255)->nullable();
            $table->decimal('base_lat', 10, 7)->nullable();
            $table->decimal('base_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('service_radius_km')->default(30);
            $table->unsignedTinyInteger('daily_capacity')->default(3);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // e.g. INS-000001
            $table->string('title', 160);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('vehicle', 160)->nullable();
            $table->unsignedSmallInteger('units')->default(1);

            $table->string('address', 255);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->date('scheduled_on')->nullable();
            $table->string('start_time', 5)->nullable(); // HH:MM, 24-hour
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->foreignId('installer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('priority', 10)->default('normal'); // low, normal, high
            $table->string('status', 20)->default('pending'); // pending, scheduled, in_progress, completed, cancelled
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'position']);
            $table->index('scheduled_on');
            $table->index(['installer_id', 'scheduled_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
        Schema::dropIfExists('installers');
    }
};
