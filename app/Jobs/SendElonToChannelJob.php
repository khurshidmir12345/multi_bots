<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Elon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;

class SendElonToChannelJob implements ShouldQueue
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
            Log::error("SendElonToChannelJob: Elon or Bot not found", [
                'elon_id' => $this->elonId,
                'bot_id' => $this->botId,
            ]);
            return;
        }

        // Agar elon allaqachon bekor qilingan yoki kanalga chiqgan bo'lsa
        if ($elon->cancelled_from_admin || $elon->cancelled_from_user || $elon->status === Elon::STATUS_COMPLATED) {
            Log::info("SendElonToChannelJob: Elon cancelled or already sent", [
                'elon_id' => $this->elonId,
                'status' => $elon->status,
            ]);
            return;
        }

        if (!$bot->channel_id) {
            Log::error("SendElonToChannelJob: Channel ID not found", [
                'bot_id' => $this->botId,
            ]);
            return;
        }

        try {
            $telegram = new Api($bot->token);
            $images = $elon->images()->get();
            $caption = $this->formatElonForChannel($elon, $bot, $telegram);

            // Rasmlarni kanalga yuborish
            if ($images->isNotEmpty()) {
                $this->sendImagesToChannel($telegram, $bot->channel_id, $images, $caption, $bot->token);
            } else {
                // Agar rasm bo'lmasa, faqat text
                $telegram->sendMessage([
                    'chat_id' => $bot->channel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            }

            // Elon muvaffaqiyatli yuborilgandan keyin statusni yangilash
            $elon->status = Elon::STATUS_COMPLATED;
            $elon->save();

            Log::info("SendElonToChannelJob: Elon sent to channel successfully", [
                'elon_id' => $this->elonId,
                'channel_id' => $bot->channel_id,
                'status' => 'complated',
            ]);
        } catch (\Exception $e) {
            Log::error("SendElonToChannelJob: Error sending elon", [
                'elon_id' => $this->elonId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Xatolik bo'lsa, status o'zgarmaydi va job keyinroq qayta urinadi
            throw $e;
        }
    }

    /**
     * Kanal uchun elon formatlash (HTML format)
     */
    private function formatElonForChannel(Elon $elon, Bot $bot, Api $telegram): string
    {
        $text = "🚗 <b>Moshina:</b> " . htmlspecialchars($elon->modeli ?? '-') . "\n";
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
        
        $text .= "📞 <b>Tel:</b> " . htmlspecialchars($elon->tel_1 ?? '-') . "\n";
        if ($elon->tel_2) {
            $text .= "📞 <b>Tel 2:</b> " . htmlspecialchars($elon->tel_2) . "\n";
        }
        $text .= "📍 <b>Manzil:</b> " . htmlspecialchars($elon->manzil ?? '-') . "\n";
        
        // Bot username'ni olish
        $botUsername = null;
        try {
            $botInfo = $telegram->getMe();
            $botUsername = $botInfo->username ?? null;
            Log::info("SendElonToChannelJob: Bot username retrieved", [
                'bot_id' => $bot->id,
                'username' => $botUsername,
            ]);
        } catch (\Exception $e) {
            Log::warning("SendElonToChannelJob: Failed to get bot username", [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Bot username va eslatma matnini qo'shish (har doim)
        $text .= "\n";
        if ($botUsername) {
            $text .= "Elon berish bepul :\n@" . htmlspecialchars($botUsername) . "\n";
        }
        
        // Eslatma matni (bold) - har doim qo'shiladi
        // HTML tag'larni to'g'ri yopish kerak - har bir qatorni alohida bold qilamiz
        $text .= "\n<b>⚠️ Eslatma:</b>\n";
        $text .= "<b>Mashinani ko'rmasdan pul tashlamang.</b>\n";
        $text .= "<b>Kanal savdoga mas'ul emas.</b>";

        // 1024 belgi limit (Telegram caption limit)
        if (mb_strlen($text) > 1024) {
            // Eslatma matnini qisqartirish
            $mainText = mb_substr($text, 0, mb_strrpos($text, "\n\n"));
            $text = $mainText . "\n\n<b>⚠️ Eslatma:</b>\n<b>Mashinani ko'rmasdan pul tashlamang.</b>\n<b>Kanal savdoga mas'ul emas.</b>";
            
            // Agar hali ham uzun bo'lsa, asosiy matnni qisqartirish
            if (mb_strlen($text) > 1024) {
                $text = mb_substr($text, 0, 1020) . '...';
            }
        }

        return $text;
    }

    /**
     * Rasmlarni kanalga yuborish
     */
    private function sendImagesToChannel(Api $telegram, string $channelId, $images, string $caption, string $botToken): void
    {
        try {
            // Faqat local_path bo'lgan rasmlarni ajratish (o'zimizda saqlangan fayllar)
            $validImages = $images->filter(function ($image) {
                return !empty($image->local_path);
            });

            if ($validImages->isEmpty()) {
                Log::warning("SendElonToChannelJob: No valid images found, sending text only", [
                    'elon_id' => $this->elonId,
                    'images_count' => $images->count(),
                ]);
                
                $telegram->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ]);
                return;
            }

            $count = $validImages->count();
            
            Log::info("SendElonToChannelJob: Processing images", [
                'elon_id' => $this->elonId,
                'total_images' => $images->count(),
                'valid_images' => $count,
            ]);
            
            if ($count === 1) {
                // 1 ta rasm bo'lsa → sendPhoto
                $image = $validImages->first();
                $photo = $this->getPhotoInput($image, $botToken);
                
                if (!$photo) {
                    Log::error("SendElonToChannelJob: Local file not found for image", [
                        'image_id' => $image->id,
                        'local_path' => $image->local_path ?? null,
                    ]);
                    $telegram->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                    return;
                }
                
                $telegram->sendPhoto([
                    'chat_id' => $channelId,
                    'photo' => $photo,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                // 2-10 ta rasm bo'lsa → ularni birlashtirib bitta rasm yaratamiz va yuboramiz
                $collagePath = $this->createImageCollage($validImages->take(10));
                
                if ($collagePath) {
                    // Birlashtirilgan rasmni caption bilan yuboramiz
                    $photo = InputFile::create($collagePath);
                    $telegram->sendPhoto([
                        'chat_id' => $channelId,
                        'photo' => $photo,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                    
                    // Vaqtinchalik faylni o'chirish
                    @unlink($collagePath);
                } else {
                    // Agar birlashtirishda xatolik bo'lsa, faqat text yuboramiz
                    $telegram->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("SendElonToChannelJob: Error sending images", [
                'channel_id' => $channelId,
                'elon_id' => $this->elonId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Rasm uchun input olish - faqat local fayldan
     * 
     * Faqat o'zimizda saqlangan fayllardan foydalanamiz
     * 
     * @return InputFile|string|null
     */
    private function getPhotoInput($image, string $botToken)
    {
        // Faqat local_path'ni tekshiramiz
        if (empty($image->local_path)) {
            Log::error("SendElonToChannelJob: Image has no local_path", [
                'image_id' => $image->id,
            ]);
            return null;
        }

        $fullPath = Storage::disk('public')->path($image->local_path);
        
        if (!file_exists($fullPath)) {
            Log::error("SendElonToChannelJob: Local file not found", [
                'image_id' => $image->id,
                'local_path' => $image->local_path,
                'full_path' => $fullPath,
            ]);
            return null;
        }

        // InputFile yordamida local faylni yuboramiz
        Log::info("SendElonToChannelJob: Creating InputFile from local path", [
            'image_id' => $image->id,
            'local_path' => $image->local_path,
            'full_path' => $fullPath,
        ]);
        
        try {
            $inputFile = InputFile::create($fullPath);
            Log::info("SendElonToChannelJob: InputFile created successfully", [
                'image_id' => $image->id,
            ]);
            return $inputFile;
        } catch (\Exception $e) {
            Log::warning("SendElonToChannelJob: InputFile::create failed, using direct path", [
                'image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);
            // To'g'ridan-to'g'ri path yuboramiz
            return $fullPath;
        }
    }

    /**
     * sendMediaGroup ni to'g'ridan-to'g'ri Telegram API'ga yuborish (SDK'siz)
     * 
     * Bu metod SDK'dan foydalanmasdan, to'g'ridan-to'g'ri HTTP request yuboradi
     */
    private function sendMediaGroupDirect(string $botToken, string $channelId, $images, string $caption): void
    {
        $fileHandles = [];
        
        try {
            $apiUrl = "https://api.telegram.org/bot{$botToken}/sendMediaGroup";
            
            // Media array'ni tayyorlash
            $media = [];
            $multipart = [
                ['name' => 'chat_id', 'contents' => $channelId],
            ];
            
            foreach ($images as $index => $image) {
                if (empty($image->local_path)) {
                    continue;
                }
                
                $fullPath = Storage::disk('public')->path($image->local_path);
                
                if (!file_exists($fullPath)) {
                    Log::warning("SendElonToChannelJob: File not found for media group", [
                        'image_id' => $image->id,
                        'path' => $fullPath,
                    ]);
                    continue;
                }
                
                // Media item uchun JSON (caption'siz - barcha rasmlar bir guruh bo'lishi uchun)
                $mediaItem = [
                    'type' => 'photo',
                    'media' => "attach://photo_{$index}",
                ];
                
                // Caption qo'shmaymiz - barcha rasmlar bir guruh bo'lishi uchun
                // Keyin alohida elon matnini yuboramiz
                
                $media[] = $mediaItem;
                
                // Faylni ochish va handle'ni saqlash
                $fileHandle = fopen($fullPath, 'r');
                if ($fileHandle === false) {
                    Log::error("SendElonToChannelJob: Failed to open file", [
                        'image_id' => $image->id,
                        'path' => $fullPath,
                    ]);
                    continue;
                }
                
                $fileHandles[] = $fileHandle;
                
                // Multipart uchun fayl qo'shamiz
                $multipart[] = [
                    'name' => "photo_{$index}",
                    'contents' => $fileHandle,
                    'filename' => basename($fullPath),
                ];
            }
            
            if (empty($media)) {
                Log::warning("SendElonToChannelJob: No valid media items for media group", [
                    'elon_id' => $this->elonId,
                ]);
                return;
            }
            
            // Media array'ni JSON string sifatida qo'shamiz
            $multipart[] = [
                'name' => 'media',
                'contents' => json_encode($media),
            ];
            
            Log::info("SendElonToChannelJob: Sending media group directly to Telegram API", [
                'elon_id' => $this->elonId,
                'media_count' => count($media),
            ]);
            
            // HTTP request yuborish
            $response = Http::asMultipart()->post($apiUrl, $multipart);
            
            if ($response->successful()) {
                Log::info("SendElonToChannelJob: Media group sent successfully", [
                    'elon_id' => $this->elonId,
                    'count' => count($media),
                ]);
                
                // Media group yuborilgandan keyin, elon matnini alohida yuboramiz
                // Bu Telegram'da rasmlar tagida ko'rinadi
                $this->sendCaptionAfterMediaGroup($botToken, $channelId, $caption);
            } else {
                Log::error("SendElonToChannelJob: Failed to send media group", [
                    'elon_id' => $this->elonId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                throw new \Exception("Failed to send media group: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("SendElonToChannelJob: Error sending media group directly", [
                'elon_id' => $this->elonId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            // Fayllarni yopish
            foreach ($fileHandles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    }

    /**
     * Bir nechta rasmlarni birlashtirib bitta rasm (collage) yaratish
     * 
     * @param $images
     * @return string|null Birlashtirilgan rasmning to'liq path'i yoki null
     */
    private function createImageCollage($images): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            Log::error("SendElonToChannelJob: GD library not available");
            return null;
        }

        try {
            $imageCount = $images->count();
            if ($imageCount === 0) {
                return null;
            }

            // Grid o'lchamlari (2x2, 2x3, 3x3, va hokazo)
            $cols = $imageCount <= 4 ? 2 : 3;
            $rows = ceil($imageCount / $cols);
            
            // Har bir rasm uchun o'lcham (max 1000px width, 1000px height)
            $thumbWidth = 1000 / $cols;
            $thumbHeight = 1000 / $rows;
            
            // Umumiy canvas o'lchami
            $canvasWidth = $thumbWidth * $cols;
            $canvasHeight = $thumbHeight * $rows;
            
            // Canvas yaratish
            $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            
            $index = 0;
            foreach ($images as $image) {
                if (empty($image->local_path)) {
                    continue;
                }
                
                $fullPath = Storage::disk('public')->path($image->local_path);
                
                if (!file_exists($fullPath)) {
                    continue;
                }
                
                // Rasmni yuklash
                $sourceImage = $this->loadImage($fullPath);
                if (!$sourceImage) {
                    continue;
                }
                
                // Source o'lchamlari
                $sourceWidth = imagesx($sourceImage);
                $sourceHeight = imagesy($sourceImage);
                
                // Aspect ratio'ni saqlab qolish - rasmlarni qirqmasdan to'liq ko'rsatish
                $sourceAspect = $sourceWidth / $sourceHeight;
                $thumbAspect = $thumbWidth / $thumbHeight;
                
                // Rasmni qirqmasdan, to'liq ko'rsatish uchun o'lchamlarni hisoblash
                if ($sourceAspect > $thumbAspect) {
                    // Rasm kengroq - balandlikni to'liq ishlatamiz
                    $newHeight = $thumbHeight;
                    $newWidth = (int)($thumbHeight * $sourceAspect);
                } else {
                    // Rasm balandroq - kenglikni to'liq ishlatamiz
                    $newWidth = $thumbWidth;
                    $newHeight = (int)($thumbWidth / $sourceAspect);
                }
                
                // Thumbnail yaratish (oq fon bilan)
                $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
                $white = imagecolorallocate($thumb, 255, 255, 255);
                imagefill($thumb, 0, 0, $white);
                
                // Markazga joylashtirish (rasm qirqilmaydi, faqat markazga joylashtiriladi)
                $x = (int)(($thumbWidth - $newWidth) / 2);
                $y = (int)(($thumbHeight - $newHeight) / 2);
                
                // Rasmni resize qilish va markazga joylashtirish (qirqmasdan)
                imagecopyresampled(
                    $thumb,
                    $sourceImage,
                    $x, $y, 0, 0,
                    $newWidth, $newHeight,  // Yangi o'lcham (qirqilmaydi)
                    $sourceWidth, $sourceHeight  // Original o'lcham
                );
                
                // Canvas'ga joylashtirish
                $col = $index % $cols;
                $row = (int)($index / $cols);
                $xPos = $col * $thumbWidth;
                $yPos = $row * $thumbHeight;
                
                imagecopy($canvas, $thumb, $xPos, $yPos, 0, 0, $thumbWidth, $thumbHeight);
                
                // Memory tozalash
                imagedestroy($sourceImage);
                imagedestroy($thumb);
                
                $index++;
            }
            
            // Birlashtirilgan rasmni saqlash
            $outputPath = storage_path('app/public/temp/collage_' . $this->elonId . '_' . time() . '.jpg');
            
            // Temp papkani yaratish
            $tempDir = dirname($outputPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            imagejpeg($canvas, $outputPath, 85);
            imagedestroy($canvas);
            
            Log::info("SendElonToChannelJob: Image collage created", [
                'elon_id' => $this->elonId,
                'image_count' => $imageCount,
                'output_path' => $outputPath,
            ]);
            
            return $outputPath;
        } catch (\Exception $e) {
            Log::error("SendElonToChannelJob: Error creating image collage", [
                'elon_id' => $this->elonId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Rasmni yuklash (turli formatlarni qo'llab-quvvatlaydi)
     */
    private function loadImage(string $path)
    {
        if (!file_exists($path)) {
            return false;
        }
        
        $imageInfo = getimagesize($path);
        if (!$imageInfo) {
            return false;
        }
        
        $mimeType = $imageInfo['mime'];
        
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                return imagecreatefromjpeg($path);
            case 'image/png':
                return imagecreatefrompng($path);
            case 'image/gif':
                return imagecreatefromgif($path);
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    return imagecreatefromwebp($path);
                }
                break;
        }
        
        return false;
    }

    /**
     * Media group yuborilgandan keyin elon matnini yuborish
     */
    private function sendCaptionAfterMediaGroup(string $botToken, string $channelId, string $caption): void
    {
        try {
            $apiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
            
            $response = Http::post($apiUrl, [
                'chat_id' => $channelId,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ]);
            
            if ($response->successful()) {
                Log::info("SendElonToChannelJob: Caption sent after media group", [
                    'elon_id' => $this->elonId,
                ]);
            } else {
                Log::error("SendElonToChannelJob: Failed to send caption after media group", [
                    'elon_id' => $this->elonId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error("SendElonToChannelJob: Error sending caption after media group", [
                'elon_id' => $this->elonId,
                'error' => $e->getMessage(),
            ]);
            // Caption yuborishda xatolik bo'lsa ham, media group yuborilgan bo'ladi
        }
    }
}
