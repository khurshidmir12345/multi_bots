<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TelegramGroup extends Model
{
    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'bot_id',
        'telegram_group_id',
        'title',
        'type',
        'status',
        'chat_members_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'telegram_group_id' => 'integer',
        'status' => 'boolean',
        'chat_members_count' => 'integer',
    ];

    /**
     * Bot bilan relationship
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Bot users bilan relationship (many-to-many)
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(BotUser::class, 'group_user', 'group_id', 'bot_user_id')
            ->withPivot('joined_at', 'left_at')
            ->withTimestamps();
    }

    /**
     * Scope a query to only include active groups.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * Scope a query to only include left groups.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLeft($query)
    {
        return $query->where('status', false);
    }

    /**
     * Scope a query to find group by telegram_group_id.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $telegramGroupId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTelegramGroupId($query, int $telegramGroupId)
    {
        return $query->where('telegram_group_id', $telegramGroupId);
    }
}
