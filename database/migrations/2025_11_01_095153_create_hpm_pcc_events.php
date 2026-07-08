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
        Schema::create('hpm_pcc_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('pcc_trace_id'); // FK to PccTrace
            $table->unsignedBigInteger('event_users');
            $table->enum('event_type', ['PRODUCTION CHECK', 'RECEIVED', 'PDI CHECK', 'DELIVERY']);
            $table->timestamp('event_timestamp');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('pcc_trace_id')->references('id')->on('hpm_pcc_traces')->onDelete('cascade');
            $table->foreign('event_users')->references('id')->on('users')->onDelete('cascade');

            // add indexes for faster queries
            $table->index('pcc_trace_id');
            $table->index('event_type'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hpm_pcc_events');
    }
};
