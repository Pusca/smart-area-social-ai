<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ContentItemController;
use App\Http\Controllers\ContentFeedbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PlanWizardController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AiGenerateController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\TenantProfileController;
use App\Http\Controllers\AdminWorkspaceController;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'platformAdmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminWorkspaceController::class, 'index'])->name('dashboard');
    Route::put('/users/{user}/tenant', [AdminWorkspaceController::class, 'updateUserTenant'])->name('users.tenant.update');
    Route::put('/tenants/{tenant}', [AdminWorkspaceController::class, 'updateTenant'])->name('tenants.update');
    Route::post('/users/{user}/impersonate', [AdminWorkspaceController::class, 'impersonateUser'])->name('users.impersonate');
    Route::post('/tenants/{tenant}/impersonate', [AdminWorkspaceController::class, 'impersonateTenant'])->name('tenants.impersonate');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/admin/impersonation/stop', [AdminWorkspaceController::class, 'stopImpersonation'])->name('admin.impersonation.stop');
});

Route::middleware(['auth', 'verified', 'hasTenant'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
    Route::post('/calendar/content/{contentItem}/approve', [CalendarController::class, 'approve'])->name('calendar.content.approve');
    Route::redirect('/brand', '/profile/brand', 302)->name('brand.legacy');

    // Profilo attivita e asset del tenant.
    Route::get('/profile/brand', [TenantProfileController::class, 'show'])->name('profile.brand');
    Route::post('/profile/brand/quickstart', [TenantProfileController::class, 'storeQuickstart'])->name('profile.brand.quickstart.store');
    Route::post('/profile/brand/quickstart/save', [TenantProfileController::class, 'saveQuickstartDemo'])->name('profile.brand.quickstart.save');
    Route::post('/profile/brand/quickstart/regenerate', [TenantProfileController::class, 'regenerateQuickstartDemo'])->name('profile.brand.quickstart.regenerate');
    Route::delete('/profile/brand/quickstart', [TenantProfileController::class, 'destroyQuickstartDemo'])->name('profile.brand.quickstart.destroy');
    Route::post('/profile/brand', [TenantProfileController::class, 'store'])->name('profile.brand.store');
    Route::post('/profile/brand/variables', [TenantProfileController::class, 'storeVariable'])->name('profile.brand.variables.store');
    Route::post('/profile/brand/variables/persona-pack', [TenantProfileController::class, 'storeGuidedPersonaVariable'])->name('profile.brand.variables.persona.store');
    Route::delete('/profile/brand/variables/{assetVariable}', [TenantProfileController::class, 'destroyVariable'])->name('profile.brand.variables.destroy');
    Route::delete('/profile/brand/assets', [TenantProfileController::class, 'destroyAssets'])
        ->name('profile.brand.assets.destroy');
    Route::delete('/profile/brand/assets/{asset}', [TenantProfileController::class, 'destroyAsset'])
        ->name('profile.brand.asset.destroy');

    // Piano editoriale separato dal Brand Center.
    Route::get('/wizard', [PlanWizardController::class, 'start'])->name('wizard.start');
    Route::post('/wizard', [PlanWizardController::class, 'store'])->name('wizard.store');
    Route::get('/wizard/done', [PlanWizardController::class, 'done'])->name('wizard.done');
    Route::get('/wizard/progress', [PlanWizardController::class, 'progress'])->name('wizard.progress');
    Route::get('/wizard/progress/{contentPlan}', [PlanWizardController::class, 'progress'])->name('wizard.progress.plan');
    Route::get('/plans/{contentPlan}/generating', [PlanWizardController::class, 'generating'])->name('plans.generating');
    Route::post('/wizard/generate', [PlanWizardController::class, 'generate'])->name('wizard.generate');

    Route::get('/ai', [AiController::class, 'index'])->name('ai');
    Route::post('/ai/generate', [AiController::class, 'generate'])->name('ai.generate');

    Route::post('/ai/content/{contentItem}/generate', [AiGenerateController::class, 'generateOne'])->name('ai.content.generate');
    Route::post('/ai/plan/{contentPlan}/generate', [AiGenerateController::class, 'generatePlan'])->name('ai.plan.generate');
    Route::post('/ai/content/{contentItem}/image', [AiGenerateController::class, 'generateImage'])->name('ai.content.generateImage');

    Route::get('/setup', [SettingsController::class, 'index'])->name('setup.index');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/fine-tuning/start', [SettingsController::class, 'startFineTuning'])->name('settings.fine-tuning.start');
    Route::post('/settings/fine-tuning/sync', [SettingsController::class, 'syncFineTuning'])->name('settings.fine-tuning.sync');
    Route::get('/settings/social/meta/redirect', [SocialAccountController::class, 'redirectToMeta'])->name('settings.social.meta.redirect');
    Route::get('/settings/social/meta/callback', [SocialAccountController::class, 'handleMetaCallback'])->name('settings.social.meta.callback');
    Route::post('/settings/social/accounts/{socialAccount}/disconnect', [SocialAccountController::class, 'disconnect'])->name('settings.social.accounts.disconnect');

    Route::prefix('posts')->name('posts.')->group(function () {
        Route::get('/', [ContentItemController::class, 'index'])->name('index');

        Route::get('/create', [ContentItemController::class, 'create'])->name('create');
        Route::get('/reels/create', [ContentItemController::class, 'createReel'])->name('reels.create');
        Route::post('/', [ContentItemController::class, 'store'])->name('store');

        Route::get('/{contentItem}/generating', [ContentItemController::class, 'generating'])->name('generating');
        Route::get('/{contentItem}/generation-status', [ContentItemController::class, 'generationStatus'])->name('generation.status');
        Route::get('/{contentItem}/edit', [ContentItemController::class, 'edit'])->name('edit');
        Route::put('/{contentItem}', [ContentItemController::class, 'update'])->name('update');
        Route::post('/{contentItem}/feedback', [ContentFeedbackController::class, 'store'])->name('feedback.store');
        Route::delete('/{contentItem}', [ContentItemController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('content-items')->name('content-items.')->group(function () {
        Route::get('/', [ContentItemController::class, 'gallery'])->name('index');
        Route::get('/{contentItem}', [ContentItemController::class, 'show'])->name('show');
    });

    Route::get('/push/public-key', [PushController::class, 'publicKey'])->name('push.publicKey');
    Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/test', [PushController::class, 'test'])->name('push.test');
});

require __DIR__ . '/auth.php';

