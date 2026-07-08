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
        Schema::create('pcc_cpps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('finish_good_id');
            // Consolidated from later alteration migration: stage enum with index
            $table->enum('stage', ['PRODUCTION CHECK', 'PDI CHECK', 'DELIVERY', 'ALL'])
                ->default('ALL')
                ->comment('Stage where this CCP should be checked');
            $table->integer('revision')->default(1);
            $table->string('check_point_img')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes
            $table->index('stage');

            $table->foreign('finish_good_id')->references('id')->on('finish_goods')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pcc_cpps');
    }
};
