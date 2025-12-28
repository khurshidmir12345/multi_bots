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
        Schema::create('elonlar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('elon_user_id')->constrained('elon_users')->onDelete('cascade')->comment('Elon user id');
            $table->string('modeli')->nullable()->comment('Model');
            $table->string('pozitsiyasi')->nullable()->comment('Pozitsiya');
            $table->string('rangi')->nullable()->comment('Rang');
            $table->string('kraskasi')->nullable()->comment('Kraskasi');
            $table->integer('yili')->nullable()->comment('Yil');
            $table->integer('yurgani')->nullable()->comment('Yurgan masofa');
            $table->string('yoqilgisi')->nullable()->comment('Yoqilg\'i turi');
            $table->decimal('narxi', 15, 2)->nullable()->comment('Narx');
            $table->string('tel_1')->nullable()->comment('Telefon raqam 1');
            $table->string('tel_2')->nullable()->comment('Telefon raqam 2');
            $table->text('manzil')->nullable()->comment('Manzil');
            $table->enum('status', ['ended', 'accepted_user', 'sended_to_admin', 'accepted_admin', 'complated'])
                ->default('accepted_user')
                ->comment('Status: ended, accepted_user, sended_to_admin, accepted_admin, complated');
            $table->timestamps();
            $table->softDeletes();
            
            // Index qo'shish
            $table->index('elon_user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('elonlar');
    }
};
