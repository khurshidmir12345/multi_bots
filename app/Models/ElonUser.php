<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ElonUser extends Model
{
    use SoftDeletes;

    /**
     * Jadval nomi
     *
     * @var string
     */
    protected $table = 'elon_users';

    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'chat_id',
        'name',
        'user_name',
        'current_step',
        'last_message_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'chat_id' => 'integer',
        'last_message_id' => 'integer',
    ];

    /**
     * Elonlar bilan relationship
     *
     * @return HasMany
     */
    public function elonlar(): HasMany
    {
        return $this->hasMany(Elon::class, 'elon_user_id');
    }

    /**
     * Images bilan relationship
     *
     * @return HasMany
     */
    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'elon_user_id');
    }
}
