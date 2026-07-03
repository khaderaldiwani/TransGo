<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_categories', function (Blueprint $table) {
            $table->id('category_id');
            $table->string('name')->unique();
            $table->decimal('price_per_km', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('vehicle_categories')->insert([
            ['name' => 'كلاسيك فوري', 'price_per_km' => 87.20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'كومفورت مكيف', 'price_per_km' => 93.30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'فان عائلات', 'price_per_km' => 97.70, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'تكسي صفراء', 'price_per_km' => 84.50, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'قمة الرفاهية VIP', 'price_per_km' => 105.20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('vehicle_category_id')
                ->nullable()
                ->after('driver_id')
                ->constrained('vehicle_categories', 'category_id')
                ->nullOnDelete();
        });

        $vipCategoryId = DB::table('vehicle_categories')
            ->where('name', 'قمة الرفاهية VIP')
            ->value('category_id');

        if ($vipCategoryId !== null) {
            DB::table('vehicles')
                ->whereNull('vehicle_category_id')
                ->update(['vehicle_category_id' => $vipCategoryId]);
        }
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_category_id');
        });

        Schema::dropIfExists('vehicle_categories');
    }
};
