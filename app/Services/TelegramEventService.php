<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\BotUser;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\Update;

class TelegramEventService
{
    public function __construct(
        private GroupService $groupService,
        private UserService $userService,
        private MessageCleanerService $messageCleanerService
    ) {
    }

    /**
     * Telegram update'ni qayta ishlash
     */
    public function handleUpdate(Bot $bot, Update $update): void
    {
        try {
            if ($update->myChatMember) {
                $this->handleMyChatMember($bot, $update->myChatMember);
                return;
            }

            if ($update->message) {
                if ($update->message->newChatMembers) {
                    $this->handleNewChatMembers($bot, $update->message);
                    return;
                }

                // left_chat_member yoki left_chat_participant
                if ($update->message->leftChatMember || $update->message->leftChatParticipant) {
                    $this->handleLeftChatMember($bot, $update->message);
                    return;
                }

                if ($this->messageCleanerService->isJoinLeaveMessage($update->message)) {
                    $this->handleJoinLeaveMessage($bot, $update->message);
                }
            }
        } catch (\Exception $e) {
            Log::error("Error handling update", [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Bot guruhga qo'shilgan yoki chiqarilgan event
     */
    private function handleMyChatMember(Bot $bot, $myChatMember): void
    {
        $chat = $myChatMember->chat;
        $newStatus = $myChatMember->newChatMember->status;
        $oldStatus = $myChatMember->oldChatMember->status ?? null;

        // Faqat guruhlar bilan ishlaymiz
        if (!in_array($chat->type, ['group', 'supergroup'])) {
            return;
        }

        if ($newStatus === 'member' && $oldStatus !== 'member') {
            $this->groupService->handleBotJoined($bot, $chat);
        } elseif ($newStatus === 'left' || $newStatus === 'kicked') {
            $this->groupService->handleBotLeft($bot, $chat->id);
        }
    }

    /**
     * User guruhga qo'shilgan event
     */
    private function handleNewChatMembers(Bot $bot, $message): void
    {
        $chat = $message->chat;
        
        // Faqat guruhlar bilan ishlaymiz
        if (!in_array($chat->type, ['group', 'supergroup'])) {
            return;
        }

        // Guruhni topish yoki yaratish
        $group = $this->groupService->findOrCreateGroup($bot, $chat);

        foreach ($message->newChatMembers as $telegramUser) {
            if ($telegramUser->isBot ?? false) {
                continue;
            }

            $user = $this->userService->findOrCreateUser($telegramUser);
            $this->userService->addUserToGroup($user, $group);
        }

        // Joined xabarni tozalash (bir marta)
        $messageId = $this->getMessageId($message);
        if ($messageId) {
            $this->messageCleanerService->deleteMessage($bot, $chat->id, $messageId);
        }
    }

    /**
     * User guruhdan chiqishi event
     */
    private function handleLeftChatMember(Bot $bot, $message): void
    {
        $chat = $message->chat;
        // left_chat_member yoki left_chat_participant
        $telegramUser = $message->leftChatMember ?? $message->leftChatParticipant;

        if (!in_array($chat->type, ['group', 'supergroup']) || !$telegramUser) {
            return;
        }

        // Guruhni topish yoki yaratish
        $group = $this->groupService->findOrCreateGroup($bot, $chat);

        // Userni topish yoki yaratish (chiqib ketgan bo'lsa ham, ma'lumotni saqlash uchun)
        $user = $this->userService->findOrCreateUser($telegramUser);

        // Userni guruhdan chiqarish
        $this->userService->removeUserFromGroup($user, $group);

        // Xabarni o'chirish
        $messageId = $this->getMessageId($message);
        if ($messageId) {
            $this->messageCleanerService->deleteMessage($bot, $chat->id, $messageId);
        }
    }

    /**
     * "joined the group" yoki "left the group" kabi xabarlarni handle qilish
     */
    private function handleJoinLeaveMessage(Bot $bot, $message): void
    {
        $chat = $message->chat;

        if (!in_array($chat->type, ['group', 'supergroup'])) {
            return;
        }

        $messageId = $this->getMessageId($message);
        if ($messageId) {
            $this->messageCleanerService->deleteMessage($bot, $chat->id, $messageId);
        }
    }

    /**
     * Message ID ni olish (optimized)
     */
    private function getMessageId($message): ?int
    {
        return $message->messageId 
            ?? $message->message_id 
            ?? (method_exists($message, 'getMessageId') ? $message->getMessageId() : null)
            ?? null;
    }
}
