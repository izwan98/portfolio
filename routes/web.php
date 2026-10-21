<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/me', function () {
    return view('portfolio.index', ['p' => config('portfolio')]);
})->name('portfolio');
