<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ArchiveController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::any('/v1/archives', [ArchiveController::class, 'index']);
Route::any('/v1/archives/{id}', [ArchiveController::class, 'show']);
