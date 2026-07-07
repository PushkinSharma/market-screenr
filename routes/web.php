<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'screener.dashboard')->name('screener');
Route::livewire('/preset', 'screener.preset-editor')->name('preset');
Route::livewire('/company/{company}', 'company.detail')->name('company');
