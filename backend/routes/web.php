<?php

use Illuminate\Support\Facades\Route;

/*
| In production the React app is built (frontend/dist) and copied into this
| folder's public/ directory, so the app and the API share one address.
| Apache serves the real files (assets/*.js, favicon.svg) directly; every other
| non-API path is an app route (/customers/12, /loans, ...) and gets the app's
| index.html, so refreshing or opening a deep link works.
*/
Route::get('/{path?}', function () {
    $index = public_path('index.html');

    if (! is_file($index)) {
        return response('The web app has not been built into backend/public yet. See docs/DEPLOY-HOSTINGER.md.', 503)
            ->header('Content-Type', 'text/plain');
    }

    return response()->file($index, [
        'Content-Type' => 'text/html; charset=UTF-8',
        // Always revalidate the page so a new release is picked up immediately;
        // the hashed assets it references are cached by the browser.
        'Cache-Control' => 'no-cache, private',
    ]);
})->where('path', '^(?!api(/|$)|sanctum(/|$)|up$).*$');
