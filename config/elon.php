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
];
