<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Elon extends Model
{
    use SoftDeletes;

    /**
     * Jadval nomi
     *
     * @var string
     */
    protected $table = 'elonlar';

    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'elon_user_id',
        'modeli',
        'pozitsiyasi',
        'rangi',
        'kraskasi',
        'yili',
        'yurgani',
        'yoqilgisi',
        'narxi',
        'currency',
        'tel_1',
        'tel_2',
        'manzil',
        'status',
        'cancelled_from_admin',
        'cancelled_from_user',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'elon_user_id' => 'integer',
        'yili' => 'integer',
        'yurgani' => 'integer',
        'narxi' => 'decimal:2',
        'cancelled_from_admin' => 'boolean',
        'cancelled_from_user' => 'boolean',
    ];

    /**
     * Status enum qiymatlari
     *
     * @var array<string>
     */
    public const STATUS_ENDED = 'ended';
    public const STATUS_ACCEPTED_USER = 'accepted_user';
    public const STATUS_SENDED_TO_ADMIN = 'sended_to_admin';
    public const STATUS_ACCEPTED_ADMIN = 'accepted_admin';
    public const STATUS_COMPLATED = 'complated';

    /**
     * Barcha statuslar ro'yxati
     *
     * @return array<string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_ENDED,
            self::STATUS_ACCEPTED_USER,
            self::STATUS_SENDED_TO_ADMIN,
            self::STATUS_ACCEPTED_ADMIN,
            self::STATUS_COMPLATED,
        ];
    }

    /**
     * ElonUser bilan relationship
     *
     * @return BelongsTo
     */
    public function elonUser(): BelongsTo
    {
        return $this->belongsTo(ElonUser::class, 'elon_user_id');
    }

    /**
     * Images bilan relationship
     *
     * @return HasMany
     */
    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'elon_id');
    }

    /**
     * Status tekshirish metodlari
     */
    public function isEnded(): bool
    {
        return $this->status === self::STATUS_ENDED;
    }

    public function isAcceptedUser(): bool
    {
        return $this->status === self::STATUS_ACCEPTED_USER;
    }

    public function isSendedToAdmin(): bool
    {
        return $this->status === self::STATUS_SENDED_TO_ADMIN;
    }

    public function isAcceptedAdmin(): bool
    {
        return $this->status === self::STATUS_ACCEPTED_ADMIN;
    }

    public function isComplated(): bool
    {
        return $this->status === self::STATUS_COMPLATED;
    }
}
