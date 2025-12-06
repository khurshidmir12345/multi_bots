<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BotUser extends Model
{
    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'is_bot',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'telegram_user_id' => 'integer',
        'is_bot' => 'boolean',
    ];

    /**
     * Scope a query to only include active users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include banned users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBanned($query)
    {
        return $query->where('status', 'banned');
    }

    /**
     * Scope a query to only include left users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLeft($query)
    {
        return $query->where('status', 'left');
    }

    /**
     * Scope a query to find user by telegram_user_id.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $telegramUserId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTelegramUserId($query, int $telegramUserId)
    {
        return $query->where('telegram_user_id', $telegramUserId);
    }

    /**
     * Telegram groups bilan relationship (many-to-many)
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(TelegramGroup::class, 'group_user', 'bot_user_id', 'group_id')
            ->withPivot('joined_at', 'left_at')
            ->withTimestamps();
    }

    /**
     * Full name accessor
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . ($this->last_name ?? ''));
    }
}
