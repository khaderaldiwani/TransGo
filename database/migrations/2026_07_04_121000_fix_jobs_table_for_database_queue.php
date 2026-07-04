<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });

            return;
        }

        Schema::table('jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('jobs', 'queue')) {
                $table->string('queue')->default('default')->index()->after('id');
            }

            if (! Schema::hasColumn('jobs', 'payload')) {
                $table->longText('payload')->nullable()->after('queue');
            }

            if (! Schema::hasColumn('jobs', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('payload');
            }

            if (! Schema::hasColumn('jobs', 'reserved_at')) {
                $table->unsignedInteger('reserved_at')->nullable()->after('attempts');
            }

            if (! Schema::hasColumn('jobs', 'available_at')) {
                $table->unsignedInteger('available_at')->default(0)->after('reserved_at');
            }

            if (! Schema::hasColumn('jobs', 'created_at')) {
                $table->unsignedInteger('created_at')->default(0)->after('available_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }

        Schema::table('jobs', function (Blueprint $table) {
            foreach (['queue', 'payload', 'attempts', 'reserved_at', 'available_at'] as $column) {
                if (Schema::hasColumn('jobs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
