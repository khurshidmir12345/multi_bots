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
            $table->boolean('cancelled_from_admin')->default(false)->after('status')->comment('Admin tomonidan bekor qilingan');
            $table->boolean('cancelled_from_user')->default(false)->after('cancelled_from_admin')->comment('Foydalanuvchi tomonidan bekor qilingan');
            $table->index('cancelled_from_admin');
            $table->index('cancelled_from_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('elonlar', function (Blueprint $table) {
            $table->dropIndex(['cancelled_from_admin']);
            $table->dropIndex(['cancelled_from_user']);
            $table->dropColumn(['cancelled_from_admin', 'cancelled_from_user']);
        });
    }
};
