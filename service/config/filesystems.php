<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | The service stores no files (audio is returned inline, transcripts live in
    | the database), so the only disk is the private local one used by the framework.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [],

];
