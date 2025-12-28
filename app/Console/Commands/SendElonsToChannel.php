<?php

namespace App\Console\Commands;

use App\Jobs\SendElonToChannelJob;
use App\Models\Bot;
use App\Models\Elon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendElonsToChannel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elons:send-to-channel';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tasdiqlangan elonlarni kanalga yuborish';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Elonlarni tekshirish boshlandi...');

        // Elon bot'ni topish
        $bot = Bot::where('type', 'elon')->active()->first();

        if (!$bot) {
            $this->error('Elon bot topilmadi!');
            $this->warn('Tekshirish: type="elon" va is_active=true bo\'lgan bot kerak');
            Log::warning('SendElonsToChannel: Elon bot not found');
            return 1;
        }

        $this->info("Bot topildi: ID={$bot->id}, Name={$bot->name}");

        if (!$bot->channel_id) {
            $this->error('Bot\'da channel_id topilmadi!');
            Log::warning('SendElonsToChannel: Channel ID not found', ['bot_id' => $bot->id]);
            return 1;
        }

        $this->info("Channel ID: {$bot->channel_id}");

        // Yuborilishi kerak bo'lgan elonlarni topish
        // accepted_admin status'da, cancelled bo'lmagan, complated bo'lmagan va rasmlari bo'lgan elonlar
        $elons = Elon::where('status', Elon::STATUS_ACCEPTED_ADMIN)
            ->where('cancelled_from_admin', false)
            ->where('cancelled_from_user', false)
            ->whereHas('images')
            ->get();

        $count = $elons->count();

        if ($count === 0) {
            $this->info('Yuborilishi kerak bo\'lgan elonlar topilmadi.');
            $this->warn('Tekshirish: status="accepted_admin", cancelled=false, va rasmlari bo\'lgan elonlar kerak');
            
            // Debug ma'lumotlari
            $totalAccepted = Elon::where('status', Elon::STATUS_ACCEPTED_ADMIN)->count();
            $totalWithImages = Elon::whereHas('images')->count();
            $this->line("Jami accepted_admin elonlar: {$totalAccepted}");
            $this->line("Jami rasmlari bo'lgan elonlar: {$totalWithImages}");
            
            return 0;
        }

        $this->info("{$count} ta elon topildi. Job'larga yuborilmoqda...");

        // Har bir elon uchun job yaratish
        foreach ($elons as $elon) {
            $imageCount = $elon->images()->count();
            $this->line("Elon #{$elon->id} - {$imageCount} ta rasm");
            
            SendElonToChannelJob::dispatch($elon->id, $bot->id);
            $this->line("  ✓ Job'ga qo'shildi");
        }

        $this->info("Barcha elonlar job'larga yuborildi!");
        $this->warn('Eslatma: Job\'lar ishlashi uchun queue worker ishlashi kerak: php artisan queue:work');
        Log::info("SendElonsToChannel: {$count} elons dispatched to jobs");

        return 0;
    }
}
