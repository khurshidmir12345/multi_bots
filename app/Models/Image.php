<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'local_path',
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
}
