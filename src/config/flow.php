<?php

return [

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
