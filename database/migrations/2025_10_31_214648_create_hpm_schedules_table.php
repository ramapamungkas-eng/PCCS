<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hpm_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slip_number')->unique();
            $table->date('schedule_date')->nullable();
            $table->date('adjusted_date')->nullable();
            $table->time('schedule_time')->nullable();
            $table->time('adjusted_time')->nullable();
            $table->integer('delivery_quantity')->default(0);
            $table->integer('adjustment_quantity')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hpm_schedules');
    }
};
