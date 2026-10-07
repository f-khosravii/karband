<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Karband\DirectoryPanel\Http\Controllers\MembershipController;
use Karband\DirectoryPanel\Http\Controllers\OrganizationCreateController;
use Karband\DirectoryPanel\Http\Controllers\OrganizationDetailController;
use Karband\DirectoryPanel\Http\Controllers\OrganizationEditController;
use Karband\DirectoryPanel\Http\Controllers\OrganizationLifecycleController;
use Karband\DirectoryPanel\Http\Controllers\OrganizationListController;
use Karband\DirectoryPanel\Http\Controllers\UserCreateController;
use Karband\DirectoryPanel\Http\Controllers\UserDetailController;
use Karband\DirectoryPanel\Http\Controllers\UserEditController;
use Karband\DirectoryPanel\Http\Controllers\UserLifecycleController;
use Karband\DirectoryPanel\Http\Controllers\UserListController;

// Dashboard index redirect
Route::get('/', function () {
    $namePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
    return redirect()->route($namePrefix . 'organizations.index');
})->name('index');

// Organizations
Route::get('/organizations', OrganizationListController::class)->name('organizations.index');
Route::get('/organizations/create', [OrganizationCreateController::class, 'create'])->name('organizations.create');
Route::post('/organizations', [OrganizationCreateController::class, 'store'])->name('organizations.store');
Route::get('/organizations/{id}', OrganizationDetailController::class)->name('organizations.show');
Route::get('/organizations/{id}/edit', [OrganizationEditController::class, 'edit'])->name('organizations.edit');
Route::put('/organizations/{id}', [OrganizationEditController::class, 'update'])->name('organizations.update');
Route::post('/organizations/{id}/archive', [OrganizationLifecycleController::class, 'archive'])->name('organizations.archive');
Route::post('/organizations/{id}/restore', [OrganizationLifecycleController::class, 'restore'])->name('organizations.restore');

// Users
Route::get('/users', UserListController::class)->name('users.index');
Route::get('/users/create', [UserCreateController::class, 'create'])->name('users.create');
Route::post('/users', [UserCreateController::class, 'store'])->name('users.store');
Route::get('/users/{id}', UserDetailController::class)->name('users.show');
Route::get('/users/{id}/edit', [UserEditController::class, 'edit'])->name('users.edit');
Route::put('/users/{id}', [UserEditController::class, 'update'])->name('users.update');
Route::post('/users/{id}/archive', [UserLifecycleController::class, 'archive'])->name('users.archive');
Route::post('/users/{id}/restore', [UserLifecycleController::class, 'restore'])->name('users.restore');

// Memberships
Route::post('/memberships', [MembershipController::class, 'store'])->name('memberships.store');
Route::post('/memberships/end', [MembershipController::class, 'end'])->name('memberships.end');
Route::post('/memberships/restore', [MembershipController::class, 'restore'])->name('memberships.restore');
