<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bot extends Model
{
    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'slug',
        'type',
        'token',
        'channel_id',
        'name',
        'description',
        'webhook_url',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope a query to only include active bots.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to find bot by slug.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $slug
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }

    /**
     * Telegram groups bilan relationship
     */
    public function telegramGroups(): HasMany
    {
        return $this->hasMany(TelegramGroup::class);
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($bot) {
            // Webhook URL'ni avtomatik yaratish
            if ($bot->slug) {
                $appUrl = config('app.url');
                $baseUrl = rtrim($appUrl, '/') . '/api/bot/' . $bot->slug;
                
                // Bot type'ga qarab webhook URL yaratish
                if ($bot->type === 'elon') {
                    $bot->webhook_url = $baseUrl . '/elon/webhook';
                } else {
                    $bot->webhook_url = $baseUrl . '/webhook';
                }
            }
        });
    }
}
