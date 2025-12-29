<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Elon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
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
     * Rasmlarni kanalga yuborish (Media Group yoki bitta rasm)
     */
    private function sendImagesToChannel(Api $telegram, string $channelId, $images, string $caption, string $botToken): void
    {
        try {
            $imageCount = $images->count();
            
            if ($imageCount === 0) {
                Log::warning("SendElonToChannelJob: No images found, sending text only", [
                    'elon_id' => $this->elonId,
                ]);
                
                $telegram->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ]);
                return;
            }

            Log::info("SendElonToChannelJob: Processing images", [
                'elon_id' => $this->elonId,
                'images_count' => $imageCount,
            ]);

            // Caption'ni tayyorlash
            $finalCaption = !empty($caption) ? $caption : '';
            
            // Telegram caption limit: 1024 belgi
            if (mb_strlen($finalCaption) > 1024) {
                $finalCaption = mb_substr($finalCaption, 0, 1020) . '...';
            }

            // Barcha rasmlar file_id bilan bo'lsa, SDK orqali yuborish
            $allHaveFileId = $images->every(function ($image) {
                return !empty($image->file_id);
            });

            if ($allHaveFileId && $imageCount > 1) {
                // SDK orqali media group yuborish (file_id bilan)
                $media = [];
                foreach ($images->take(10) as $index => $image) {
                    $media[] = [
                        'type' => 'photo',
                        'media' => $image->file_id,
                    ];
                }
                
                if (!empty($media)) {
                    $media[0]['caption'] = $finalCaption;
                    $media[0]['parse_mode'] = 'HTML';
                    
                    $telegram->sendMediaGroup([
                        'chat_id' => $channelId,
                        'media' => json_encode($media),
                    ]);
                    
                    Log::info("SendElonToChannelJob: Images sent via SDK with file_id", [
                        'elon_id' => $this->elonId,
                        'count' => count($media),
                    ]);
                    return;
                }
            }
            
            if ($imageCount === 1) {
                // 1 ta rasm bo'lsa → sendPhoto
                $image = $images->first();
                $photo = $this->getPhotoInput($image, $botToken);
                
                if (!$photo) {
                    Log::error("SendElonToChannelJob: Image not found", [
                        'image_id' => $image->id,
                        'file_id' => $image->file_id ?? null,
                        's3_path' => $image->s3_path ?? null,
                        's3_url' => $image->s3_url ?? null,
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
                    'caption' => $finalCaption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                // 2-10 ta rasm bo'lsa → Media Group qilib yuborish
                $this->sendMediaGroupToChannel($botToken, $channelId, $images->take(10), $finalCaption);
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
     * Rasm uchun input olish - file_id, S3 yoki local fayldan
     * Adminga yuborish bilan bir xil tartib
     * 
     * @return InputFile|string|null
     */
    private function getPhotoInput($image, string $botToken)
    {
        // Avval file_id'ni ishlatish (eng tez)
        if (!empty($image->file_id)) {
            Log::info("SendElonToChannelJob: Using file_id", [
                'image_id' => $image->id,
                'file_id' => $image->file_id,
            ]);
            return $image->file_id;
        }

        // Keyin S3 URL'ni tekshiramiz
        if (!empty($image->s3_url)) {
            Log::info("SendElonToChannelJob: Using S3 URL", [
                'image_id' => $image->id,
                's3_url' => $image->s3_url,
            ]);
            return $image->s3_url;
        }

        // Keyin S3 path'ni tekshiramiz
        if (!empty($image->s3_path) && Storage::disk('s3')->exists($image->s3_path)) {
            try {
                // S3'dan faylni yuklab olish
                $fileContent = Storage::disk('s3')->get($image->s3_path);
                $tempFile = tmpfile();
                $tempPath = stream_get_meta_data($tempFile)['uri'];
                file_put_contents($tempPath, $fileContent);
                
                Log::info("SendElonToChannelJob: Using S3 path (downloaded to temp)", [
                    'image_id' => $image->id,
                    's3_path' => $image->s3_path,
                ]);
                
                return $tempPath;
            } catch (\Exception $e) {
                Log::error("SendElonToChannelJob: Failed to get file from S3", [
                    'image_id' => $image->id,
                    's3_path' => $image->s3_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Keyin image_url (Telegram URL)
        if (!empty($image->image_url)) {
            Log::info("SendElonToChannelJob: Using image_url", [
                'image_id' => $image->id,
                'image_url' => $image->image_url,
            ]);
            return $image->image_url;
        }

        // Oxirgi variant - local_path
        if (!empty($image->local_path)) {
            $fullPath = Storage::disk('public')->path($image->local_path);
            
            if (file_exists($fullPath)) {
                Log::info("SendElonToChannelJob: Using local path", [
                    'image_id' => $image->id,
                    'local_path' => $image->local_path,
                ]);
                
                try {
                    return InputFile::create($fullPath);
                } catch (\Exception $e) {
                    Log::warning("SendElonToChannelJob: InputFile::create failed, using direct path", [
                        'image_id' => $image->id,
                        'error' => $e->getMessage(),
                    ]);
                    return $fullPath;
                }
            }
        }

        Log::error("SendElonToChannelJob: No valid image source found", [
            'image_id' => $image->id,
        ]);
        return null;
    }


    /**
     * Media Group'ni kanalga yuborish
     */
    private function sendMediaGroupToChannel(string $botToken, string $channelId, $images, string $caption): void
    {
        $fileHandles = [];
        $tempFiles = [];
        
        try {
            $apiUrl = "https://api.telegram.org/bot{$botToken}/sendMediaGroup";
            
            // Media array'ni tayyorlash
            $media = [];
            $multipart = [
                ['name' => 'chat_id', 'contents' => $channelId],
            ];
            
            Log::info("SendElonToChannelJob: Starting media group preparation", [
                'elon_id' => $this->elonId,
                'images_count' => $images->count(),
            ]);
            
            foreach ($images as $index => $image) {
                if ($index >= 10) { // Telegram maksimum 10 ta rasm qabul qiladi
                    break;
                }
                
                Log::info("SendElonToChannelJob: Processing image for media group", [
                    'elon_id' => $this->elonId,
                    'image_id' => $image->id,
                    'index' => $index,
                    's3_path' => $image->s3_path ?? null,
                    's3_url' => $image->s3_url ?? null,
                    'local_path' => $image->local_path ?? null,
                ]);
                
                $mediaValue = null;
                $useAttachment = false;
                
                // Avval file_id'ni ishlatish (eng tez)
                if ($image->file_id) {
                    $mediaValue = $image->file_id;
                    Log::info("SendElonToChannelJob: Using file_id for media group", [
                        'image_id' => $image->id,
                        'file_id' => $image->file_id,
                    ]);
                } elseif ($image->s3_url) {
                    // S3 URL'ni ishlatish
                    $mediaValue = $image->s3_url;
                    Log::info("SendElonToChannelJob: Using S3 URL for media group", [
                        'image_id' => $image->id,
                        's3_url' => $image->s3_url,
                    ]);
                } elseif ($image->s3_path && Storage::disk('s3')->exists($image->s3_path)) {
                    // S3'dan faylni yuklab olish va yuborish
                    try {
                        $fileContent = Storage::disk('s3')->get($image->s3_path);
                        $tempFile = tmpfile();
                        $tempPath = stream_get_meta_data($tempFile)['uri'];
                        file_put_contents($tempPath, $fileContent);
                        $tempFiles[] = $tempPath;
                        
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
                            
                            Log::info("SendElonToChannelJob: Image loaded from S3 path", [
                                'image_id' => $image->id,
                                's3_path' => $image->s3_path,
                                'temp_path' => $tempPath,
                            ]);
                        } else {
                            Log::warning("SendElonToChannelJob: Failed to open temp file", [
                                'image_id' => $image->id,
                                's3_path' => $image->s3_path,
                            ]);
                            continue;
                        }
                    } catch (\Exception $e) {
                        Log::error("SendElonToChannelJob: Error getting file from S3", [
                            'image_id' => $image->id,
                            's3_path' => $image->s3_path,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                } elseif ($image->image_url) {
                    // Telegram URL'ni ishlatish
                    $mediaValue = $image->image_url;
                    Log::info("SendElonToChannelJob: Using image_url for media group", [
                        'image_id' => $image->id,
                        'image_url' => $image->image_url,
                    ]);
                } elseif ($image->local_path) {
                    // Oxirgi variant - local_path
                    $fullPath = Storage::disk('public')->path($image->local_path);
                    
                    if (file_exists($fullPath)) {
                        $mediaValue = "attach://photo_{$index}";
                        $useAttachment = true;
                        
                        $fileHandle = fopen($fullPath, 'r');
                        if ($fileHandle === false) {
                            Log::error("SendElonToChannelJob: Failed to open file", [
                                'image_id' => $image->id,
                                'path' => $fullPath,
                            ]);
                            continue;
                        }
                        
                        $fileHandles[] = $fileHandle;
                        $multipart[] = [
                            'name' => "photo_{$index}",
                            'contents' => $fileHandle,
                            'filename' => basename($fullPath),
                        ];
                        
                        Log::info("SendElonToChannelJob: Using local path for media group", [
                            'image_id' => $image->id,
                            'local_path' => $image->local_path,
                        ]);
                    } else {
                        Log::warning("SendElonToChannelJob: File not found", [
                            'image_id' => $image->id,
                            'path' => $fullPath,
                        ]);
                    }
                }
                
                // Agar hali ham mediaValue null bo'lsa, rasmni o'tkazib yuboramiz
                if (!$mediaValue) {
                    Log::warning("SendElonToChannelJob: No valid image source", [
                        'image_id' => $image->id,
                        's3_path' => $image->s3_path ?? null,
                        's3_url' => $image->s3_url ?? null,
                        'local_path' => $image->local_path ?? null,
                    ]);
                    continue;
                }
                
                // Media item uchun JSON
                if ($mediaValue) {
                    $mediaItem = [
                        'type' => 'photo',
                        'media' => $mediaValue,
                    ];
                    
                    // Birinchi rasmga caption qo'shamiz
                    if ($index === 0 && !empty($caption)) {
                        // Telegram caption limit: 1024 belgi
                        $finalCaption = mb_strlen($caption) > 1024 ? mb_substr($caption, 0, 1020) . '...' : $caption;
                        $mediaItem['caption'] = $finalCaption;
                        $mediaItem['parse_mode'] = 'HTML';
                    }
                    
                    $media[] = $mediaItem;
                    
                    Log::info("SendElonToChannelJob: Image added to media group", [
                        'elon_id' => $this->elonId,
                        'image_id' => $image->id,
                        'index' => $index,
                        'media_value' => $mediaValue,
                    ]);
                } else {
                    Log::warning("SendElonToChannelJob: Media value is null, skipping image", [
                        'elon_id' => $this->elonId,
                        'image_id' => $image->id,
                        'index' => $index,
                    ]);
                }
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
            
            Log::info("SendElonToChannelJob: Sending media group to channel", [
                'elon_id' => $this->elonId,
                'media_count' => count($media),
                'valid_images' => count($media),
            ]);
            
            // HTTP request yuborish
            $response = Http::asMultipart()->post($apiUrl, $multipart);
            
            if ($response->successful()) {
                Log::info("SendElonToChannelJob: Media group sent successfully", [
                    'elon_id' => $this->elonId,
                    'count' => count($media),
                    'response' => $response->json(),
                ]);
            } else {
                Log::error("SendElonToChannelJob: Failed to send media group", [
                    'elon_id' => $this->elonId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                throw new \Exception("Failed to send media group: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("SendElonToChannelJob: Error sending media group", [
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
            // Vaqtinchalik fayllarni o'chirish
            foreach ($tempFiles as $tempPath) {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }
    }

    /**
     * Bir nechta rasmlarni birlashtirib bitta rasm (collage) yaratish
     * Intervention Image v3 bilan
     * 
     * @param $images
     * @return string|null Birlashtirilgan rasmning to'liq path'i yoki null
     */
    private function createImageCollage($images): ?string
    {
        try {
            $imageCount = $images->count();
            if ($imageCount === 0 || $imageCount > 4) {
                return null;
            }

            // Intervention Image Manager yaratish
            $manager = new ImageManager(new Driver());

            // Grid layout aniqlash
            $layout = $this->getGridLayout($imageCount);
            $cols = $layout['cols'];
            $rows = $layout['rows'];
            
            // Har bir rasm uchun o'lcham (1000px width, 1000px height maksimum)
            $cellWidth = 1000 / $cols;
            $cellHeight = 1000 / $rows;
            
            // Canvas o'lchami
            $canvasWidth = (int)($cellWidth * $cols);
            $canvasHeight = (int)($cellHeight * $rows);
            
            // Canvas yaratish (oq fon)
            $canvas = $manager->create($canvasWidth, $canvasHeight);
            
            $index = 0;
            $loadedImages = [];
            
            foreach ($images as $image) {
                try {
                    // Rasmni yuklash (S3 yoki local)
                    $sourceImage = $this->loadImageForCollage($image);
                    if (!$sourceImage) {
                        Log::warning("SendElonToChannelJob: Failed to load image", [
                            'image_id' => $image->id ?? null,
                        ]);
                        continue;
                    }
                    
                    // Rasmni kichraytirish (aspect ratio saqlab, cover qilib)
                    $resized = $sourceImage->cover($cellWidth, $cellHeight);
                    $loadedImages[] = [
                        'image' => $resized,
                        'index' => $index,
                    ];
                    
                    $index++;
                } catch (\Exception $e) {
                    Log::warning("SendElonToChannelJob: Error processing image for collage", [
                        'image_id' => $image->id ?? null,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    continue;
                }
            }
            
            // Agar hech qanday rasm yuklanmagan bo'lsa
            if (empty($loadedImages)) {
                Log::error("SendElonToChannelJob: No images loaded for collage", [
                    'elon_id' => $this->elonId,
                ]);
                return null;
            }
            
            // Rasmlarni canvas'ga joylashtirish
            foreach ($loadedImages as $item) {
                $resized = $item['image'];
                $imgIndex = $item['index'];
                
                // Grid pozitsiyasi
                $col = $imgIndex % $cols;
                $row = (int)($imgIndex / $cols);
                $xPos = (int)($col * $cellWidth);
                $yPos = (int)($row * $cellHeight);
                
                // Canvas'ga joylashtirish
                $canvas->place($resized, 'top-left', $xPos, $yPos);
            }
            
            // Temp papkani yaratish
            $tempDir = storage_path('app/public/temp');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            // Birlashtirilgan rasmni saqlash
            $outputPath = $tempDir . '/collage_' . $this->elonId . '_' . time() . '.jpg';
            $canvas->toJpeg(85)->save($outputPath);
            
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
     * Grid layout aniqlash
     */
    private function getGridLayout(int $count): array
    {
        return match($count) {
            1 => ['cols' => 1, 'rows' => 1],
            2 => ['cols' => 1, 'rows' => 2],
            3 => ['cols' => 2, 'rows' => 2],
            4 => ['cols' => 2, 'rows' => 2],
            default => ['cols' => 2, 'rows' => 2],
        };
    }

    /**
     * Collage uchun rasmni yuklash (S3 yoki local)
     */
    private function loadImageForCollage($image)
    {
        $manager = new ImageManager(new Driver());
        
        // Avval S3 path'ni tekshiramiz (eng ishonchli)
        if (!empty($image->s3_path)) {
            try {
                if (Storage::disk('s3')->exists($image->s3_path)) {
                    $fileContent = Storage::disk('s3')->get($image->s3_path);
                    return $manager->read($fileContent);
                }
            } catch (\Exception $e) {
                Log::warning("SendElonToChannelJob: Failed to load image from S3 path", [
                    'image_id' => $image->id,
                    's3_path' => $image->s3_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Keyin S3 URL'ni HTTP orqali yuklab olish
        if (!empty($image->s3_url)) {
            try {
                $response = Http::timeout(30)->get($image->s3_url);
                if ($response->successful()) {
                    $fileContent = $response->body();
                    return $manager->read($fileContent);
                }
            } catch (\Exception $e) {
                Log::warning("SendElonToChannelJob: Failed to load image from S3 URL", [
                    'image_id' => $image->id,
                    's3_url' => $image->s3_url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Oxirgi variant - local_path
        if (!empty($image->local_path)) {
            $fullPath = Storage::disk('public')->path($image->local_path);
            if (file_exists($fullPath)) {
                try {
                    return $manager->read($fullPath);
                } catch (\Exception $e) {
                    Log::warning("SendElonToChannelJob: Failed to load image from local path", [
                        'image_id' => $image->id,
                        'local_path' => $image->local_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        return null;
    }


}
