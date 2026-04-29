<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governorate_aliases', function (Blueprint $table) {
            $table->id('alias_id');
            $table->foreignId('governorate_id')
                ->constrained('governorates', 'governorate_id')
                ->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->unique();
            $table->timestamps();

            $table->index('governorate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governorate_aliases');
    }
};
