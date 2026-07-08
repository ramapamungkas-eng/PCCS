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
        // First, let's check if we can use change() method
        try {
            Schema::table('pccs', function (Blueprint $table) {
                // Based on the error data, these fields need larger capacity
                $table->string('slip_barcode', 50)->change();
                $table->string('from', 100)->nullable()->change();
                $table->string('to', 100)->nullable()->change();
                $table->string('supply_address', 100)->nullable()->change();
                $table->string('next_supply_address', 100)->nullable()->change();
                $table->string('ms_id', 100)->nullable()->change();
                $table->string('inventory_category', 100)->nullable()->change();
                $table->string('part_no', 200)->nullable()->change();
                $table->string('part_name', 500)->nullable()->change();
                $table->string('color_code', 100)->nullable()->change();
                $table->string('ps_code', 100)->nullable()->change();
                $table->string('order_class', 100)->nullable()->change();
                $table->string('prod_seq_no', 200)->nullable()->change();
                $table->string('kd_lot_no', 200)->nullable()->change();
                $table->string('slip_no', 200)->nullable()->change();
                $table->string('hns', 50)->nullable()->change();
            });
        } catch (\Exception $e) {
            // If change() fails, use raw SQL for PostgreSQL
            DB::statement('ALTER TABLE pccs ALTER COLUMN slip_barcode TYPE VARCHAR(50)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN "from" TYPE VARCHAR(100)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN "to" TYPE VARCHAR(100)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN supply_address TYPE VARCHAR(100)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN next_supply_address TYPE VARCHAR(100)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN ms_id TYPE VARCHAR(100)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN inventory_category TYPE VARCHAR(100)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN part_no TYPE VARCHAR(200)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN part_name TYPE VARCHAR(500)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN color_code TYPE VARCHAR(100)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN ps_code TYPE VARCHAR(100)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN order_class TYPE VARCHAR(100)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN prod_seq_no TYPE VARCHAR(200)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN kd_lot_no TYPE VARCHAR(200)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN slip_no TYPE VARCHAR(200)');
            DB::statement('ALTER TABLE pccs ALTER COLUMN hns TYPE VARCHAR(50)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Define your original field lengths here
        Schema::table('pccs', function (Blueprint $table) {
            $table->string('slip_barcode', 12)->change();
            $table->string('from', 50)->nullable()->change();
            $table->string('to', 50)->nullable()->change();
            $table->string('supply_address', 50)->nullable()->change();
            $table->string('next_supply_address', 50)->nullable()->change();
            $table->string('ms_id', 50)->nullable()->change();
            $table->string('inventory_category', 50)->nullable()->change();
            $table->string('part_no', 100)->nullable()->change();
            $table->string('part_name', 200)->nullable()->change();
            $table->string('color_code', 50)->nullable()->change();
            $table->string('ps_code', 50)->nullable()->change();
            $table->string('order_class', 50)->nullable()->change();
            $table->string('prod_seq_no', 100)->nullable()->change();
            $table->string('kd_lot_no', 100)->nullable()->change();
            $table->string('slip_no', 100)->nullable()->change();
            $table->string('hns', 10)->nullable()->change();
        });
    }
};