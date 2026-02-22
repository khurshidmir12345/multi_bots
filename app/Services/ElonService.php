<?php

namespace App\Services;

use App\Jobs\SendElonToChannelJob;
use App\Models\Bot;
use App\Models\Elon;
use App\Models\ElonUser;
use App\Models\Image;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\Update;

class ElonService
{
    private Api $telegram;

    public function __construct(
        private Bot $bot
    ) {
        $this->telegram = new Api($bot->token);
    }

    /**
     * Admin'ga xatolik xabari yuborish
     */
    private function notifyAdminError(string $message, array $context = []): void
    {
        try {
            $adminChatIds = config('elon.admin_chat_ids', []);
            if (empty($adminChatIds)) {
                return;
            }

            $errorText = "⚠️ *Xatolik yuz berdi*\n\n";
            $errorText .= $message;
            
            if (!empty($context)) {
                $errorText .= "\n\n*Tafsilotlar:*\n";
                foreach ($context as $key => $value) {
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    $errorText .= "• *{$key}*: " . (string)$value . "\n";
                }
            }

            foreach ($adminChatIds as $adminChatId) {
                try {
                    $this->telegram->sendMessage([
                        'chat_id' => $adminChatId,
                        'text' => $errorText,
                        'parse_mode' => 'Markdown',
                    ]);
                } catch (\Exception $e) {
                    // Admin'ga xabar yuborishda xatolik bo'lsa, hech narsa qilmaymiz
                }
            }
        } catch (\Exception $e) {
            // Xatolik bo'lsa, hech narsa qilmaymiz
        }
    }

