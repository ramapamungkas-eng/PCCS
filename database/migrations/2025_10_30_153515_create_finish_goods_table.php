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
        Schema::create('finish_goods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id'); // singular, konsisten dengan Laravel convention
            $table->string('part_number');
            $table->string('part_name');
            $table->string('alias')->nullable();
            $table->string('model')->nullable();
            $table->string('variant')->nullable();
            $table->integer('stock')->default(0);
            $table->string('wh_address')->nullable();
            $table->enum('type', ['ASSY', 'DIRECT'])->default('ASSY');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // foreign key ke tabel customers
            $table
                ->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finish_goods');
    }
};
