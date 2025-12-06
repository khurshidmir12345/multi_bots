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
        Schema::create('telegram_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('bots')->onDelete('cascade');
            $table->bigInteger('telegram_group_id')->comment('Guruh chat_id');
            $table->string('title')->nullable()->comment('Guruh nomi');
            $table->string('type')->comment('group / supergroup');
            $table->boolean('status')->default(true)->comment('active / left');
            $table->timestamps();
            
            // Index qo'shish
            $table->index('bot_id');
            $table->index('telegram_group_id');
            $table->unique(['bot_id', 'telegram_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_groups');
    }
};