    /**
     * Update'ni handle qilish
     */
    public function handleUpdate(Update $update): void
    {
        try {
            if ($update->callbackQuery) {
                $this->handleCallbackQuery($update->callbackQuery);
                return;
            }

            if ($update->message) {
                $this->handleMessage($update->message);
            }
        } catch (\Exception $e) {
            $this->notifyAdminError("Update handle qilishda xatolik", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Message'ni handle qilish
     */
    private function handleMessage(Message $message): void
    {
        try {
            $chatId = $message->chat->id;
            $text = $message->text ?? '';
            $photo = $message->photo;

            $user = ElonUser::firstOrCreate(
            ['chat_id' => $chatId],
            [
                'name' => $message->from->firstName ?? '',
                'user_name' => $message->from->username ?? null,
                'current_step' => 'start',
            ]
        );

        $needsUpdate = false;
        if ($message->from->firstName && $user->name !== $message->from->firstName) {
            $user->name = $message->from->firstName;
            $needsUpdate = true;
        }
        if ($message->from->username && $user->user_name !== $message->from->username) {
            $user->user_name = $message->from->username;
            $needsUpdate = true;
        }
        if ($needsUpdate) {
            $user->save();
        }

        // Command'lar
        if ($text === '/start') {
            $this->handleStart($user, $message);
            return;
        }

        // Rasm yuborilgan bo'lsa
        if ($photo) {
            $this->handlePhoto($user, $message);
            return;
        }

            // Step bo'yicha javob qabul qilish
            $this->handleStep($user, $message, $text);
        } catch (\Exception $e) {
            $this->notifyAdminError("Message handle qilishda xatolik", [
                'chat_id' => $message->chat->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start command
     */
    private function handleStart(ElonUser $user, Message $message): void
    {
        $user->current_step = 'modeli';
        $user->save();

        $text = "🚗 *Yangi elon yaratish*\n\n";
        $text .= "Salom! Mashina eloni yaratish uchun quyidagi ma'lumotlarni to'ldiring.\n\n";
        $text .= "Har bir savolga javob bering va elonni muvaffaqiyatli yuboring! ✨";

        $this->sendMessage($message->chat->id, $text);
        $this->askModeli($message->chat->id);
    }

    /**
     * Step bo'yicha javob qabul qilish
     */
    private function handleStep(ElonUser $user, Message $message, string $text): void
    {
        $step = $user->current_step;

        if (!$step || $step === 'start') {
            $this->handleStart($user, $message);
            return;
        }

        // Faol elon topish yoki yaratish
        $elon = $user->elonlar()
            ->where('status', Elon::STATUS_ACCEPTED_USER)
            ->latest()
            ->first();

        if (!$elon) {
            $elon = Elon::create([
                'elon_user_id' => $user->id,
                'status' => Elon::STATUS_ACCEPTED_USER,
            ]);
        }

        switch ($step) {
            case 'modeli':
                $elon->modeli = $text;
                $elon->save();
                $user->current_step = 'pozitsiyasi';
                $user->save();
                $this->askPozitsiyasi($message->chat->id);
                break;

            case 'pozitsiyasi':
                $elon->pozitsiyasi = $text;
                $elon->save();
                $user->current_step = 'rangi';
                $user->save();
                $this->askRangi($message->chat->id);
                break;

            case 'rangi':
                $elon->rangi = $text;
                $elon->save();
                $user->current_step = 'kraskasi';
                $user->save();
                $this->askKraskasi($message->chat->id);
                break;

            case 'kraskasi':
                $elon->kraskasi = $text;
                $elon->save();
                $user->current_step = 'yili';
                $user->save();
                $this->askYili($message->chat->id);
                break;

            case 'yili':
                $yil = (int) $text;
                if ($yil > 1900 && $yil <= date('Y') + 1) {
                    $elon->yili = $yil;
                    $elon->save();
                    $user->current_step = 'yurgani';
                    $user->save();
                    $this->askYurgani($message->chat->id);
                } else {
                    $this->sendMessage($message->chat->id, "❌ Noto'g'ri yil! Iltimos, to'g'ri yil kiriting (masalan: 2020)");
                }
                break;

            case 'yurgani':
                $yurgani = (int) preg_replace('/[^0-9]/', '', $text);
                // Maksimal qiymat: 999,999,999 km (1 milliard km - juda ko'p, lekin realistik)
                $maxYurgani = 999999999;
                if ($yurgani > 0 && $yurgani <= $maxYurgani) {
                    $elon->yurgani = $yurgani;
                    $elon->save();
                    $user->current_step = 'yoqilgisi';
                    $user->save();
                    $this->askYoqilgisi($message->chat->id);
                } else {
                    if ($yurgani > $maxYurgani) {
                        $this->sendMessage($message->chat->id, "❌ Masofa juda katta! Iltimos, 999,999,999 km dan kichikroq raqam kiriting (masalan: 50000)");
                    } else {
                        $this->sendMessage($message->chat->id, "❌ Noto'g'ri masofa! Iltimos, raqam kiriting (masalan: 50000)");
                    }
                }
                break;

            case 'yoqilgisi':
                $elon->yoqilgisi = $text;
                $elon->save();
                $user->current_step = 'narxi';
                $user->save();
                $this->askNarxi($message->chat->id);
                break;

            case 'narxi':
                // Valyutani aniqlash ($ yoki so'm)
                $currency = 'so\'m';
                $textLower = strtolower($text);
                if (str_contains($textLower, '$') || str_contains($textLower, 'dollar') || str_contains($textLower, 'usd')) {
                    $currency = 'dollar';
                }
                
                $narxi = (float) preg_replace('/[^0-9.]/', '', $text);
                if ($narxi > 0) {
                    $elon->narxi = $narxi;
                    $elon->currency = $currency;
                    $elon->save();
                    $user->current_step = 'tel_1';
                    $user->save();
                    $this->askTel1($message->chat->id);
                } else {
                    $this->sendMessage($message->chat->id, "❌ Noto'g'ri narx! Iltimos, to'g'ri narx kiriting (masalan: 15000000 yoki \$5000)");
                }
                break;

            case 'tel_1':
                $elon->tel_1 = $text;
                $elon->save();
                $user->current_step = 'tel_2';
                $user->save();
                $this->askTel2($message->chat->id);
                break;

            case 'tel_2':
                $elon->tel_2 = $text ?: null;
                $elon->save();
                $user->current_step = 'manzil';
                $user->save();
                $this->askManzil($message->chat->id);
                break;

            case 'manzil':
                $elon->manzil = $text;
                $elon->save();
                $user->current_step = 'images';
                $user->save();
                $this->askImages($message->chat->id);
                break;

            case 'images':
                // "Tugatish" deb yozilganda confirm'ga o'tish
                if (strtolower($text) === 'tugatish' || strtolower($text) === 'tugadi' || strtolower($text) === 'done') {
                    $elon = $user->elonlar()
                        ->where('status', Elon::STATUS_ACCEPTED_USER)
                        ->latest()
                        ->first();
                    
                    if ($elon && $elon->images()->count() > 0) {
                        $user->current_step = 'confirm';
                        $user->save();
                        $this->askConfirm($message->chat->id, $elon);
                    } else {
                        $this->sendMessage($message->chat->id, "⚠️ Iltimos, kamida 1 ta rasm yuboring!");
                    }
                }
                // Rasm yuborilganda handle qilinadi (handlePhoto metodida)
                break;

            case 'confirm':
                if (strtolower($text) === 'ha' || strtolower($text) === 'yes' || $text === '✅') {
                    $elon->status = Elon::STATUS_SENDED_TO_ADMIN;
                    $elon->save();
                    $user->current_step = null;
                    $user->save();
                    
                    // Adminlarga elon yuborish
                    $this->sendToAdmins($elon);
                    
                    $this->sendConfirmation($message->chat->id, $elon);
                } elseif (strtolower($text) === 'yo\'q' || strtolower($text) === 'no' || $text === '❌') {
                    $user->current_step = 'modeli';
                    $user->save();
                    $this->sendMessage($message->chat->id, "🔄 Qayta tahrirlashni boshlaymiz...");
                    $this->askModeli($message->chat->id);
                } else {
                    $this->askConfirm($message->chat->id, $elon);
                }
                break;

            case 'sold_feedback':
                // Faol elon topish (sotilgan elon)
                $soldElon = $user->elonlar()
                    ->where('is_sold', true)
                    ->latest()
                    ->first();

                if ($soldElon) {
                    $soldElon->sold_feedback = $text;
                    $soldElon->save();
                    $user->current_step = null;
                    $user->save();

                    // Agar elon kanalga chiqgan bo'lsa (elon_message_id bor bo'lsa), feedback qo'shish uchun job'ni navbatga qo'yish
                    // Elon'ni refresh qilish (boshqa job tomonidan o'zgartirilgan bo'lishi mumkin)
                    $soldElon->refresh();
                    
                    if ($soldElon->elon_message_id) {
                        try {
                            // Kichik kechikish - birinchi job (hashtag) tugallanishini kutish
                            \App\Jobs\UpdateSoldElonFeedbackJob::dispatch($soldElon->id, $this->bot->id)
                                ->delay(now()->addSeconds(2));
                            
                        } catch (\Exception $e) {
                            $this->notifyAdminError("UpdateSoldElonFeedbackJob queue qilishda xatolik", [
                                'elon_id' => $soldElon->id,
                                'bot_id' => $this->bot->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $this->sendMessage($message->chat->id, "✅ Fikringiz qabul qilindi va saqlandi!\n\nRahmat! 🙏");
                } else {
                    $this->sendMessage($message->chat->id, "❌ Xatolik yuz berdi!");
                }
                break;
        }
    }

    /**
     * Rasm yuborilganda
     */
    private function handlePhoto(ElonUser $user, Message $message): void
    {
        if ($user->current_step !== 'images') {
            $this->sendMessage($message->chat->id, "⚠️ Iltimos, avval barcha ma'lumotlarni to'ldiring!");
            return;
        }

        $elon = $user->elonlar()
            ->where('status', Elon::STATUS_ACCEPTED_USER)
            ->latest()
            ->first();

        if (!$elon) {
            $this->sendMessage($message->chat->id, "❌ Xatolik yuz berdi!");
            return;
        }

        // Avval rasm sonini tekshirish
        $imageCount = $elon->images()->count();
        
        // Agar 4 ta rasm bo'lsa, 5-chi rasmni qabul qilmaymiz
        if ($imageCount >= 4) {
            $this->sendMessage($message->chat->id, "❌ Xatolik! Maksimum 4 ta rasm yuklashingiz mumkin. Siz allaqachon {$imageCount} ta rasm yuklagansiz.");
            return;
        }

        // Eng katta rasm olish
        $photo = collect($message->photo)->sortByDesc('file_size')->first();
        $fileId = $photo['file_id'];
        $file = $this->telegram->getFile(['file_id' => $fileId]);
        $filePath = $file->filePath;
        $fileUrl = "https://api.telegram.org/file/bot{$this->bot->token}/{$filePath}";

        // Rasmni Telegram'dan yuklab olish va S3'ga saqlash
        $s3Data = $this->downloadAndSaveImage($fileUrl, $filePath, $elon->id);

        // Rasmni saqlash
        Image::create([
            'elon_user_id' => $user->id,
            'elon_id' => $elon->id,
            'image_url' => $fileUrl,
            'image_path' => $filePath,
            'file_id' => $fileId,
            's3_path' => $s3Data['s3_path'],
            's3_url' => $s3Data['s3_url'],
        ]);

        // Elon'ni refresh qilish (yangi saqlangan rasmni hisobga olish uchun)
        $elon->refresh();
        $imageCount = $elon->images()->count();
        
        if ($imageCount >= 4) {
            $this->sendMessage($message->chat->id, "✅ Maksimum 4 ta rasm yuklashingiz mumkin. Rasmlar yuklandi!");
            $user->current_step = 'confirm';
            $user->save();
            $this->askConfirm($message->chat->id, $elon);
        } else {
            $text = "📸 Rasm qabul qilindi! ({$imageCount}/4)\n\n";
            $text .= "Yana rasm yuborishingiz yoki quyidagi tugmani bosishingiz mumkin.";
            $this->sendMessageWithFinishButton($message->chat->id, $text, $elon->id);
        }
    }

    // Savollar
    private function askModeli(int $chatId): void
    {
        $text = "🚗 *1/13 - Model*\n\n";
        $text .= "Mashina modelini kiriting:\n";
        $text .= "Masalan: Nexia, Malibu, Cobalt...";
        $this->sendMessage($chatId, $text);
    }

    private function askPozitsiyasi(int $chatId): void
    {
        $text = "⚙️ *2/13 - Pozitsiya*\n\n";
        $text .= "Pozitsiyani kiriting:\n";
        $text .= "Masalan: Avtomat, Mexanika...";
        $this->sendMessage($chatId, $text);
    }

    private function askRangi(int $chatId): void
    {
        $text = "🎨 *3/13 - Rang*\n\n";
        $text .= "Mashina rangini kiriting:\n";
        $text .= "Masalan: Oq, Qora, Kumush, Qizil...";
        $this->sendMessage($chatId, $text);
    }

    private function askKraskasi(int $chatId): void
    {
        $text = "🖌️ *4/13 - Kraskasi*\n\n";
        $text .= "Kraskasini kiriting:\n";
        $text .= "Masalan: Original, Bo'yalgan, Toza...";
        $this->sendMessage($chatId, $text);
    }

    private function askYili(int $chatId): void
    {
        $text = "📅 *5/13 - Yil*\n\n";
        $text .= "Ishlab chiqarilgan yilni kiriting:\n";
        $text .= "Masalan: 2020";
        $this->sendMessage($chatId, $text);
    }

    private function askYurgani(int $chatId): void
    {
        $text = "🛣️ *6/13 - Yurgan masofa*\n\n";
        $text .= "Yurgan masofani kiriting (km):\n";
        $text .= "Masalan: 50000";
        $this->sendMessage($chatId, $text);
    }

    private function askYoqilgisi(int $chatId): void
    {
        $text = "⛽ *7/13 - Yoqilg'i*\n\n";
        $text .= "Yoqilg'i turini kiriting:\n";
        $text .= "Masalan: Benzin, Dizel, Gaz...";
        $this->sendMessage($chatId, $text);
    }

    private function askNarxi(int $chatId): void
    {
        $text = "💰 *8/13 - Narx*\n\n";
        $text .= "Narxni kiriting (so'm yoki dollar):\n";
        $text .= "Masalan: 15000000 so'm yoki \$5000";
        $this->sendMessage($chatId, $text);
    }

    private function askTel1(int $chatId): void
    {
        $text = "📞 *9/13 - Telefon raqam 1*\n\n";
        $text .= "Birinchi telefon raqamni kiriting:\n";
        $text .= "Masalan: +998901234567";
        $this->sendMessage($chatId, $text);
    }

    private function askTel2(int $chatId): void
    {
        $text = "📞 *10/13 - Telefon raqam 2*\n\n";
        $text .= "Ikkinchi telefon raqamni kiriting (ixtiyoriy):\n";
        $text .= "Agar yo'q bo'lsa, \"Yoq\" deb yozing";
        $this->sendMessage($chatId, $text);
    }

    private function askManzil(int $chatId): void
    {
        $text = "📍 *11/13 - Manzil*\n\n";
        $text .= "Manzilni kiriting:\n";
        $text .= "Masalan: Andijon, Shahar, Viloyat, Tuman, Ko'cha";
        $this->sendMessage($chatId, $text);
    }

    private function askImages(int $chatId): void
    {
        $text = "📸 *12/13 - Rasmlar*\n\n";
        $text .= "Mashina rasmlarini yuboring (kamida 1 ta, maksimum 4 ta):\n\n";
        $text .= "Rasmlarni birin-ketin yuboring.";
        
        // Faol elon topish
        $user = ElonUser::where('chat_id', $chatId)->first();
        $elon = $user ? $user->elonlar()
            ->where('status', Elon::STATUS_ACCEPTED_USER)
            ->latest()
            ->first() : null;
        
        // Agar elon bo'lsa va rasm bo'lsa, button bilan yuborish
        if ($elon && $elon->images()->count() > 0) {
            $text .= "\n\nRasmlar yuklangandan keyin quyidagi tugmani bosing.";
            $this->sendMessageWithFinishButton($chatId, $text, $elon->id);
        } else {
            // Agar rasm bo'lmasa, button'siz yuborish
            $this->sendMessage($chatId, $text);
        }
    }

    private function askConfirm(int $chatId, Elon $elon): void
    {
        $text = "✅ *13/13 - Tasdiqlash*\n\n";
        $text .= "*Elon ma'lumotlari:*\n\n";
        $text .= "🚗 *Model*: " . ($elon->modeli ?? '-') . "\n";
        $text .= "⚙️ *Pozitsiya*: " . ($elon->pozitsiyasi ?? '-') . "\n";
        $text .= "🎨 *Rang*: " . ($elon->rangi ?? '-') . "\n";
        $text .= "🖌️ *Kraskasi*: " . ($elon->kraskasi ?? '-') . "\n";
        $text .= "📅 *Yil*: " . ($elon->yili ?? '-') . "\n";
        $text .= "🛣️ *Yurgani*: " . ($elon->yurgani ? number_format($elon->yurgani, 0, '.', ' ') . ' km' : '-') . "\n";
        $text .= "⛽ *Yoqilg'i*: " . ($elon->yoqilgisi ?? '-') . "\n";
        
        // Narx va valyuta
        $narxText = '-';
        if ($elon->narxi) {
            $currencySymbol = $elon->currency === 'dollar' ? '$' : '';
            $currencyText = $elon->currency === 'dollar' ? ' dollar' : ' so\'m';
            $narxText = $currencySymbol . number_format($elon->narxi, 0, '.', ' ') . $currencyText;
        }
        $text .= "💰 *Narx*: " . $narxText . "\n";
        
        $text .= "📞 *Tel 1*: " . ($elon->tel_1 ?? '-') . "\n";
        $text .= "📞 *Tel 2*: " . ($elon->tel_2 ?? '-') . "\n";
        $text .= "📍 *Manzil*: " . ($elon->manzil ?? '-') . "\n";
        $text .= "📸 *Rasmlar*: " . $elon->images()->count() . " ta\n\n";
        $text .= "Barcha ma'lumotlar to'g'rimi?";

        $this->sendMessageWithConfirmButtons($chatId, $text, $elon->id);
    }

    private function sendConfirmation(int $chatId, Elon $elon): void
    {
        $text = "🎉 *Elon muvaffaqiyatli yuborildi!*\n\n";
        $text .= "Elon ID: #{$elon->id}\n\n";
        $text .= "Elon admin tomonidan ko'rib chiqilgandan keyin kanalga joylashtiriladi.\n\n";
        $text .= "Yangi elon yaratish uchun /start buyrug'ini yuboring.";

        // "Bekor qilish" button bilan xabar yuborish
        try {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '❌ Bekor qilish',
                            'callback_data' => "elon_cancel_user_{$elon->id}"
                        ]
                    ]
                ]
            ];

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (\Exception $e) {
            $this->notifyAdminError("Tasdiqlash xabarini yuborishda xatolik", [
                'chat_id' => $chatId,
                'elon_id' => $elon->id,
                'error' => $e->getMessage(),
            ]);
            // Xatolik bo'lsa, button'siz yuborish
            try {
                $this->sendMessage($chatId, $text);
            } catch (\Exception $e2) {
                // Xatolik bo'lsa, hech narsa qilmaymiz
            }
        }
    }

    /**
     * Adminlarga elon yuborish
     */
    private function sendToAdmins(Elon $elon): void
    {
        $adminChatIds = config('elon.admin_chat_ids', []);
        
        if (empty($adminChatIds)) {
            return;
        }

        $images = $elon->images()->get();
        $elonText = $this->formatElonForAdmin($elon);

        foreach ($adminChatIds as $adminChatId) {
            try {
                // Avval rasmlarni elon matni bilan yuborish
                if ($images->isNotEmpty()) {
                    $this->sendImagesToAdmin((int) $adminChatId, $images, $elonText);
                    // Keyin button'lar bilan alohida xabar yuborish
                    $this->sendButtonsOnly((int) $adminChatId, $elon->id);
                } else {
                    // Agar rasm bo'lmasa, faqat text yuborish
                    $this->sendMessageWithButtons((int) $adminChatId, $elonText, $elon->id);
                }
            } catch (\Exception $e) {
                $this->notifyAdminError("Admin'ga elon yuborishda xatolik", [
                    'admin_chat_id' => $adminChatId,
                    'elon_id' => $elon->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Adminlarga rasmlarni yuborish (media group sifatida)
     */
    private function sendImagesToAdmin(int $chatId, $images, string $caption = ''): void
    {
        try {
            $imageCount = $images->count();
            
            if ($imageCount === 0) {
                return;
            }

            // Caption'ni tayyorlash (elon matni yoki default)
            $finalCaption = !empty($caption) ? $caption : "📸 *Elon rasmlari*";
            
            // Telegram caption limit: 1024 belgi
            if (mb_strlen($finalCaption) > 1024) {
                $finalCaption = mb_substr($finalCaption, 0, 1020) . '...';
            }

            // Avval file_id'lar borligini tekshirish va ularni ustuvor qilish
            $imagesWithFileId = $images->filter(function ($image) {
                return !empty($image->file_id);
            });

            // Agar barcha rasmlar file_id bilan bo'lsa, SDK orqali yuborish
            if ($imagesWithFileId->count() === $imageCount && $imageCount > 1) {
                // SDK orqali media group yuborish (file_id bilan)
                $media = [];
                foreach ($imagesWithFileId as $index => $image) {
                    if ($index < 10) { // Telegram maksimum 10 ta rasm qabul qiladi
                        $media[] = [
                            'type' => 'photo',
                            'media' => $image->file_id,
                        ];
                    }
                }
                
                if (!empty($media)) {
                    $media[0]['caption'] = $finalCaption;
                    $media[0]['parse_mode'] = 'Markdown';
                    
                    try {
                        $this->telegram->sendMediaGroup([
                            'chat_id' => $chatId,
                            'media' => json_encode($media),
                        ]);
                        return;
                    } catch (\Exception $e) {
                        // Agar SDK orqali xatolik bo'lsa, to'g'ridan-to'g'ri yuborishga o'tamiz
                    }
                }
            }

            // Agar bitta rasm bo'lsa va file_id bor bo'lsa, sendPhoto ishlatish
            if ($imageCount === 1) {
                $image = $images->first();
                if ($image->file_id) {
                    try {
                        $photoCaption = !empty($finalCaption) ? $finalCaption : "📸 *Elon rasmi*";
                        if (mb_strlen($photoCaption) > 1024) {
                            $photoCaption = mb_substr($photoCaption, 0, 1020) . '...';
                        }
                        
                        $this->telegram->sendPhoto([
                            'chat_id' => $chatId,
                            'photo' => $image->file_id,
                            'caption' => $photoCaption,
                            'parse_mode' => 'Markdown',
                        ]);
                        return;
                    } catch (\Exception $e) {
                        // Agar xatolik bo'lsa, to'g'ridan-to'g'ri yuborishga o'tamiz
                        $this->sendMediaGroupDirect($chatId, $images, $finalCaption);
                        return;
                    }
                }
            }

            // Agar file_id bo'lmasa yoki ko'p rasm bo'lsa, to'g'ridan-to'g'ri HTTP request yuborish
            $this->sendMediaGroupDirect($chatId, $images, $finalCaption);
            
        } catch (\Exception $e) {
            $this->notifyAdminError("Admin'ga rasmlar yuborishda xatolik", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * To'g'ridan-to'g'ri HTTP request orqali media group yuborish
     */
    private function sendMediaGroupDirect(int $chatId, $images, string $caption = ''): void
    {
        $fileHandles = [];
        
        try {
            $apiUrl = "https://api.telegram.org/bot{$this->bot->token}/sendMediaGroup";
            
            // Media array'ni tayyorlash
            $media = [];
            $multipart = [
                ['name' => 'chat_id', 'contents' => (string) $chatId],
            ];
            
            $validImages = 0;
            foreach ($images as $index => $image) {
                if ($index >= 10) { // Telegram maksimum 10 ta rasm qabul qiladi
                    break;
                }
                
                $mediaValue = null;
                $useAttachment = false;
                
                // Avval file_id'ni ishlatish
                if ($image->file_id) {
                    $mediaValue = $image->file_id;
                } elseif ($image->s3_url) {
                    // S3 URL'ni ishlatish
                    $mediaValue = $image->s3_url;
                } elseif ($image->s3_path && Storage::disk('s3')->exists($image->s3_path)) {
                    // S3'dan faylni yuklab olish va yuborish
                    try {
                        $fileContent = Storage::disk('s3')->get($image->s3_path);
                        $tempFile = tmpfile();
                        $tempPath = stream_get_meta_data($tempFile)['uri'];
                        file_put_contents($tempPath, $fileContent);
                        
                        $mediaValue = "attach://photo_{$index}";
                        $useAttachment = true;
                        
                        // Faylni ochish
                        $fileHandle = fopen($tempPath, 'r');
                        if ($fileHandle !== false) {
                            $fileHandles[] = $fileHandle;
                            
                            // Multipart uchun fayl qo'shamiz
                            $multipart[] = [
                                'name' => "photo_{$index}",
                                'contents' => $fileHandle,
                                'filename' => basename($image->s3_path),
                            ];
                        } else {
                            continue;
                        }
                    } catch (\Exception $e) {
                        $this->notifyAdminError("S3'dan fayl olishda xatolik", [
                            'image_id' => $image->id,
                            's3_path' => $image->s3_path,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                } elseif ($image->image_url) {
                    // Telegram URL'ni ishlatish
                    $mediaValue = $image->image_url;
                }
                
                if ($mediaValue) {
                    $media[] = [
                        'type' => 'photo',
                        'media' => $mediaValue,
                    ];
                    $validImages++;
                }
            }
            
            if (empty($media)) {
                return;
            }
            
            // Birinchi rasmga caption qo'shish (har doim)
            if (count($media) > 0 && !empty($caption)) {
                // Telegram caption limit: 1024 belgi
                $finalCaption = mb_strlen($caption) > 1024 ? mb_substr($caption, 0, 1020) . '...' : $caption;
                $media[0]['caption'] = $finalCaption;
                $media[0]['parse_mode'] = 'Markdown';
            }
            
            // Media array'ni JSON string sifatida qo'shamiz
            $multipart[] = [
                'name' => 'media',
                'contents' => json_encode($media),
            ];
            
            // Agar bitta rasm bo'lsa va file_id yoki URL bo'lsa, oddiy sendPhoto ishlatish
            if ($validImages === 1) {
                $image = $images->first();
                $photo = null;
                
                // Faqat file_id yoki URL bo'lsa, sendPhoto ishlatamiz
                if ($image->file_id) {
                    $photo = $image->file_id;
                } elseif ($image->s3_url) {
                    $photo = $image->s3_url;
                } elseif ($image->image_url) {
                    $photo = $image->image_url;
                }
                
                // Agar file_id yoki URL bo'lsa, sendPhoto ishlatish
                if ($photo && ($image->file_id || $image->s3_url || $image->image_url)) {
                    // File handle'larni yopish
                    foreach ($fileHandles as $handle) {
                        fclose($handle);
                    }
                    
                    $photoCaption = !empty($caption) ? $caption : "📸 *Elon rasmi*";
                    // Telegram caption limit: 1024 belgi
                    if (mb_strlen($photoCaption) > 1024) {
                        $photoCaption = mb_substr($photoCaption, 0, 1020) . '...';
                    }
                    
                    try {
                        $this->telegram->sendPhoto([
                            'chat_id' => $chatId,
                            'photo' => $photo,
                            'caption' => $photoCaption,
                            'parse_mode' => 'Markdown',
                        ]);
                        return;
                    } catch (\Exception $e) {
                        // Agar xatolik bo'lsa, media group orqali yuborishga o'tamiz
                    }
                }
                
                // Agar S3 path bo'lsa yoki xatolik bo'lsa, media group orqali yuboramiz
                // File handle'larni yopmaslik (media group uchun kerak)
            }
            
            // HTTP request yuborish (media group)
            try {
                $response = Http::timeout(60)->asMultipart()->post($apiUrl, $multipart);
                
                // File handle'larni yopish
                foreach ($fileHandles as $handle) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
                
                if (!$response->successful()) {
                    $responseBody = $response->body();
                    $this->notifyAdminError("Media group yuborishda xatolik", [
                        'chat_id' => $chatId,
                        'status' => $response->status(),
                        'response' => $responseBody,
                        'media_count' => count($media),
                    ]);
                }
            } catch (\Exception $e) {
                // File handle'larni yopish (xatolik bo'lsa ham)
                foreach ($fileHandles as $handle) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
                
                $this->notifyAdminError("Media group HTTP request xatolik", [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                    'media_count' => count($media),
                ]);
            }
        } catch (\Exception $e) {
            // File handle'larni yopish (xatolik bo'lsa ham)
            foreach ($fileHandles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            
            $this->notifyAdminError("Media group to'g'ridan-to'g'ri yuborishda xatolik", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin uchun elon formatlash
     */
    private function formatElonForAdmin(Elon $elon): string
    {
        $text = "📋 *Yangi elon*\n\n";
        $text .= "Elon ID: #{$elon->id}\n\n";
        $text .= "*Elon ma'lumotlari:*\n\n";
        $text .= "🚗 *Model*: " . ($elon->modeli ?? '-') . "\n";
        $text .= "⚙️ *Pozitsiya*: " . ($elon->pozitsiyasi ?? '-') . "\n";
        $text .= "🎨 *Rang*: " . ($elon->rangi ?? '-') . "\n";
        $text .= "🖌️ *Kraskasi*: " . ($elon->kraskasi ?? '-') . "\n";
        $text .= "📅 *Yil*: " . ($elon->yili ?? '-') . "\n";
        $text .= "🛣️ *Yurgani*: " . ($elon->yurgani ? number_format($elon->yurgani, 0, '.', ' ') . ' km' : '-') . "\n";
        $text .= "⛽ *Yoqilg'i*: " . ($elon->yoqilgisi ?? '-') . "\n";
        
        // Narx va valyuta
        $narxText = '-';
        if ($elon->narxi) {
            $currencySymbol = $elon->currency === 'dollar' ? '$' : '';
            $currencyText = $elon->currency === 'dollar' ? ' dollar' : ' so\'m';
            $narxText = $currencySymbol . number_format($elon->narxi, 0, '.', ' ') . $currencyText;
        }
        $text .= "💰 *Narx*: " . $narxText . "\n";
        
        $text .= "📞 *Tel 1*: " . ($elon->tel_1 ?? '-') . "\n";
        $text .= "📞 *Tel 2*: " . ($elon->tel_2 ?? '-') . "\n";
        $text .= "📍 *Manzil*: " . ($elon->manzil ?? '-') . "\n";
        $text .= "📸 *Rasmlar*: " . $elon->images()->count() . " ta\n\n";
        
        // Foydalanuvchi ma'lumotlari
        $userName = $elon->elonUser->name ?? '-';
        $userNameText = $userName;
        if ($elon->elonUser->user_name) {
            $userNameText .= " (@{$elon->elonUser->user_name})";
        }
        $text .= "👤 *Foydalanuvchi*: " . $userNameText . "\n";
        $text .= "🆔 *Chat ID*: " . ($elon->elonUser->chat_id ?? '-') . "\n";

        return $text;
    }

    /**
     * Button'lar bilan xabar yuborish
     */
    private function sendMessageWithButtons(int $chatId, string $text, int $elonId): void
    {
        try {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Tasdiqlash',
                            'callback_data' => "elon_accept_{$elonId}"
                        ],
                        [
                            'text' => '❌ Rad etish',
                            'callback_data' => "elon_reject_{$elonId}"
                        ]
                    ]
                ]
            ];

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (\Exception $e) {
            $this->notifyAdminError("Button'li xabar yuborishda xatolik", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Faqat button'lar bilan xabar yuborish (rasmlar bilan caption yuborilganda)
     */
    private function sendButtonsOnly(int $chatId, int $elonId): void
    {
        try {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Tasdiqlash',
                            'callback_data' => "elon_accept_{$elonId}"
                        ],
                        [
                            'text' => '❌ Rad etish',
                            'callback_data' => "elon_reject_{$elonId}"
                        ]
                    ]
                ]
            ];

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '⬆️ *Yuqoridagi elonni tasdiqlang yoki rad eting*',
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (\Exception $e) {
            $this->notifyAdminError("Button'lar yuborishda xatolik", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Callback query'ni handle qilish
     */
    private function handleCallbackQuery($callbackQuery): void
    {
        $chatId = $callbackQuery->from->id;
        $data = $callbackQuery->data;
        $messageId = $callbackQuery->message->messageId ?? null;
        $callbackQueryId = $callbackQuery->id;

        // Avval callback query'ga darhol javob berish (timeout'ni oldini olish uchun)
        try {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => '',
            ]);
        } catch (\Exception $e) {
            // Agar javob berishda xatolik bo'lsa ham, ishni davom ettiramiz
        }

        // Callback data'ni parse qilish
        try {
            if (str_starts_with($data, 'elon_accept_')) {
                // Admin callback'lar uchun tekshirish
                $adminChatIds = config('elon.admin_chat_ids', []);
                if (!in_array($chatId, $adminChatIds)) {
                    $this->telegram->answerCallbackQuery([
                        'callback_query_id' => $callbackQueryId,
                        'text' => 'Sizda bu amalni bajarish huquqi yo\'q!',
                        'show_alert' => true,
                    ]);
                    return;
                }
                $elonId = (int) str_replace('elon_accept_', '', $data);
                $this->handleAdminAccept($chatId, $elonId, $callbackQueryId, $messageId);
            } elseif (str_starts_with($data, 'elon_reject_')) {
                // Admin callback'lar uchun tekshirish
                $adminChatIds = config('elon.admin_chat_ids', []);
                if (!in_array($chatId, $adminChatIds)) {
                    $this->telegram->answerCallbackQuery([
                        'callback_query_id' => $callbackQueryId,
                        'text' => 'Sizda bu amalni bajarish huquqi yo\'q!',
                        'show_alert' => true,
                    ]);
                    return;
                }
                $elonId = (int) str_replace('elon_reject_', '', $data);
                $this->handleAdminReject($chatId, $elonId, $callbackQueryId, $messageId);
            } elseif (str_starts_with($data, 'elon_cancel_user_')) {
                // Foydalanuvchi callback'i - admin tekshiruvi yo'q
                $elonId = (int) str_replace('elon_cancel_user_', '', $data);
                $this->handleUserCancel($chatId, $elonId, $callbackQueryId, $messageId);
            } elseif (str_starts_with($data, 'elon_finish_images_')) {
                // Foydalanuvchi callback'i - admin tekshiruvi yo'q
                $elonId = (int) str_replace('elon_finish_images_', '', $data);
                $this->handleFinishImages($chatId, $elonId, $callbackQueryId, $messageId);
            } elseif (str_starts_with($data, 'elon_confirm_yes_')) {
                // Foydalanuvchi callback'i - admin tekshiruvi yo'q
                $elonId = (int) str_replace('elon_confirm_yes_', '', $data);
                $this->handleConfirmYes($chatId, $elonId, $callbackQueryId, $messageId);
            } elseif (str_starts_with($data, 'elon_confirm_no_')) {
                // Foydalanuvchi callback'i - admin tekshiruvi yo'q
                $elonId = (int) str_replace('elon_confirm_no_', '', $data);
                $this->handleConfirmNo($chatId, $elonId, $callbackQueryId, $messageId);
            } elseif (str_starts_with($data, 'elon_sold_')) {
                // Foydalanuvchi callback'i - admin tekshiruvi yo'q
                $elonId = (int) str_replace('elon_sold_', '', $data);
                $this->handleUserSold($chatId, $elonId, $callbackQueryId, $messageId);
            }
        } catch (\Exception $e) {
            $this->notifyAdminError("Callback query handle qilishda xatolik", [
                'callback_query_id' => $callbackQueryId,
                'data' => $data,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            
            // Xatolik bo'lsa ham, foydalanuvchiga xabar berishga harakat qilish
            try {
                $this->telegram->answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => 'Xatolik yuz berdi. Iltimos, qayta urinib ko\'ring.',
                    'show_alert' => true,
                ]);
            } catch (\Exception $e2) {
                // Xatolik bo'lsa, hech narsa qilmaymiz
            }
        }
    }

    /**
     * Admin tasdiqlash
     */
    private function handleAdminAccept(int $adminChatId, int $elonId, string $callbackQueryId, int $messageId): void
    {
        try {
            $elon = Elon::find($elonId);
            
            if (!$elon) {
                try {
                    $this->telegram->answerCallbackQuery([
                        'callback_query_id' => $callbackQueryId,
                        'text' => 'Elon topilmadi!',
                        'show_alert' => true,
                    ]);
                } catch (\Exception $e) {
                    // Xatolik bo'lsa, hech narsa qilmaymiz
                }
                return;
            }

            // Agar allaqachon tasdiqlangan bo'lsa
            if ($elon->status === Elon::STATUS_ACCEPTED_ADMIN || $elon->status === Elon::STATUS_COMPLATED) {
                try {
                    $this->telegram->answerCallbackQuery([
                        'callback_query_id' => $callbackQueryId,
                        'text' => 'Bu elon allaqachon tasdiqlangan!',
                        'show_alert' => true,
                    ]);
                } catch (\Exception $e) {
                    // Xatolik bo'lsa, hech narsa qilmaymiz
                }
                return;
            }

            // Agar foydalanuvchi tomonidan bekor qilingan bo'lsa, kanalga yuborilmaydi
            if ($elon->cancelled_from_user) {
                try {
                    $this->telegram->answerCallbackQuery([
                        'callback_query_id' => $callbackQueryId,
                        'text' => 'Bu elon foydalanuvchi tomonidan bekor qilingan!',
                        'show_alert' => true,
                    ]);
                    
                    // Xabarni yangilash (background'da)
                    $text = $this->formatElonForAdmin($elon);
                    $text .= "\n\n❌ *Foydalanuvchi tomonidan bekor qilingan*";
                    
                    try {
                        $this->telegram->editMessageText([
                            'chat_id' => $adminChatId,
                            'message_id' => $messageId,
                            'text' => $text,
                            'parse_mode' => 'Markdown',
                        ]);
                    } catch (\Exception $e) {
                        // Xatolik bo'lsa, hech narsa qilmaymiz
                    }
                } catch (\Exception $e) {
                    // Xatolik bo'lsa, hech narsa qilmaymiz
                }
                return;
            }

            // Elon statusini yangilash (darhol)
            $elon->status = Elon::STATUS_ACCEPTED_ADMIN;
            $elon->save();

            // Callback query'ga darhol javob (o'ylanmaslik uchun)
            try {
                $this->telegram->answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => 'Elon tasdiqlandi! ✅',
                ]);
            } catch (\Exception $e) {
                // Xatolik bo'lsa, hech narsa qilmaymiz
            }

            // Xabarni yangilash (background'da, xatolik bo'lsa ham davom etadi)
            try {
                $text = $this->formatElonForAdmin($elon);
                $text .= "\n\n✅ *Tasdiqlangan* (Admin ID: {$adminChatId})";

                $this->telegram->editMessageText([
                    'chat_id' => $adminChatId,
                    'message_id' => $messageId,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                ]);
            } catch (\Exception $e) {
                // Xatolik bo'lsa, hech narsa qilmaymiz
            }

            // Queue'ga qo'yish (background'da)
            try {
                SendElonToChannelJob::dispatch($elon->id, $this->bot->id);
            } catch (\Exception $e) {
                $this->notifyAdminError("Kanalga elon queue qilishda xatolik", [
                    'elon_id' => $elonId,
                    'bot_id' => $this->bot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Foydalanuvchiga xabar yuborish (background'da)
            if ($elon->elonUser) {
                try {
                    $userText = "✅ *Elon tasdiqlandi!*\n\n";
                    $userText .= "Elon ID: #{$elon->id}\n\n";
                    $userText .= "Elon kanalga yuborildi.\n\n";
                    $userText .= "Agar elonni bekor qilmoqchi bo'lsangiz yoki moshina sotilgan bo'lsa, quyidagi tugmalardan birini bosing.";
                    
                    $keyboard = [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '❌ Elonni bekor qilish',
                                    'callback_data' => "elon_cancel_user_{$elon->id}"
                                ],
                                [
                                    'text' => '✅ Moshina sotildi',
                                    'callback_data' => "elon_sold_{$elon->id}"
                                ]
                            ]
                        ]
                    ];
                    
                    $this->telegram->sendMessage([
                        'chat_id' => $elon->elonUser->chat_id,
                        'text' => $userText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => json_encode($keyboard),
                    ]);
                } catch (\Exception $e) {
                    // Xatolik bo'lsa, hech narsa qilmaymiz
                }
            }
        } catch (\Exception $e) {
            $this->notifyAdminError("Admin accept handle qilishda xatolik", [
                'admin_chat_id' => $adminChatId,
                'elon_id' => $elonId,
                'error' => $e->getMessage(),
            ]);
            
            // Xatolik bo'lsa ham, callback query'ga javob berishga harakat qilish
            try {
                $this->telegram->answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => 'Xatolik yuz berdi. Iltimos, qayta urinib ko\'ring.',
                    'show_alert' => true,
                ]);
            } catch (\Exception $e2) {
                // Xatolik bo'lsa, hech narsa qilmaymiz
            }
        }
    }

    /**
     * Admin rad etish
     */
    private function handleAdminReject(int $adminChatId, int $elonId, string $callbackQueryId, int $messageId): void
    {
        $elon = Elon::find($elonId);
        
        if (!$elon) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Elon topilmadi!',
                'show_alert' => true,
            ]);
            return;
        }

        // Elon statusini yangilash
        $elon->status = Elon::STATUS_ENDED;
        $elon->cancelled_from_admin = true;
        $elon->save();

        // Callback query'ga javob
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQueryId,
            'text' => 'Elon rad etildi! ❌',
        ]);

        // Xabarni yangilash
        $text = $this->formatElonForAdmin($elon);
        $text .= "\n\n❌ *Rad etilgan* (Admin ID: {$adminChatId})";

        $this->telegram->editMessageText([
            'chat_id' => $adminChatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);

        // Foydalanuvchiga xabar yuborish
        if ($elon->elonUser) {
            $userText = "❌ *Elon rad etildi*\n\n";
            $userText .= "Elon ID: #{$elon->id}\n\n";
            $userText .= "Iltimos, elon ma'lumotlarini tekshirib, qayta yuboring.";
            $this->sendMessage($elon->elonUser->chat_id, $userText);
        }

    }

    /**
     * Foydalanuvchi tomonidan elonni bekor qilish
     */
    private function handleUserCancel(int $userChatId, int $elonId, string $callbackQueryId, int $messageId): void
    {
        $elon = Elon::find($elonId);
        
        if (!$elon) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Elon topilmadi!',
                'show_alert' => true,
            ]);
            return;
        }

        // Foydalanuvchi ekanligini tekshirish
        if (!$elon->elonUser || $elon->elonUser->chat_id !== $userChatId) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Bu sizning eloningiz emas!',
                'show_alert' => true,
            ]);
            return;
        }

        // Agar elon allaqachon kanalga chiqgan bo'lsa (complated status)
        if ($elon->status === Elon::STATUS_COMPLATED) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Elon allaqachon kanalga chiqgan, bekor qilish mumkin emas!',
                'show_alert' => true,
            ]);
            return;
        }

        // Agar allaqachon bekor qilingan bo'lsa
        if ($elon->cancelled_from_user || $elon->cancelled_from_admin) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Elon allaqachon bekor qilingan!',
                'show_alert' => true,
            ]);
            return;
        }

        // Elon statusini yangilash
        $elon->status = Elon::STATUS_ENDED;
        $elon->cancelled_from_user = true;
        $elon->save();

        // Callback query'ga javob
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQueryId,
            'text' => 'Elon bekor qilindi! ✅',
        ]);

        // Xabarni yangilash
        $text = "✅ *Elon tasdiqlandi!*\n\n";
        $text .= "Elon ID: #{$elon->id}\n\n";
        $text .= "❌ *Elon bekor qilindi* (Siz tomoningizdan)";

        $this->telegram->editMessageText([
            'chat_id' => $userChatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);

    }

    /**
     * Foydalanuvchi tomonidan moshina sotildi deb belgilash
     */
    private function handleUserSold(int $userChatId, int $elonId, string $callbackQueryId, int $messageId): void
    {
        $elon = Elon::find($elonId);
        
        if (!$elon) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Elon topilmadi!',
                'show_alert' => true,
            ]);
            return;
        }

        // Foydalanuvchi ekanligini tekshirish
        if (!$elon->elonUser || $elon->elonUser->chat_id !== $userChatId) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Bu sizning eloningiz emas!',
                'show_alert' => true,
            ]);
            return;
        }

        // Agar allaqachon sotilgan bo'lsa
        if ($elon->is_sold) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Moshina allaqachon sotilgan deb belgilangan!',
                'show_alert' => true,
            ]);
            return;
        }

        // Elon is_sold'ni yangilash
        $elon->is_sold = true;
        $elon->save();

        // User step'ni sold_feedback ga o'rnatish
        $user = $elon->elonUser;
        $user->current_step = 'sold_feedback';
        $user->save();

        // Callback query'ga javob
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQueryId,
            'text' => 'Moshina sotildi deb belgilandi! ✅',
        ]);

        // Xabarni yangilash
        $text = "✅ *Elon tasdiqlandi!*\n\n";
        $text .= "Elon ID: #{$elon->id}\n\n";
        $text .= "✅ *Moshina sotildi* (Siz tomoningizdan belgilandi)";

        $this->telegram->editMessageText([
            'chat_id' => $userChatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);

        // User'ga xabar yuborish
        $feedbackText = "🎉 *@Avto_vodiyuz kanali sizga xizmat ko'rsatganidan mamnun!*\n\n";
        $feedbackText .= "Ishlarizga omad tilaymiz! 🍀\n\n";
        $feedbackText .= "Kanalga joylash uchun izoh yozib bering. Qisqacha elonga fikringizni joylab qo'yamiz.";

        $this->sendMessage($userChatId, $feedbackText);

        // Agar elon kanalga chiqgan bo'lsa (elon_message_id bor bo'lsa), update job'ni navbatga qo'yish
        // Faqat hashtag (#sotildi) qo'yish uchun
        if ($elon->elon_message_id) {
            try {
                \App\Jobs\UpdateSoldElonJob::dispatch($elon->id, $this->bot->id);
            } catch (\Exception $e) {
                $this->notifyAdminError("UpdateSoldElonJob queue qilishda xatolik", [
                    'elon_id' => $elonId,
                    'bot_id' => $this->bot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

    }

    /**
     * Telegram'dan rasmni yuklab olish va S3'ga saqlash
     */
    private function downloadAndSaveImage(string $fileUrl, string $filePath, int $elonId): array
    {
        try {
            // Rasmni HTTP client orqali yuklab olish (timeout bilan)
            $response = Http::timeout(30)->get($fileUrl);
            
            if (!$response->successful()) {
                $this->notifyAdminError("Telegram'dan rasm yuklab olishda xatolik", [
                    'file_url' => $fileUrl,
                    'file_path' => $filePath,
                    'status' => $response->status(),
                ]);
                return ['s3_path' => null, 's3_url' => null];
            }
            
            $imageContent = $response->body();
            
            if (empty($imageContent)) {
                $this->notifyAdminError("Yuklangan rasm bo'sh", [
                    'file_url' => $fileUrl,
                    'file_path' => $filePath,
                ]);
                return ['s3_path' => null, 's3_url' => null];
            }

            // File extension olish
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            if (empty($extension)) {
                $extension = 'jpg'; // Default extension
            }

            // Storage path yaratish: elons/{elon_id}/{timestamp}_{random}.{ext}
            $fileName = time() . '_' . uniqid() . '.' . $extension;
            $storagePath = "elons/{$elonId}/{$fileName}";

            // S3 disk'ga saqlash
            Storage::disk('s3')->put($storagePath, $imageContent);

            // S3 URL'ni olish - to'g'ridan-to'g'ri URL yaratish
            $awsUrl = config('filesystems.disks.s3.url', '');
            $s3Url = $awsUrl ? rtrim($awsUrl, '/') . '/' . $storagePath : null;

            return [
                's3_path' => $storagePath,
                's3_url' => $s3Url,
            ];
        } catch (\Exception $e) {
            $this->notifyAdminError("Rasmni S3'ga saqlashda xatolik", [
                'file_url' => $fileUrl,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return ['s3_path' => null, 's3_url' => null];
        }
    }

    /**
     * "Tugatish" tugmasi bilan xabar yuborish
     */
    private function sendMessageWithFinishButton(int $chatId, string $text, int $elonId): void
    {
        try {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Tugatish',
                            'callback_data' => "elon_finish_images_{$elonId}"
                        ]
                    ]
                ]
            ];

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (\Exception $e) {
            $this->notifyAdminError("Tugatish button'li xabar yuborishda xatolik", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * "Ha" va "Yo'q" tugmalari bilan tasdiqlash xabari yuborish
     */
    private function sendMessageWithConfirmButtons(int $chatId, string $text, int $elonId): void
    {
        try {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Ha',
                            'callback_data' => "elon_confirm_yes_{$elonId}"
                        ],
                        [
                            'text' => '❌ Yo\'q',
                            'callback_data' => "elon_confirm_no_{$elonId}"
                        ]
                    ]
                ]
            ];

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (\Exception $e) {
            $this->notifyAdminError("Tasdiqlash button'li xabar yuborishda xatolik", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rasmlarni tugatish (callback query)
     */
    private function handleFinishImages(int $chatId, int $elonId, string $callbackQueryId, int $messageId): void
    {
        $elon = Elon::find($elonId);
        
        if (!$elon) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Elon topilmadi!',
                'show_alert' => true,
            ]);
            return;
        }

        // Foydalanuvchi ekanligini tekshirish
        if (!$elon->elonUser || $elon->elonUser->chat_id !== $chatId) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Bu sizning eloningiz emas!',
                'show_alert' => true,
            ]);
            return;
        }

        // Agar rasm bo'lmasa
        if ($elon->images()->count() === 0) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => '⚠️ Iltimos, kamida 1 ta rasm yuboring!',
                'show_alert' => true,
            ]);
            return;
        }

        // User step'ni yangilash
        $user = $elon->elonUser;
        $user->current_step = 'confirm';
        $user->save();

        // Callback query'ga javob (agar hali javob berilmagan bo'lsa)
        try {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Rasmlar tugatildi! ✅',
            ]);
        } catch (\Exception $e) {
            // Xatolik bo'lsa, hech narsa qilmaymiz
        }

        // Xabarni yangilash
        $text = "✅ *Rasmlar tugatildi!*\n\n";
        $text .= "Jami {$elon->images()->count()} ta rasm yuklandi.";

        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);

        // Confirm'ga o'tish
        $this->askConfirm($chatId, $elon);

    }

    /**
     * Tasdiqlash - "Ha" tugmasi bosilganda
     */
    private function handleConfirmYes(int $chatId, int $elonId, string $callbackQueryId, int $messageId): void
    {
        $elon = Elon::find($elonId);
        
        if (!$elon) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Elon topilmadi!',
                'show_alert' => true,
            ]);
            return;
        }

        // Foydalanuvchi ekanligini tekshirish
        if (!$elon->elonUser || $elon->elonUser->chat_id !== $chatId) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Bu sizning eloningiz emas!',
                'show_alert' => true,
            ]);
            return;
        }

        // Elon statusini yangilash
        $elon->status = Elon::STATUS_SENDED_TO_ADMIN;
        $elon->save();

        // User step'ni yangilash
        $user = $elon->elonUser;
        $user->current_step = null;
        $user->save();

        // Callback query'ga javob
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQueryId,
            'text' => 'Elon yuborildi! ✅',
        ]);

        // Xabarni yangilash
        $text = "✅ *13/13 - Tasdiqlash*\n\n";
        $text .= "*Elon ma'lumotlari:*\n\n";
        $text .= "🚗 *Model*: " . ($elon->modeli ?? '-') . "\n";
        $text .= "⚙️ *Pozitsiya*: " . ($elon->pozitsiyasi ?? '-') . "\n";
        $text .= "🎨 *Rang*: " . ($elon->rangi ?? '-') . "\n";
        $text .= "🖌️ *Kraskasi*: " . ($elon->kraskasi ?? '-') . "\n";
        $text .= "📅 *Yil*: " . ($elon->yili ?? '-') . "\n";
        $text .= "🛣️ *Yurgani*: " . ($elon->yurgani ? number_format($elon->yurgani, 0, '.', ' ') . ' km' : '-') . "\n";
        $text .= "⛽ *Yoqilg'i*: " . ($elon->yoqilgisi ?? '-') . "\n";
        
        // Narx va valyuta
        $narxText = '-';
        if ($elon->narxi) {
            $currencySymbol = $elon->currency === 'dollar' ? '$' : '';
            $currencyText = $elon->currency === 'dollar' ? ' dollar' : ' so\'m';
            $narxText = $currencySymbol . number_format($elon->narxi, 0, '.', ' ') . $currencyText;
        }
        $text .= "💰 *Narx*: " . $narxText . "\n";
        
        $text .= "📞 *Tel 1*: " . ($elon->tel_1 ?? '-') . "\n";
        $text .= "📞 *Tel 2*: " . ($elon->tel_2 ?? '-') . "\n";
        $text .= "📍 *Manzil*: " . ($elon->manzil ?? '-') . "\n";
        $text .= "📸 *Rasmlar*: " . $elon->images()->count() . " ta\n\n";
        $text .= "✅ *Tasdiqlandi va yuborildi!*";

        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);

        // Adminlarga elon yuborish
        $this->sendToAdmins($elon);
        
        // Foydalanuvchiga tasdiqlash xabari
        $this->sendConfirmation($chatId, $elon);

    }

    /**
     * Tasdiqlash - "Yo'q" tugmasi bosilganda
     */
    private function handleConfirmNo(int $chatId, int $elonId, string $callbackQueryId, int $messageId): void
    {
        $elon = Elon::find($elonId);
        
        if (!$elon) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Elon topilmadi!',
                'show_alert' => true,
            ]);
            return;
        }

        // Foydalanuvchi ekanligini tekshirish
        if (!$elon->elonUser || $elon->elonUser->chat_id !== $chatId) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Bu sizning eloningiz emas!',
                'show_alert' => true,
            ]);
            return;
        }

        // User step'ni yangilash
        $user = $elon->elonUser;
        $user->current_step = 'modeli';
        $user->save();

        // Callback query'ga javob
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQueryId,
            'text' => 'Qayta tahrirlash boshlandi! 🔄',
        ]);

        // Xabarni yangilash
        $text = "✅ *13/13 - Tasdiqlash*\n\n";
        $text .= "*Elon ma'lumotlari:*\n\n";
        $text .= "🚗 *Model*: " . ($elon->modeli ?? '-') . "\n";
        $text .= "⚙️ *Pozitsiya*: " . ($elon->pozitsiyasi ?? '-') . "\n";
        $text .= "🎨 *Rang*: " . ($elon->rangi ?? '-') . "\n";
        $text .= "🖌️ *Kraskasi*: " . ($elon->kraskasi ?? '-') . "\n";
        $text .= "📅 *Yil*: " . ($elon->yili ?? '-') . "\n";
        $text .= "🛣️ *Yurgani*: " . ($elon->yurgani ? number_format($elon->yurgani, 0, '.', ' ') . ' km' : '-') . "\n";
        $text .= "⛽ *Yoqilg'i*: " . ($elon->yoqilgisi ?? '-') . "\n";
        
        // Narx va valyuta
        $narxText = '-';
        if ($elon->narxi) {
            $currencySymbol = $elon->currency === 'dollar' ? '$' : '';
            $currencyText = $elon->currency === 'dollar' ? ' dollar' : ' so\'m';
            $narxText = $currencySymbol . number_format($elon->narxi, 0, '.', ' ') . $currencyText;
        }
        $text .= "💰 *Narx*: " . $narxText . "\n";
        
        $text .= "📞 *Tel 1*: " . ($elon->tel_1 ?? '-') . "\n";
        $text .= "📞 *Tel 2*: " . ($elon->tel_2 ?? '-') . "\n";
        $text .= "📍 *Manzil*: " . ($elon->manzil ?? '-') . "\n";
        $text .= "📸 *Rasmlar*: " . $elon->images()->count() . " ta\n\n";
        $text .= "🔄 *Qayta tahrirlash boshlandi*";

        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);

        // Qayta tahrirlashni boshlash
        $this->sendMessage($chatId, "🔄 Qayta tahrirlashni boshlaymiz...");
        $this->askModeli($chatId);

    }

    /**
     * Xabar yuborish
     */
    private function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null): void
    {
        try {
            $params = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ];

            if ($replyToMessageId) {
                $params['reply_to_message_id'] = $replyToMessageId;
            }

            $this->telegram->sendMessage($params);
        } catch (\Exception $e) {
            $this->notifyAdminError("Xabar yuborishda xatolik", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
