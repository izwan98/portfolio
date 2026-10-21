<?php

use App\Http\Controllers\ApiDetectiveController;
use App\Http\Controllers\MlbbDraftController;
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

Route::controller(MlbbDraftController::class)->prefix('mlbb-draft')->name('mlbb-draft.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/data', 'data')->middleware('throttle:60,1')->name('data');
    Route::get('/matchups', 'matchups')->middleware('throttle:120,1')->name('matchups');

    Route::get('/templates', 'templates')->name('templates.index');
    Route::post('/templates', 'storeTemplate')->middleware('throttle:10,60')->name('templates.store');
    Route::put('/templates/{id}', 'updateTemplate')->middleware('throttle:30,60')->where('id', '[a-z0-9-]+')->name('templates.update');
    Route::delete('/templates/{id}', 'destroyTemplate')->middleware('throttle:30,60')->where('id', '[a-z0-9-]+')->name('templates.destroy');
});
