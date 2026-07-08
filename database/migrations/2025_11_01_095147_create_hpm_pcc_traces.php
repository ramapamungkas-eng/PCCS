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
        Schema::create('hpm_pcc_traces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('pcc_id');
            $table->enum('event_type', ['PRODUCTION CHECK', 'RECEIVED', 'PDI CHECK', 'DELIVERY']);
            $table->timestamp('event_timestamp');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('pcc_id')->references('id')->on('pccs')->onDelete('cascade');
            // add indexes for faster queries
            $table->index('pcc_id');
            $table->index('event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hpm_pcc_traces');
    }
};
