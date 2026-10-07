<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Karband\CatalogPanel\Http\Controllers\CatalogCreateController;
use Karband\CatalogPanel\Http\Controllers\CatalogDetailController;
use Karband\CatalogPanel\Http\Controllers\CatalogEditController;
use Karband\CatalogPanel\Http\Controllers\CatalogHistoryController;
use Karband\CatalogPanel\Http\Controllers\CatalogLifecycleController;
use Karband\CatalogPanel\Http\Controllers\CatalogListController;

Route::get('/', [CatalogListController::class, 'index'])->name('index');
Route::get('/create', [CatalogCreateController::class, 'create'])->name('create');
Route::post('/', [CatalogCreateController::class, 'store'])->name('store');

Route::get('/{code}', [CatalogDetailController::class, 'show'])->name('show');
Route::get('/{code}/edit', [CatalogEditController::class, 'edit'])->name('edit');
Route::match(['put', 'post', 'patch'], '/{code}/edit', [CatalogEditController::class, 'update']);
Route::match(['put', 'post', 'patch'], '/{code}', [CatalogEditController::class, 'update'])->name('update');

Route::get('/{code}/price', [CatalogEditController::class, 'editPrice'])->name('edit-price');
Route::match(['put', 'post', 'patch'], '/{code}/price', [CatalogEditController::class, 'updatePrice'])->name('update-price');

Route::post('/{code}/activate', [CatalogLifecycleController::class, 'activate'])->name('activate');
Route::post('/{code}/deactivate', [CatalogLifecycleController::class, 'deactivate'])->name('deactivate');

Route::get('/{code}/history', [CatalogHistoryController::class, 'index'])->name('history');
Route::get('/{code}/history/{revision}', [CatalogHistoryController::class, 'showRevision'])->name('history.revision');
