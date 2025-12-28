<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Elon;
use App\Models\ElonUser;
use App\Models\Image;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * Update'ni handle qilish
     */
    public function handleUpdate(Update $update): void
    {
        Log::info("ElonService: handleUpdate called", [
            'has_message' => $update->message !== null,
            'has_callback_query' => $update->callbackQuery !== null,
        ]);

        if ($update->callbackQuery) {
            $this->handleCallbackQuery($update->callbackQuery);
            return;
        }

        if ($update->message) {
            $this->handleMessage($update->message);
        } else {
            Log::warning("ElonService: No message in update", [
                'update_id' => $update->updateId ?? null,
            ]);
        }
    }

    /**
     * Message'ni handle qilish
     */
    private function handleMessage(Message $message): void
    {
        $chatId = $message->chat->id;
        $text = $message->text ?? '';
        $photo = $message->photo;

        Log::info("ElonService: handleMessage called", [
            'chat_id' => $chatId,
            'text' => $text,
            'has_photo' => $photo !== null,
        ]);

        // User'ni topish yoki yaratish
        $user = ElonUser::firstOrCreate(
            ['chat_id' => $chatId],
            [
                'name' => $message->from->firstName ?? '',
                'user_name' => $message->from->username ?? null,
                'current_step' => 'start',
            ]
        );

        // Agar user name yoki username o'zgarsa, yangilash
        if ($message->from->firstName && $user->name !== $message->from->firstName) {
            $user->name = $message->from->firstName;
            $user->save();
        }
        if ($message->from->username && $user->user_name !== $message->from->username) {
            $user->user_name = $message->from->username;
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
                if ($yurgani > 0) {
                    $elon->yurgani = $yurgani;
                    $elon->save();
                    $user->current_step = 'yoqilgisi';
                    $user->save();
                    $this->askYoqilgisi($message->chat->id);
                } else {
                    $this->sendMessage($message->chat->id, "❌ Noto'g'ri masofa! Iltimos, raqam kiriting (masalan: 50000)");
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

        // Eng katta rasm olish
        $photo = collect($message->photo)->sortByDesc('file_size')->first();
        $fileId = $photo['file_id'];
        $file = $this->telegram->getFile(['file_id' => $fileId]);
        $filePath = $file->filePath;
        $fileUrl = "https://api.telegram.org/file/bot{$this->bot->token}/{$filePath}";

        // Rasmni Telegram'dan yuklab olish va local storage'ga saqlash
        $localPath = $this->downloadAndSaveImage($fileUrl, $filePath, $elon->id);

        // Rasmni saqlash
        Image::create([
            'elon_user_id' => $user->id,
            'elon_id' => $elon->id,
            'image_url' => $fileUrl,
            'image_path' => $filePath,
            'file_id' => $fileId,
            'local_path' => $localPath,
        ]);

        $imageCount = $elon->images()->count();
        
        if ($imageCount >= 10) {
            $this->sendMessage($message->chat->id, "✅ Maksimum 10 ta rasm yuklashingiz mumkin. Rasmlar yuklandi!");
            $user->current_step = 'confirm';
            $user->save();
            $this->askConfirm($message->chat->id, $elon);
        } else {
            $text = "📸 Rasm qabul qilindi! ({$imageCount}/10)\n\n";
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
        $text .= "Mashina rasmlarini yuboring (kamida 1 ta, maksimum 10 ta):\n\n";
        $text .= "Rasmlarni birin-ketin yuboring.\n";
        $text .= "Rasmlar yuklangandan keyin quyidagi tugmani bosing.";
        
        // Faol elon topish
        $user = ElonUser::where('chat_id', $chatId)->first();
        $elon = $user ? $user->elonlar()
            ->where('status', Elon::STATUS_ACCEPTED_USER)
            ->latest()
            ->first() : null;
        
        if ($elon) {
            $this->sendMessageWithFinishButton($chatId, $text, $elon->id);
        } else {
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

        $this->sendMessage($chatId, $text);
    }

    /**
     * Adminlarga elon yuborish
     */
    private function sendToAdmins(Elon $elon): void
    {
        $adminChatIds = config('elon.admin_chat_ids', []);
        
        if (empty($adminChatIds)) {
            Log::warning("No admin chat IDs configured");
            return;
        }

        $images = $elon->images()->get();
        $elonText = $this->formatElonForAdmin($elon);

        foreach ($adminChatIds as $adminChatId) {
            try {
                // Avval rasmlarni yuborish
                if ($images->isNotEmpty()) {
                    $this->sendImagesToAdmin((int) $adminChatId, $images);
                }
                
                // Keyin text va button'lar bilan yuborish
                $this->sendMessageWithButtons((int) $adminChatId, $elonText, $elon->id);
            } catch (\Exception $e) {
                Log::error("Error sending elon to admin", [
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
    private function sendImagesToAdmin(int $chatId, $images): void
    {
        try {
            $imageCount = $images->count();
            
            if ($imageCount === 0) {
                return;
            }

            // Barcha rasmlar file_id bilan bo'lsa, SDK orqali yuborish
            $allHaveFileId = $images->every(function ($image) {
                return !empty($image->file_id);
            });

            if ($allHaveFileId && $imageCount > 1) {
                // SDK orqali media group yuborish (file_id bilan)
                $media = [];
                foreach ($images as $index => $image) {
                    if ($index < 10) { // Telegram maksimum 10 ta rasm qabul qiladi
                        $media[] = [
                            'type' => 'photo',
                            'media' => $image->file_id,
                        ];
                    }
                }
                
                if (!empty($media)) {
                    $media[0]['caption'] = "📸 *Elon rasmlari*";
                    $media[0]['parse_mode'] = 'Markdown';
                    
                    $this->telegram->sendMediaGroup([
                        'chat_id' => $chatId,
                        'media' => json_encode($media),
                    ]);
                    return;
                }
            }

            // Agar file_id bo'lmasa yoki bitta rasm bo'lsa, to'g'ridan-to'g'ri HTTP request yuborish
            $this->sendMediaGroupDirect($chatId, $images);
            
        } catch (\Exception $e) {
            Log::error("Error sending images to admin", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * To'g'ridan-to'g'ri HTTP request orqali media group yuborish
     */
    private function sendMediaGroupDirect(int $chatId, $images): void
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
                } elseif ($image->local_path && Storage::disk('public')->exists($image->local_path)) {
                    // Local file yuborish
                    $fullPath = Storage::disk('public')->path($image->local_path);
                    
                    if (file_exists($fullPath)) {
                        $mediaValue = "attach://photo_{$index}";
                        $useAttachment = true;
                        
                        // Faylni ochish
                        $fileHandle = fopen($fullPath, 'r');
                        if ($fileHandle !== false) {
                            $fileHandles[] = $fileHandle;
                            
                            // Multipart uchun fayl qo'shamiz
                            $multipart[] = [
                                'name' => "photo_{$index}",
                                'contents' => $fileHandle,
                                'filename' => basename($fullPath),
                            ];
                        } else {
                            Log::warning("Failed to open file for media group", [
                                'image_id' => $image->id,
                                'path' => $fullPath,
                            ]);
                            continue;
                        }
                    }
                } elseif ($image->image_url) {
                    // URL yuborish
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
                Log::warning("No valid media items for media group", [
                    'chat_id' => $chatId,
                ]);
                return;
            }
            
            // Birinchi rasmga caption qo'shish
            if (count($media) > 0) {
                $media[0]['caption'] = "📸 *Elon rasmlari*";
                $media[0]['parse_mode'] = 'Markdown';
            }
            
            // Media array'ni JSON string sifatida qo'shamiz
            $multipart[] = [
                'name' => 'media',
                'contents' => json_encode($media),
            ];
            
            // Agar bitta rasm bo'lsa, oddiy sendPhoto ishlatish
            if ($validImages === 1) {
                // File handle'larni yopish
                foreach ($fileHandles as $handle) {
                    fclose($handle);
                }
                
                $image = $images->first();
                $photo = null;
                
                if ($image->file_id) {
                    $photo = $image->file_id;
                } elseif ($image->local_path && Storage::disk('public')->exists($image->local_path)) {
                    $photo = Storage::disk('public')->path($image->local_path);
                } elseif ($image->image_url) {
                    $photo = $image->image_url;
                }
                
                if ($photo) {
                    $this->telegram->sendPhoto([
                        'chat_id' => $chatId,
                        'photo' => $photo,
                        'caption' => "📸 *Elon rasmi*",
                        'parse_mode' => 'Markdown',
                    ]);
                }
                return;
            }
            
            // HTTP request yuborish
            $response = Http::asMultipart()->post($apiUrl, $multipart);
            
            // File handle'larni yopish
            foreach ($fileHandles as $handle) {
                fclose($handle);
            }
            
            if ($response->successful()) {
                Log::info("Media group sent successfully to admin", [
                    'chat_id' => $chatId,
                    'count' => count($media),
                ]);
            } else {
                Log::error("Failed to send media group to admin", [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            // File handle'larni yopish (xatolik bo'lsa ham)
            foreach ($fileHandles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            
            Log::error("Error sending media group directly", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
            Log::error("Error sending message with buttons", [
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
        $messageId = $callbackQuery->message->messageId;

        // Callback data'ni parse qilish
        if (str_starts_with($data, 'elon_accept_')) {
            // Admin callback'lar uchun tekshirish
            $adminChatIds = config('elon.admin_chat_ids', []);
            if (!in_array($chatId, $adminChatIds)) {
                $this->telegram->answerCallbackQuery([
                    'callback_query_id' => $callbackQuery->id,
                    'text' => 'Sizda bu amalni bajarish huquqi yo\'q!',
                    'show_alert' => true,
                ]);
                return;
            }
            $elonId = (int) str_replace('elon_accept_', '', $data);
            $this->handleAdminAccept($chatId, $elonId, $callbackQuery->id, $messageId);
        } elseif (str_starts_with($data, 'elon_reject_')) {
            // Admin callback'lar uchun tekshirish
            $adminChatIds = config('elon.admin_chat_ids', []);
            if (!in_array($chatId, $adminChatIds)) {
                $this->telegram->answerCallbackQuery([
                    'callback_query_id' => $callbackQuery->id,
                    'text' => 'Sizda bu amalni bajarish huquqi yo\'q!',
                    'show_alert' => true,
                ]);
                return;
            }
            $elonId = (int) str_replace('elon_reject_', '', $data);
            $this->handleAdminReject($chatId, $elonId, $callbackQuery->id, $messageId);
        } elseif (str_starts_with($data, 'elon_cancel_user_')) {
            // Foydalanuvchi callback'i - admin tekshiruvi yo'q
            $elonId = (int) str_replace('elon_cancel_user_', '', $data);
            $this->handleUserCancel($chatId, $elonId, $callbackQuery->id, $messageId);
        } elseif (str_starts_with($data, 'elon_finish_images_')) {
            // Foydalanuvchi callback'i - admin tekshiruvi yo'q
            $elonId = (int) str_replace('elon_finish_images_', '', $data);
            $this->handleFinishImages($chatId, $elonId, $callbackQuery->id, $messageId);
        } elseif (str_starts_with($data, 'elon_confirm_yes_')) {
            // Foydalanuvchi callback'i - admin tekshiruvi yo'q
            $elonId = (int) str_replace('elon_confirm_yes_', '', $data);
            $this->handleConfirmYes($chatId, $elonId, $callbackQuery->id, $messageId);
        } elseif (str_starts_with($data, 'elon_confirm_no_')) {
            // Foydalanuvchi callback'i - admin tekshiruvi yo'q
            $elonId = (int) str_replace('elon_confirm_no_', '', $data);
            $this->handleConfirmNo($chatId, $elonId, $callbackQuery->id, $messageId);
        }
    }

    /**
     * Admin tasdiqlash
     */
    private function handleAdminAccept(int $adminChatId, int $elonId, string $callbackQueryId, int $messageId): void
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

        // Agar allaqachon tasdiqlangan bo'lsa
        if ($elon->status === Elon::STATUS_ACCEPTED_ADMIN || $elon->status === Elon::STATUS_COMPLATED) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
                'text' => 'Bu elon allaqachon tasdiqlangan!',
                'show_alert' => true,
            ]);
            return;
        }

        // Elon statusini yangilash
        $elon->status = Elon::STATUS_ACCEPTED_ADMIN;
        $elon->save();

        // Callback query'ga javob
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQueryId,
            'text' => 'Elon tasdiqlandi! ✅',
        ]);

        // Xabarni yangilash
        $text = $this->formatElonForAdmin($elon);
        $text .= "\n\n✅ *Tasdiqlangan* (Admin ID: {$adminChatId})";

        $this->telegram->editMessageText([
            'chat_id' => $adminChatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);

        // Foydalanuvchiga xabar yuborish (button bilan)
        if ($elon->elonUser) {
            $userText = "✅ *Elon tasdiqlandi!*\n\n";
            $userText .= "Elon ID: #{$elon->id}\n\n";
            $userText .= "Elon kanalga yuboriladi.\n\n";
            $userText .= "Agar elonni bekor qilmoqchi bo'lsangiz, quyidagi tugmani bosing.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '❌ Elonni bekor qilish',
                            'callback_data' => "elon_cancel_user_{$elon->id}"
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
        }

        Log::info("Elon accepted by admin", [
            'elon_id' => $elonId,
            'admin_chat_id' => $adminChatId,
        ]);
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

        Log::info("Elon rejected by admin", [
            'elon_id' => $elonId,
            'admin_chat_id' => $adminChatId,
        ]);
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
        if ($elon->elonUser->chat_id !== $userChatId) {
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

        Log::info("Elon cancelled by user", [
            'elon_id' => $elonId,
            'user_chat_id' => $userChatId,
        ]);
    }

    /**
     * Telegram'dan rasmni yuklab olish va local storage'ga saqlash
     */
    private function downloadAndSaveImage(string $fileUrl, string $filePath, int $elonId): ?string
    {
        try {
            // Rasmni yuklab olish
            $imageContent = file_get_contents($fileUrl);
            
            if ($imageContent === false) {
                Log::error("Failed to download image from Telegram", [
                    'file_url' => $fileUrl,
                    'file_path' => $filePath,
                ]);
                return null;
            }

            // File extension olish
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            if (empty($extension)) {
                $extension = 'jpg'; // Default extension
            }

            // Storage path yaratish: elons/{elon_id}/{timestamp}_{random}.{ext}
            $fileName = time() . '_' . uniqid() . '.' . $extension;
            $storagePath = "elons/{$elonId}/{$fileName}";

            // Public disk'ga saqlash
            Storage::disk('public')->put($storagePath, $imageContent);

            // To'liq path qaytarish
            return $storagePath;
        } catch (\Exception $e) {
            Log::error("Error downloading and saving image", [
                'file_url' => $fileUrl,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
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
            Log::error("Error sending message with finish button", [
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
            Log::error("Error sending message with confirm buttons", [
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

        // Callback query'ga javob
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQueryId,
            'text' => 'Rasmlar tugatildi! ✅',
        ]);

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

        Log::info("Images finished by user", [
            'elon_id' => $elonId,
            'user_chat_id' => $chatId,
        ]);
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

        Log::info("Elon confirmed and sent by user", [
            'elon_id' => $elonId,
            'user_chat_id' => $chatId,
        ]);
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

        Log::info("Elon editing restarted by user", [
            'elon_id' => $elonId,
            'user_chat_id' => $chatId,
        ]);
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
            Log::error("Error sending message", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
