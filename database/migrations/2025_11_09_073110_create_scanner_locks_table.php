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
        Schema::create('scanner_locks', function (Blueprint $table) {
            $table->id();
            $table->string('scanner_identifier'); // e.g., 'weld-production-check'
            $table->timestamp('locked_until')->nullable();
            $table->string('reason')->nullable();
            // Consolidated: locked_by_user_id changed to unsignedBigInteger and made part of composite unique, with FK
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->text('metadata')->nullable(); // JSON for extra context
            $table->timestamps();

            $table->index('locked_until');
            // Final constraint state: composite unique so per-user locks allowed
            $table->unique(['scanner_identifier', 'locked_by_user_id'], 'scanner_user_unique');

            $table->foreign('locked_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scanner_locks');
    }
};
