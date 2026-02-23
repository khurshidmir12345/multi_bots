<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Elon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Api;

class SendElonToChannelJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $elonId,
        public int $botId
    ) {}

    public function handle(): void
    {
        $elon = Elon::find($this->elonId);
        $bot = Bot::find($this->botId);

        if (!$elon || !$bot) {
            return;
        }

        if ($elon->cancelled_from_admin || $elon->cancelled_from_user || $elon->status === Elon::STATUS_COMPLATED) {
            return;
        }

        if (!$bot->channel_id) {
            Log::error("SendElonToChannelJob: Channel ID not found", ['bot_id' => $this->botId]);
            return;
        }

        try {
            $telegram = new Api($bot->token);
            $images = $elon->images()->get();
            $caption = $this->formatElonForChannel($elon);

            $messageId = null;

            if ($images->isNotEmpty()) {
                $messageId = $this->sendImagesToChannel($telegram, $bot->channel_id, $images, $caption, $bot->token);
            } else {
                $response = $telegram->sendMessage([
                    'chat_id' => $bot->channel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ]);
                $messageId = $response->messageId ?? null;
            }

            $elon->status = Elon::STATUS_COMPLATED;
            if ($messageId) {
                $elon->elon_message_id = (string) $messageId;
            }
            $elon->save();
        } catch (\Exception $e) {
            Log::error("SendElonToChannelJob: Error", [
                'elon_id' => $this->elonId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function formatElonForChannel(Elon $elon): string
    {
        $modelForHashtag = $elon->modeli ?? '';
        $hashtag = '#' . preg_replace('/[^a-zA-Z0-9_]/', '_', mb_strtolower($modelForHashtag));
        $hashtag = htmlspecialchars($hashtag);
        
        $text = "♻️♻️ " . $hashtag . " Сотилади ♻️♻️\n\n";
        $text .= "🚗 <b>Модел:</b> " . htmlspecialchars($elon->modeli ?? '-') . "\n";
        $text .= "🔧 <b>Позицияси:</b> " . htmlspecialchars($elon->pozitsiyasi ?? '-') . "\n";
        $text .= "🎨 <b>Ранги:</b> " . htmlspecialchars($elon->rangi ?? '-') . "\n";
        $text .= "🖌️ <b>Краскаси:</b> " . htmlspecialchars($elon->kraskasi ?? '-') . "\n";
        
        $yilText = ($elon->yili ?? '-');
        if ($yilText !== '-') {
            $yilText = $yilText . ' йил';
        }
        $text .= "📆 <b>Йил:</b> " . $yilText . "\n";
        
        $probegText = '-';
        if ($elon->yurgani) {
            $probegText = number_format($elon->yurgani, 0, '.', ' ') . ' км';
        }
        $text .= "📏 <b>Пробег:</b> " . $probegText . "\n";
        $text .= "⛽ <b>Ёқилғи:</b> " . htmlspecialchars($elon->yoqilgisi ?? '-') . "\n";
        
        if ($elon->narxi) {
            $currencySymbol = $elon->currency === 'dollar' ? '$' : '';
            $currencyText = $elon->currency === 'dollar' ? '' : ' сўм';
            $text .= "💰 <b>Нархи:</b> " . $currencySymbol . number_format($elon->narxi, 0, '.', ' ') . $currencyText . "\n";
        }
        
        $text .= "📞 <b>Тел:</b> " . htmlspecialchars($elon->tel_1 ?? '-') . "\n";
        if ($elon->tel_2) {
            $text .= "📞 <b>Тел 2:</b> " . htmlspecialchars($elon->tel_2) . "\n";
        }
        $text .= "📍 <b>Манзил:</b> " . htmlspecialchars($elon->manzil ?? '-') . "\n";
        
        $botUsername = config('elon.bot_username');
        if ($botUsername) {
            $text .= "\nЭлон бериш бepул :\n@" . htmlspecialchars($botUsername) . "\n";
        }
        
        $text .= "\n<b>⚠️ Эслатма:</b>\n";
        $text .= "<b>Машинани кўрмасдан пул ташламанг.</b>\n";
        $text .= "<b>Канал савдога масъул эмас.</b>";

        if (mb_strlen($text) > 1024) {
            $text = mb_substr($text, 0, 1020) . '...';
        }

        return $text;
    }

    /**
     * @return int|null message_id
     */
    private function sendImagesToChannel(Api $telegram, string $channelId, $images, string $caption, string $botToken): ?int
    {
        $imageCount = $images->count();

        if ($imageCount === 0) {
            $response = $telegram->sendMessage([
                'chat_id' => $channelId,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ]);
            return $response->messageId ?? null;
        }

        $finalCaption = mb_strlen($caption) > 1024 ? mb_substr($caption, 0, 1020) . '...' : $caption;

        // file_id bilan media group (eng tez yo'l)
        $allHaveFileId = $images->every(fn($img) => !empty($img->file_id));

        if ($allHaveFileId && $imageCount > 1) {
            $media = [];
            foreach ($images->take(10) as $image) {
                $media[] = ['type' => 'photo', 'media' => $image->file_id];
            }
            $media[0]['caption'] = $finalCaption;
            $media[0]['parse_mode'] = 'HTML';
            
            $response = $telegram->sendMediaGroup([
                'chat_id' => $channelId,
                'media' => json_encode($media),
            ]);
            
            if (is_array($response) && !empty($response)) {
                return $response[0]->messageId ?? null;
            }
            return null;
        }
        
        if ($imageCount === 1) {
            $image = $images->first();
            $photo = $image->file_id ?: null;
            
            if (!$photo) {
                $response = $telegram->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ]);
                return $response->messageId ?? null;
            }
            
            $response = $telegram->sendPhoto([
                'chat_id' => $channelId,
                'photo' => $photo,
                'caption' => $finalCaption,
                'parse_mode' => 'HTML',
            ]);
            return $response->messageId ?? null;
        }

        return $this->sendMediaGroupViaHttp($botToken, $channelId, $images->take(10), $finalCaption);
    }

    /**
     * @return int|null message_id
     */
    private function sendMediaGroupViaHttp(string $botToken, string $channelId, $images, string $caption): ?int
    {
        $media = [];
        $multipart = [
            ['name' => 'chat_id', 'contents' => $channelId],
        ];
        
        foreach ($images as $index => $image) {
            if (!$image->file_id) {
                continue;
            }

            $mediaItem = ['type' => 'photo', 'media' => $image->file_id];
            
            if ($index === 0 && !empty($caption)) {
                $mediaItem['caption'] = $caption;
                $mediaItem['parse_mode'] = 'HTML';
            }
            
            $media[] = $mediaItem;
        }
        
        if (empty($media)) {
            return null;
        }
        
        $multipart[] = ['name' => 'media', 'contents' => json_encode($media)];
        
        $response = Http::asMultipart()->post(
            "https://api.telegram.org/bot{$botToken}/sendMediaGroup",
            $multipart
        );
        
        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['result'][0]['message_id'])) {
                return $data['result'][0]['message_id'];
            }
        } else {
            throw new \Exception("Media group failed: " . $response->body());
        }
        
        return null;
    }
}
