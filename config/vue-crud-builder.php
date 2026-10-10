<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Vue Pages Style
    |--------------------------------------------------------------------------
    |
    | Controls how make:crud generates Vue pages.
    |
    | 'per-resource' - Generate Index.vue + Show.vue + Form.vue per resource
    | 'shared'       - Use the published shared Crud/Index.vue, Crud/Show.vue and Crud/Form.vue
    | 'ask'          - Prompt the developer during make:crud (default)
    |
    */

    'pages' => env('CRUD_BUILDER_PAGES', 'ask'),

    /*
    |--------------------------------------------------------------------------
    | Class Namespaces
    |--------------------------------------------------------------------------
    |
    | Namespaces used for convention-based class resolution and code generation.
    | By convention, UserController resolves App\Forms\UserForm, App\Tables\UserTable,
    | App\Http\Requests\UserRequest, and App\Models\User automatically.
    |
    */

    'namespaces' => [
        'models' => 'App\\Models',
        'forms' => 'App\\Forms',
        'tables' => 'App\\Tables',
        'requests' => 'App\\Http\\Requests',
    ],

];
