<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/t/{token}/{any?}', function () {
    return view('customer');
})->where([
    'token' => '[A-Za-z0-9]{40}',
    'any' => '.*',
])->name('customer.store');

Route::get('/q/{accessToken}', function () {
    return view('customer');
})->where('accessToken', '[A-Za-z0-9]{40}')->name('customer.order');

Route::get('/merchant/{any?}', function () {
    return view('merchant');
})->where('any', '.*')->name('merchant.dashboard');
