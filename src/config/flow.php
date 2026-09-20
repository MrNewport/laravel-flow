<?php

return [

    /*
    | The related model's primary-key type: int, uuid, or ulid.
    | Set before the initial migration. Changing this setting does not alter
    | existing tables; existing installations require a reviewed migration.
    */
    'morph_key_type' => 'int',

    /*
    |--------------------------------------------------------------------------
    | Default Eloquent User Model
    |--------------------------------------------------------------------------
    */
    'user_model' => \App\Models\User::class,

    /*
    |--------------------------------------------------------------------------
    | Rejection Actions
    |--------------------------------------------------------------------------
    | If you want certain strings to always be considered "reject" or "cancel"
    */
    'rejection_actions' => [
        'reject',
        'cancel'
    ],
];
