<?php

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ---------------------------
// Static / reserved pages
// ---------------------------
Route::view('/faq', 'faq');
Route::view('/contact', 'contact');
Route::view('/terms', 'terms');
Route::view('/safety', 'safety');
Route::view('/sniptoid', 'sniptoid')->name('sniptoid');

// ---------------------------
// Snipto API routes
// ---------------------------
// Create a new snippet (AJAX POST)
Route::prefix('api/snipto')->group(function () {
    Route::post('/', [ApiController::class, 'store'])
        ->middleware('progressive.throttle:store-snipto');
    Route::get('{slug}', [ApiController::class, 'show'])
        ->where('slug', '[A-Za-z0-9_-]+')
        ->middleware('progressive.throttle:show-snipto');
});

Route::post('/locale', function (Request $request) {
    $locale = $request->input('locale');

    // Get all supported locale keys from config
    $supportedLocales = array_keys(config('app.supported_locales'));

    if (in_array($locale, $supportedLocales)) {
        // Set locale cookie with 10-year expiration
        Cookie::queue('user_locale', $locale, 60 * 24 * 365 * 10);
    }

    // Redirect back to previous page
    return back();
})->name('locale.change');

// ---------------------------
// Catch-all slug route for viewing/creating snippet
// ---------------------------
Route::get('/{slug}', function () {
    return view('snipto');
})->where('slug', '[A-Za-z0-9_-]+');
