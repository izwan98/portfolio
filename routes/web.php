<?php

use App\Http\Controllers\ApiDetectiveController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/me', function () {
    return view('portfolio.index', ['p' => config('portfolio')]);
})->name('portfolio');

Route::view('/triple-t', 'triple-t.index')->name('triple-t');

Route::controller(ApiDetectiveController::class)->prefix('api-detective')->name('api-detective.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/demo-spec', 'demo')->name('demo');
    Route::post('/discover', 'discover')->middleware('throttle:20,1')->name('discover');
    Route::post('/send', 'send')->middleware('throttle:60,1')->name('send');
});

