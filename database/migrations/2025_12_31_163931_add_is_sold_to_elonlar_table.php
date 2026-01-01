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
        Schema::table('elonlar', function (Blueprint $table) {
            $table->boolean('is_sold')->default(false)->after('cancelled_from_user')->comment('Moshina sotildimi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('elonlar', function (Blueprint $table) {
            $table->dropColumn('is_sold');
        });
    }
};
