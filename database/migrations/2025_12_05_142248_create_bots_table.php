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
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique()->comment('Bot slug (bot1, bot2, ...)');
            $table->string('token')->unique()->comment('Telegram bot token');
            $table->string('name')->nullable()->comment('Bot nomi');
            $table->text('description')->nullable()->comment('Bot tavsifi');
            $table->string('webhook_url')->nullable()->comment('Webhook URL');
            $table->boolean('is_active')->default(true)->comment('Bot faolmi');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
