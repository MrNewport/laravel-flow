<?php

return [

    /*
    | The related model's primary-key type: int, uuid, or ulid.
    | Set before the initial migration. Changing this setting does not alter
    | existing tables; existing installations require a reviewed migration.
    */
    'morph_key_type' => 'int',

    // Bound one action/assignment operation before writing or expanding its results.
    'max_transitions_per_action' => 1000,
    'max_assignments' => 250,

    // Full reviewed conversion is atomic. Larger installations can explicitly raise this
    // maintenance budget after checking preview counts, duration and database lock capacity.
    'max_morph_conversion_rows' => 100000,

    /*
    |--------------------------------------------------------------------------
    | Default Eloquent User Model
    |--------------------------------------------------------------------------
    */
    'user_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Rejection Actions
    |--------------------------------------------------------------------------
    | If you want certain strings to always be considered "reject" or "cancel"
    */
    'rejection_actions' => [
        'reject',
        'cancel',
    ],
];
