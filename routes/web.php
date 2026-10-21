<?php

use App\Http\Controllers\ApiDetectiveController;
use App\Http\Controllers\MlbbDraftController;
use Illuminate\Support\Facades\Route;

// Landing page: a card for every project in this app.
Route::get('/', function () {
    return view('home', ['projects' => [
        ['route' => 'portfolio', 'title' => 'Portfolio', 'tag' => 'About me', 'accent' => '#7c6cff',
            'description' => 'Izwan Salim — Full Stack Developer. Experience, projects and the stack I work with.'],
        ['route' => 'api-detective.index', 'title' => 'API Detective', 'tag' => 'Developer tool', 'accent' => '#f5b544',
            'description' => 'Point it at an OpenAPI spec, a docs page or a live API. It finds the endpoints, explains them and lets you test them.'],
        ['route' => 'mlbb-draft.index', 'title' => 'MLBB Draft', 'tag' => 'Mobile Legends', 'accent' => '#3b82f6',
            'description' => 'Ranked and MPL-style draft board for captains: bans, per-lane pick suggestions and counters, updated daily.'],
        ['route' => 'triple-t', 'title' => 'Tung Tung Tung Sahur', 'tag' => 'Rhythm game', 'accent' => '#e0a96d',
            'description' => 'Hit the tungs on the beat and land every SAHUR to wake the whole kampung before imsak.'],
    ]]);
})->name('home');

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
