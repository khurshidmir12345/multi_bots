<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Image extends Model
{
    /**
     * Jadval nomi
     *
     * @var string
     */
    protected $table = 'images';

    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'elon_user_id',
        'elon_id',
        'image_url',
        'image_path',
        'file_id',
        'local_path', // Eski ma'lumotlar uchun qoldirilgan
        's3_path',
        's3_url',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'elon_user_id' => 'integer',
        'elon_id' => 'integer',
    ];

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
     * Elon bilan relationship
     *
     * @return BelongsTo
     */
    public function elon(): BelongsTo
    {
        return $this->belongsTo(Elon::class, 'elon_id');
    }

    /**
     * Rasm URL'ni olish (AWS S3 yoki boshqa manbalardan)
     *
     * @return string|null
     */
    public function getDisplayUrlAttribute(): ?string
    {
        // Avval s3_url'ni tekshirish
        if ($this->s3_url) {
            return $this->s3_url;
        }

        // Agar s3_path bo'lsa, URL yaratish
        if ($this->s3_path) {
            try {
                return Storage::disk('s3')->url($this->s3_path);
            } catch (\Exception $e) {
                // Xatolik bo'lsa, keyingi variantga o'tish
            }
        }

        // Keyin local_path'ni tekshirish
        if ($this->local_path && Storage::disk('public')->exists($this->local_path)) {
            return Storage::disk('public')->url($this->local_path);
        }

        // Oxirgi variant - image_url (Telegram URL)
        return $this->attributes['image_url'] ?? null;
    }
}
