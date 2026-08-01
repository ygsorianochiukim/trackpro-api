<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Only the configured frontend origin(s) may call the API from a browser.
    // FRONTEND_URL is a comma-separated list (e.g. "https://trackprogps.com,https://www.trackprogps.com").
    // Falls back to the local Next.js dev server when unset.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))
    ))),

    // Preview deployments (Vercel, Netlify, …) get a fresh random subdomain on
    // every build, so they can never be listed in FRONTEND_URL. Set
    // FRONTEND_URL_PATTERNS to a comma-separated list of delimited regexes
    // matched against the request Origin, e.g.
    //   FRONTEND_URL_PATTERNS="#^https://trackpro-[a-z0-9-]+\.vercel\.app$#"
    // Leave empty in a locked-down production setup — patterns are broader than
    // an explicit origin list, so only enable them if you want previews working.
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL_PATTERNS', ''))
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
