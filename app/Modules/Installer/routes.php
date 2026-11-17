<?php

use App\Modules\Installer\Http\Controllers\InstallerController;
use Illuminate\Support\Facades\Route;

/*
| Web installer. InstallationGate answers 404 for every one of these routes
| once the application is installed.
*/
Route::middleware('web')->prefix('install')->name('install.')->controller(InstallerController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/requirements', 'requirements')->name('requirements');
    Route::get('/database', 'database')->name('database');
    Route::get('/administrator', 'administrator')->name('administrator');
    Route::get('/mail', 'mail')->name('mail');

    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/requirements', 'confirmRequirements');
        Route::post('/database', 'saveDatabase');
        Route::post('/administrator', 'saveAdministrator');
        Route::post('/mail', 'saveMail');
    });
});
