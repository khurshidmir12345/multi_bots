<?php

namespace App\Console\Commands;

use App\Models\Bot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SetElonBotWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:set-elon-webhook {url?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Elon bot uchun webhook o\'rnatish';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // elon_bot slug'iga ega botni topish
        $bot = Bot::bySlug('elon_bot')->first();

        if (!$bot) {
            $this->error('elon_bot slug\'iga ega bot topilmadi!');
            return 1;
        }

        $this->info("Bot topildi: ID={$bot->id}, Slug={$bot->slug}, Type={$bot->type}");

        // Webhook URL'ni aniqlash
        $webhookUrl = $this->argument('url') 
            ?: 'https://50af2cd8a27c.ngrok-free.app/api/bot/elon_bot/elon/webhook';

        $this->info("Webhook URL: {$webhookUrl}");

        // Bot'ning webhook_url'ini yangilash
        $bot->webhook_url = $webhookUrl;
        $bot->save();

        $this->info("Bot'ning webhook_url yangilandi: {$bot->webhook_url}");

        // Telegram API'ga webhook o'rnatish
        try {
            $this->info('Telegram API\'ga webhook o\'rnatilmoqda...');
            
            $response = Http::post("https://api.telegram.org/bot{$bot->token}/setWebhook", [
                'url' => $webhookUrl,
            ]);

            $result = $response->json();

            if ($result['ok'] ?? false) {
                $this->info('✓ Webhook muvaffaqiyatli o\'rnatildi!');
                $this->line("Description: " . ($result['description'] ?? 'N/A'));
                
                if (isset($result['result'])) {
                    $this->line("Result: " . ($result['result'] ? 'true' : 'false'));
                }
                
                Log::info('Elon bot webhook o\'rnatildi', [
                    'bot_id' => $bot->id,
                    'bot_slug' => $bot->slug,
                    'webhook_url' => $webhookUrl,
                ]);
                
                return 0;
            } else {
                $this->error('✗ Webhook o\'rnatilmadi!');
                $this->error("Xatolik: " . ($result['description'] ?? 'Noma\'lum xatolik'));
                
                Log::error('Elon bot webhook o\'rnatilmadi', [
                    'bot_id' => $bot->id,
                    'bot_slug' => $bot->slug,
                    'webhook_url' => $webhookUrl,
                    'error' => $result['description'] ?? 'Noma\'lum xatolik',
                ]);
                
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('✗ Xatolik yuz berdi: ' . $e->getMessage());
            
            Log::error('Elon bot webhook o\'rnatishda xatolik', [
                'bot_id' => $bot->id,
                'bot_slug' => $bot->slug,
                'webhook_url' => $webhookUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return 1;
        }
    }
}
