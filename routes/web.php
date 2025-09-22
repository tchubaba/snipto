<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\SniptoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ---------------------------
// Static / reserved pages
// ---------------------------
Route::view('/faq', 'static.faq');
Route::view('/contact', 'static.contact');
Route::view('/terms', 'static.terms');

// ---------------------------
// Snipto API routes
// ---------------------------
// Create a new snippet (AJAX POST)
Route::prefix('api/snipto')->group(function () {
    Route::post('/', [ApiController::class, 'store']);
    Route::get('{slug}', [ApiController::class, 'show'])
        ->where('slug', '[A-Za-z0-9_-]+');
    Route::post('{slug}/viewed', [ApiController::class, 'markViewed'])
        ->where('slug', '[A-Za-z0-9_-]+');
});

// ---------------------------
// Catch-all slug route for viewing/creating snippet
// ---------------------------
Route::get('/{slug}', function () {
    return view('snipto');
})->where('slug', '[A-Za-z0-9_-]+');
