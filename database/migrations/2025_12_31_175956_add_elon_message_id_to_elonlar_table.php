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
            $table->string('elon_message_id')->nullable()->after('sold_feedback')->comment('Kanalga joylangan xabar ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('elonlar', function (Blueprint $table) {
            $table->dropColumn('elon_message_id');
        });
    }
};
