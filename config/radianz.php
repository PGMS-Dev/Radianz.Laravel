<?php

return [
    'client_id' => env('RADIANZ_CLIENT_ID'),
    'api_key' => env('RADIANZ_API_KEY'),
    'endpoint' => env('RADIANZ_ENDPOINT', 'https://radianz.io/api/v1/logs'),
    'release' => env('RADIANZ_RELEASE'),
    'include_messages' => env('RADIANZ_INCLUDE_MESSAGES', false),
];
