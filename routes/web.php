<?php

use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ShopController::class, 'shop']);
Route::get('/checkout', [ShopController::class, 'checkout']);
Route::get('/modules', [ShopController::class, 'modules']);
