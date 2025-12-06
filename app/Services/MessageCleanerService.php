<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class MessageCleanerService
{
    /**
     * Guruhdan "joined" va "left" xabarlarini tozalash
     */
    public function cleanJoinLeaveMessages(Bot $bot, TelegramGroup $group): void
    {
        try {
            $telegram = new Api($bot->token);
            $chatId = $group->telegram_group_id;

            // Bot admin ekanligini tekshirish
            $botMember = $telegram->getChatMember([
                'chat_id' => $chatId,
                'user_id' => $telegram->getMe()->id,
            ]);

            if (!in_array($botMember->status, ['administrator', 'creator'])) {
                Log::warning("Bot is not admin, cannot delete messages", [
                    'bot_id' => $bot->id,
                    'group_id' => $group->id,
                    'status' => $botMember->status,
                ]);
                return;
            }

            // Bu yerda keyinchalik joined/left xabarlarni topib o'chirish logikasi qo'shiladi
            // Hozircha faqat struktura yaratildi
            
            Log::info("Message cleaning initiated", [
                'bot_id' => $bot->id,
                'group_id' => $group->id,
            ]);

        } catch (\Exception $e) {
            Log::error("Error cleaning messages", [
                'bot_id' => $bot->id,
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bitta xabarni o'chirish
     */
    public function deleteMessage(Bot $bot, int $chatId, int $messageId): bool
    {
        try {
            $telegram = new Api($bot->token);
            $telegram->deleteMessage([
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
            return true;
        } catch (\Exception $e) {
            // Faqat error log (bot admin emas yoki boshqa muammo)
            return false;
        }
    }

    /**
     * Xabarda "joined" yoki "left" matni borligini tekshirish
     */
    public function isJoinLeaveMessage($message): bool
    {
        if (!$message) {
            return false;
        }

        // new_chat_members, left_chat_member yoki left_chat_participant bo'lsa, bu join/leave xabari
        if (isset($message->newChatMembers) || isset($message->leftChatMember) || isset($message->leftChatParticipant)) {
            return true;
        }

        $text = $message->text ?? $message->caption ?? null;
        
        if (empty($text) && is_object($message)) {
            if (method_exists($message, 'getText')) {
                $text = $message->getText();
            } elseif (method_exists($message, 'getCaption')) {
                $text = $message->getCaption();
            }
        }
        
        if (empty($text)) {
            return false;
        }

        $text = strtolower(trim($text));
        
        // Patternlar (static qilib optimallashtirilgan)
        static $joinPatterns = [
            'joined', 'joined the group', 'joined the chat',
            'qo\'shildi', 'qoshildi', 'qo\'shilgan', 'qoshilgan',
            'guruhga qo\'shildi', 'guruhga qoshildi',
            'via invite link', 'invite link orqali', 'invite link bilan',
            'added', // Admin tomonidan qo'shilgan
        ];

        static $leavePatterns = [
            'left the group', 'left the chat', 'left',
            'chiqdi', 'chiqib ketdi', 'ketdi',
            'guruhdan chiqdi', 'guruhni tark etdi',
            'removed', // Admin tomonidan chiqarilgan
        ];

        foreach ($joinPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        foreach ($leavePatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
