<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Chat;

class GroupService
{
    /**
     * Bot guruhga qo'shilgan payt guruhni DB ga yozish
     */
    public function handleBotJoined(Bot $bot, Chat $chat): TelegramGroup
    {
        return TelegramGroup::updateOrCreate(
            [
                'bot_id' => $bot->id,
                'telegram_group_id' => $chat->id,
            ],
            [
                'title' => $chat->title,
                'type' => $chat->type,
                'status' => true,
            ]
        );
    }

    /**
     * Bot guruhdan chiqarilgan payt statusni false qilish
     */
    public function handleBotLeft(Bot $bot, int $telegramGroupId): bool
    {
        $group = TelegramGroup::where('bot_id', $bot->id)
            ->where('telegram_group_id', $telegramGroupId)
            ->first();

        if (!$group) {
            return false;
        }

        $group->update(['status' => false]);
        return true;
    }

    /**
     * Guruhni topish yoki yaratish
     */
    public function findOrCreateGroup(Bot $bot, Chat $chat): TelegramGroup
    {
        $group = TelegramGroup::firstOrCreate(
            [
                'bot_id' => $bot->id,
                'telegram_group_id' => $chat->id,
            ],
            [
                'title' => $chat->title,
                'type' => $chat->type,
                'status' => true,
                'chat_members_count' => 0,
            ]
        );

        return $group;
    }

    /**
     * Guruhdagi a'zolar sonini yangilash (DB'dan)
     */
    public function updateMembersCount(TelegramGroup $group): void
    {
        $count = $group->users()
            ->whereNull('group_user.left_at')
            ->count();

        $group->update(['chat_members_count' => $count]);
    }

    /**
     * Telegram API'dan guruhdagi a'zolar sonini olish
     */
    public function getChatMembersCountFromTelegram(Bot $bot, int $telegramGroupId): ?int
    {
        try {
            $telegram = new Api($bot->token);
            $result = $telegram->getChatMemberCount([
                'chat_id' => $telegramGroupId,
            ]);

            return $result ?? null;
        } catch (\Exception $e) {
            Log::error("Error getting chat members count", [
                'bot_id' => $bot->id,
                'telegram_group_id' => $telegramGroupId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Guruhdagi a'zolar sonini Telegram API'dan o'qib, DB ga yozish
     */
    public function syncMembersCountFromTelegram(Bot $bot, TelegramGroup $group): bool
    {
        $count = $this->getChatMembersCountFromTelegram($bot, $group->telegram_group_id);

        if ($count !== null) {
            $group->update(['chat_members_count' => $count]);
            return true;
        }

        return false;
    }
}
