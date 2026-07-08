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
        Schema::create('pccs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->string('supply_address')->nullable();
            $table->string('next_supply_address')->nullable();
            $table->string('ms_id')->nullable();
            $table->string('inventory_category')->nullable();
            $table->string('part_no');
            $table->string('part_name')->nullable();
            $table->string('color_code')->nullable();
            $table->string('ps_code')->nullable();
            $table->string('order_class')->nullable();
            $table->string('prod_seq_no')->nullable();
            $table->string('kd_lot_no')->nullable();
            $table->integer('ship')->default(0);
            $table->string('slip_no', 12);
            $table->string('slip_barcode')->unique()->index();
            $table->boolean('printed')->default(false);
            $table->date('date');
            $table->time('time');
            $table->string('hns')->nullable();
            $table->timestamps();

            $table->index('slip_barcode');
            $table->index('part_no');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pccs');
    }
};
