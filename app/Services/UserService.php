<?php

namespace App\Services;

use App\Models\BotUser;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\User as TelegramUser;

class UserService
{
    /**
     * Userni DB ga yozish yoki yangilash
     */
    public function findOrCreateUser(TelegramUser $telegramUser): BotUser
    {
        $user = BotUser::updateOrCreate(
            [
                'telegram_user_id' => $telegramUser->id,
            ],
            [
                'username' => $telegramUser->username ?? null,
                'first_name' => $telegramUser->firstName ?? $telegramUser->first_name ?? '',
                'last_name' => $telegramUser->lastName ?? $telegramUser->last_name ?? null,
                'is_bot' => $telegramUser->isBot ?? $telegramUser->is_bot ?? false,
                'status' => 'active',
            ]
        );

        return $user;
    }

    /**
     * Userni guruhga qo'shish
     */
    public function addUserToGroup(BotUser $user, TelegramGroup $group): void
    {
        // Agar user allaqachon guruhda bo'lsa va left_at null bo'lsa, hech narsa qilmaymiz
        $existingPivot = $group->users()
            ->where('bot_users.id', $user->id)
            ->whereNull('group_user.left_at')
            ->first();

        if ($existingPivot) {
            return;
        }

        $leftPivot = $group->users()
            ->where('bot_users.id', $user->id)
            ->whereNotNull('group_user.left_at')
            ->first();

        if ($leftPivot) {
            $group->users()->updateExistingPivot($user->id, [
                'joined_at' => now(),
                'left_at' => null,
            ]);
            // Count ni yangilash
            $this->updateMembersCount($group);
        } else {
            $group->users()->attach($user->id, [
                'joined_at' => now(),
            ]);
            // Count ni yangilash
            $this->updateMembersCount($group);
        }
    }

    /**
     * Userni guruhdan chiqarish
     */
    public function removeUserFromGroup(BotUser $user, TelegramGroup $group): void
    {
        // User guruhda bo'lsa va hali chiqmagan bo'lsa
        $pivot = $group->users()
            ->where('bot_users.id', $user->id)
            ->whereNull('group_user.left_at')
            ->first();

        if ($pivot) {
            // Faqat left_at ni yangilash
            $group->users()->updateExistingPivot($user->id, [
                'left_at' => now(),
            ]);
        } else {
            // Agar user guruhda bo'lmasa yoki allaqachon chiqib ketgan bo'lsa,
            // yangi record yaratish (joined_at va left_at bir vaqtda)
            $existingPivot = $group->users()
                ->where('bot_users.id', $user->id)
                ->first();

            if (!$existingPivot) {
                // Yangi user, lekin chiqib ketgan (masalan, admin tomonidan chiqarilgan)
                $group->users()->attach($user->id, [
                    'joined_at' => now()->subMinutes(1), // Taxminiy joined_at
                    'left_at' => now(),
                ]);
            }
        }

        $user->update(['status' => 'left']);
        
        // Count ni yangilash
        $this->updateMembersCount($group);
    }

    /**
     * Guruhdagi a'zolar sonini yangilash
     */
    private function updateMembersCount(TelegramGroup $group): void
    {
        $count = $group->users()
            ->whereNull('group_user.left_at')
            ->count();

        $group->update(['chat_members_count' => $count]);
    }
}
