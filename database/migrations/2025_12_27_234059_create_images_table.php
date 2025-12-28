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
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('elon_user_id')->constrained('elon_users')->onDelete('cascade')->comment('Elon user id');
            $table->foreignId('elon_id')->constrained('elonlar')->onDelete('cascade')->comment('Elon id');
            $table->string('image_url')->nullable()->comment('Image URL');
            $table->string('image_path')->nullable()->comment('Image path');
            $table->timestamps();
            
            // Index qo'shish
            $table->index('elon_user_id');
            $table->index('elon_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
