<?php

use App\Http\Controllers\CspReportController;
use Illuminate\Support\Facades\Route;

//Route::get('/user', function (Request $request) {
//    return $request->user();
//})->middleware('auth:sanctum');

Route::post('/csp-report', [CspReportController::class, 'report'])
    ->name('csp-report')
    ->middleware('throttle:100,1');
