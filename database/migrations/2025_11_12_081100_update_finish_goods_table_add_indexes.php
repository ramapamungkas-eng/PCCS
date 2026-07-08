<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "finish_goods" ALTER COLUMN "type" TYPE varchar(255)');
            DB::statement('ALTER TABLE "finish_goods" DROP CONSTRAINT IF EXISTS "finish_goods_type_check"');
            DB::statement("ALTER TABLE \"finish_goods\" ADD CONSTRAINT \"finish_goods_type_check\" CHECK (\"type\" in ('ASSY', 'DIRECT'))");
            DB::statement("ALTER TABLE \"finish_goods\" ALTER COLUMN \"type\" SET DEFAULT 'ASSY'");
            DB::statement('ALTER TABLE "finish_goods" ALTER COLUMN "type" SET NOT NULL');
        } else {
            Schema::table('finish_goods', function (Blueprint $table) {
                // Fix default value for type column from 'FG' to 'ASSY'
                $table->enum('type', ['ASSY', 'DIRECT'])->default('ASSY')->change();
            });
        }

        Schema::table('finish_goods', function (Blueprint $table) {
            // Add indexes for performance
            $table->index(['customer_id', 'part_number'], 'idx_customer_part'); // For duplicate detection in import
            $table->index('part_number', 'idx_part_number'); // For search queries
            $table->index('type', 'idx_type'); // For filtering by type
            $table->index('is_active', 'idx_is_active'); // For filtering active/inactive
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finish_goods', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex('idx_customer_part');
            $table->dropIndex('idx_part_number');
            $table->dropIndex('idx_type');
            $table->dropIndex('idx_is_active');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "finish_goods" ALTER COLUMN "type" TYPE varchar(255)');
            DB::statement('ALTER TABLE "finish_goods" DROP CONSTRAINT IF EXISTS "finish_goods_type_check"');
            DB::statement("ALTER TABLE \"finish_goods\" ADD CONSTRAINT \"finish_goods_type_check\" CHECK (\"type\" in ('ASSY', 'DIRECT'))");
            DB::statement("ALTER TABLE \"finish_goods\" ALTER COLUMN \"type\" SET DEFAULT 'ASSY'");
            DB::statement('ALTER TABLE "finish_goods" ALTER COLUMN "type" SET NOT NULL');
        } else {
            Schema::table('finish_goods', function (Blueprint $table) {
                // Revert type default back to 'FG'
                $table->enum('type', ['ASSY', 'DIRECT'])->default('ASSY')->change();
            });
        }
    }
};
