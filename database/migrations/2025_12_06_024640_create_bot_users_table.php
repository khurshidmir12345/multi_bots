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
        Schema::create('bot_users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->comment('Telegram user id');
            $table->string('username')->nullable()->comment('Username');
            $table->string('first_name')->comment('First name');
            $table->string('last_name')->nullable()->comment('Last name');
            $table->boolean('is_bot')->default(false)->comment('Is bot');
            $table->string('status')->default('active')->comment('active / banned / left');
            $table->timestamps();
            
            // Index qo'shish
            $table->index('telegram_user_id');
            $table->index('status');
            $table->unique('telegram_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_users');
    }
};
