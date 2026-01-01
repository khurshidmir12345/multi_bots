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
            $table->text('sold_feedback')->nullable()->after('is_sold')->comment('Foydalanuvchi fikri moshina sotilganda');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('elonlar', function (Blueprint $table) {
            $table->dropColumn('sold_feedback');
        });
    }
};
