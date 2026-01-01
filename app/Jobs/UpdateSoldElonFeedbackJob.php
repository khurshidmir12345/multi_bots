<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Elon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class UpdateSoldElonFeedbackJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $elonId,
        public int $botId
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $elon = Elon::find($this->elonId);
        $bot = Bot::find($this->botId);

        if (!$elon || !$bot) {
            Log::error("UpdateSoldElonFeedbackJob: Elon or Bot not found", [
                'elon_id' => $this->elonId,
                'bot_id' => $this->botId,
            ]);
            return;
        }

        // Elon'ni refresh qilish (boshqa job tomonidan o'zgartirilgan bo'lishi mumkin)
        $elon->refresh();

        // Agar elon_message_id bo'lmasa, update qilish mumkin emas
        if (!$elon->elon_message_id) {
            Log::warning("UpdateSoldElonFeedbackJob: Elon message ID not found", [
                'elon_id' => $this->elonId,
            ]);
            return;
        }

        // Agar feedback bo'lmasa, update qilish kerak emas
        if (empty($elon->sold_feedback)) {
            Log::info("UpdateSoldElonFeedbackJob: No feedback to add", [
                'elon_id' => $this->elonId,
            ]);
            return;
        }

        // Agar allaqachon bekor qilingan yoki kanalga chiqmagan bo'lsa
        if ($elon->cancelled_from_admin || $elon->cancelled_from_user || $elon->status !== Elon::STATUS_COMPLATED) {
            Log::info("UpdateSoldElonFeedbackJob: Elon cancelled or not completed", [
                'elon_id' => $this->elonId,
                'status' => $elon->status,
            ]);
            return;
        }

        if (!$bot->channel_id) {
            Log::error("UpdateSoldElonFeedbackJob: Channel ID not found", [
                'bot_id' => $this->botId,
            ]);
            return;
        }

        try {
            $telegram = new Api($bot->token);
            $caption = $this->formatElonForChannelWithFeedback($elon, $bot);

            // Elonni update qilish
            $images = $elon->images()->get();
            
            try {
                if ($images->isNotEmpty()) {
                    // Agar rasm bo'lsa, caption'ni update qilish
                    $telegram->editMessageCaption([
                        'chat_id' => $bot->channel_id,
                        'message_id' => (int) $elon->elon_message_id,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    // Agar rasm bo'lmasa, text'ni update qilish
                    $telegram->editMessageText([
                        'chat_id' => $bot->channel_id,
                        'message_id' => (int) $elon->elon_message_id,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                }
            } catch (\Exception $e) {
                // Agar "message is not modified" xatosi bo'lsa, bu normal holat (matn bir xil)
                if (str_contains($e->getMessage(), 'message is not modified')) {
                    Log::info("UpdateSoldElonFeedbackJob: Message not modified (content is the same)", [
                        'elon_id' => $this->elonId,
                        'message_id' => $elon->elon_message_id,
                    ]);
                    return; // Job muvaffaqiyatli tugadi
                } elseif (str_contains($e->getMessage(), 'message to edit not found') || 
                          str_contains($e->getMessage(), 'message_id is invalid') ||
                          str_contains($e->getMessage(), 'Bad Request: message to edit not found')) {
                    // Agar message topilmasa, bu xatolik - lekin job tugadi
                    Log::warning("UpdateSoldElonFeedbackJob: Message not found (may be deleted or message_id changed)", [
                        'elon_id' => $this->elonId,
                        'message_id' => $elon->elon_message_id,
                        'error' => $e->getMessage(),
                    ]);
                    return; // Job tugadi, lekin xatolik bor
                } else {
                    // Boshqa xatolik bo'lsa, qayta throw qilish
                    throw $e;
                }
            }

            Log::info("UpdateSoldElonFeedbackJob: Elon updated with feedback successfully", [
                'elon_id' => $this->elonId,
                'channel_id' => $bot->channel_id,
                'message_id' => $elon->elon_message_id,
            ]);
        } catch (\Exception $e) {
            Log::error("UpdateSoldElonFeedbackJob: Error updating elon", [
                'elon_id' => $this->elonId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Kanal uchun elon formatlash (HTML format) - Feedback bilan
     */
    private function formatElonForChannelWithFeedback(Elon $elon, Bot $bot): string
    {
        // Eng tepaga #sotildi qo'shish
        $text = "<b>#sotildi</b>\n\n";
        
        // Mijoz tavsifini qo'shish (agar bo'lsa)
        if (!empty($elon->sold_feedback)) {
            $text .= "<b>Mijoz tavsifi:</b> " . htmlspecialchars($elon->sold_feedback) . "\n\n";
        }
        
        $text .= "🚗 <b>Moshina:</b> " . htmlspecialchars($elon->modeli ?? '-') . "\n";
        $text .= "🔧 <b>Karobka:</b> " . htmlspecialchars($elon->pozitsiyasi ?? '-') . "\n";
        $text .= "🎨 <b>Rangi:</b> " . htmlspecialchars($elon->rangi ?? '-') . "\n";
        $text .= "🖌️ <b>Kraskasi:</b> " . htmlspecialchars($elon->kraskasi ?? '-') . "\n";
        $text .= "📆 <b>Yil:</b> " . ($elon->yili ?? '-') . "\n";
        $text .= "📏 <b>Probeg:</b> " . ($elon->yurgani ? number_format($elon->yurgani, 0, '.', ' ') : '-') . "\n";
        $text .= "⛽ <b>Yoqilg'i:</b> " . htmlspecialchars($elon->yoqilgisi ?? '-') . "\n";
        
        // Narx va valyuta
        if ($elon->narxi) {
            $currencySymbol = $elon->currency === 'dollar' ? '$' : '';
            $currencyText = $elon->currency === 'dollar' ? '' : ' so\'m';
            $text .= "💰 <b>Narxi:</b> " . $currencySymbol . number_format($elon->narxi, 0, '.', ' ') . $currencyText . "\n";
        }
        
        // Telefon raqami o'rniga #sotildi
        $text .= "📞 <b>Tel:</b> #sotildi\n";
        if ($elon->tel_2) {
            $text .= "📞 <b>Tel 2:</b> #sotildi\n";
        }
        $text .= "📍 <b>Manzil:</b> " . htmlspecialchars($elon->manzil ?? '-') . "\n";
        
        // Bot username'ni olish
        $botUsername = null;
        try {
            $telegram = new Api($bot->token);
            $botInfo = $telegram->getMe();
            $botUsername = $botInfo->username ?? null;
        } catch (\Exception $e) {
            Log::warning("UpdateSoldElonFeedbackJob: Failed to get bot username", [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Bot username qo'shish
        if ($botUsername) {
            $text .= "\nElon berish bepul :\n@" . htmlspecialchars($botUsername);
        }

        // 1024 belgi limit (Telegram caption limit)
        if (mb_strlen($text) > 1024) {
            $text = mb_substr($text, 0, 1020) . '...';
        }

        return $text;
    }
}
