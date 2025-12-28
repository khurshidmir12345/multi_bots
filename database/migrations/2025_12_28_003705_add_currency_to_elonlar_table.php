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
            $table->string('currency')->default('so\'m')->after('narxi')->comment('Valyuta: so\'m, dollar');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('elonlar', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
