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
        Schema::create('elon_users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->comment('Telegram chat id');
            $table->string('name')->nullable()->comment('User name');
            $table->string('user_name')->nullable()->comment('Telegram username');
            $table->string('current_step')->nullable()->comment('Current step in bot flow');
            $table->bigInteger('last_message_id')->nullable()->comment('Last message id');
            $table->timestamps();
            $table->softDeletes();
            
            // Index qo'shish
            $table->index('chat_id');
            $table->index('current_step');
            $table->unique('chat_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('elon_users');
    }
};
