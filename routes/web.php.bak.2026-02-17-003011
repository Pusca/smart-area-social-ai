<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ContentItemController;
use App\Http\Controllers\PlanWizardController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AiGenerateController;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'verified', 'hasTenant'])->group(function () {

    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');

    Route::get('/wizard', [PlanWizardController::class, 'start'])->name('wizard.start');
    Route::post('/wizard', [PlanWizardController::class, 'store'])->name('wizard.store');

    Route::get('/wizard/brand', [PlanWizardController::class, 'brand'])->name('wizard.brand');
    Route::post('/wizard/brand', [PlanWizardController::class, 'brandStore'])->name('wizard.brand.store');

    Route::get('/wizard/done', [PlanWizardController::class, 'done'])->name('wizard.done');
    Route::post('/wizard/generate', [PlanWizardController::class, 'generate'])->name('wizard.generate');

    Route::get('/ai', [AiController::class, 'index'])->name('ai');
    Route::post('/ai/generate', [AiController::class, 'generate'])->name('ai.generate');

    Route::post('/ai/content/{contentItem}/generate', [AiGenerateController::class, 'generateOne'])->name('ai.content.generate');
    Route::post('/ai/plan/{contentPlan}/generate', [AiGenerateController::class, 'generatePlan'])->name('ai.plan.generate');
    Route::post('/ai/content/{contentItem}/image', [AiGenerateController::class, 'generateImage'])->name('ai.content.generateImage');

    Route::view('/notifications', 'notifications')->name('notifications');
    Route::view('/settings', 'settings')->name('settings');

    Route::prefix('posts')->name('posts.')->group(function () {
        Route::get('/', [ContentItemController::class, 'index'])->name('index');

        Route::get('/create', [ContentItemController::class, 'create'])->name('create');
        Route::post('/', [ContentItemController::class, 'store'])->name('store');

        Route::get('/{contentItem}/edit', [ContentItemController::class, 'edit'])->name('edit');
        Route::put('/{contentItem}', [ContentItemController::class, 'update'])->name('update');
        Route::delete('/{contentItem}', [ContentItemController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('content-items')->name('content-items.')->group(function () {
        Route::get('/', [ContentItemController::class, 'gallery'])->name('index');
        Route::get('/{contentItem}', [ContentItemController::class, 'show'])->name('show');
    });

    Route::get('/push/public-key', [PushController::class, 'publicKey'])->name('push.publicKey');
    Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/test', [PushController::class, 'test'])->name('push.test');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
