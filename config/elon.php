<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin Chat IDs
    |--------------------------------------------------------------------------
    |
    | Bu yerda elon bot adminlarining chat_id'lari saqlanadi.
    | Bir nechta admin bo'lishi mumkin (vergul bilan ajratilgan).
    | Masalan: 5557554848,123456789,987654321
    |
    */

    'admin_chat_ids' => array_filter(
        array_map('trim', explode(',', env('ELON_ADMIN_CHAT_IDS', '5557554848')))
    ),

    /*
    |--------------------------------------------------------------------------
    | Sold Sticker ID
    |--------------------------------------------------------------------------
    |
    | Moshina sotilganda yuboriladigan sticker file_id.
    | Bu sticker elon xabari yoniga yuboriladi.
    | Agar bo'sh bo'lsa, sticker yuborilmaydi.
    |
    */

    'sold_sticker_id' => env('ELON_SOLD_STICKER_ID', null),

    'bot_username' => env('ELON_BOT_USERNAME', 'elon_saqla_bot'),
];
