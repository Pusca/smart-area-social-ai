<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ContentItemController;
use App\Http\Controllers\ContentFeedbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PlanWizardController;
use App\Http\Controllers\AiGenerateController;
use App\Http\Controllers\CanvaIntegrationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\TenantProfileController;
use App\Http\Controllers\AdminWorkspaceController;
use App\Http\Controllers\AgencyBrandSwitchController;
use App\Http\Controllers\AlterEgoController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\LinkedInSocialController;
use App\Http\Controllers\TikTokSocialController;
use App\Http\Controllers\GoogleBusinessSocialController;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'platformAdmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminWorkspaceController::class, 'index'])->name('dashboard');
    Route::get('/ai/metrics', [AdminWorkspaceController::class, 'generationMetrics'])->name('ai.metrics');
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

// ── Onboarding (prima del middleware onboarding per evitare loop) ─────────────
Route::middleware(['auth', 'verified', 'resolveActiveTenant', 'hasTenant'])->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');
    Route::post('/onboarding/brand', [OnboardingController::class, 'saveBrand'])->name('onboarding.brand');
    Route::post('/onboarding/audience', [OnboardingController::class, 'saveAudience'])->name('onboarding.audience');
    Route::post('/onboarding/skip-social', [OnboardingController::class, 'skipSocial'])->name('onboarding.skip-social');
    Route::post('/onboarding/assets', [OnboardingController::class, 'uploadAssets'])->name('onboarding.assets');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
});

// ── App principale (protetta da onboarding) ───────────────────────────────────
Route::middleware(['auth', 'verified', 'resolveActiveTenant', 'hasTenant', 'onboardingComplete'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
    Route::post('/calendar/content/{contentItem}/approve', [CalendarController::class, 'approve'])->name('calendar.content.approve');

    // ── Feature multi-brand / agenzia ────────────────────────────────────────
    // Lista brand accessibili + switch sessione. Non richiede hasTenant
    // perché serve anche per il primo switch.
    Route::prefix('agency')->name('agency.')->group(function () {
        Route::get('/brands', [AgencyBrandSwitchController::class, 'index'])->name('brands.index');
        Route::post('/brands/{tenant}/switch', [AgencyBrandSwitchController::class, 'switch'])->name('brands.switch');
        Route::post('/brands/reset', [AgencyBrandSwitchController::class, 'reset'])->name('brands.reset');
    });

    // ── Onboarding da URL: scraping brand ───────────────────────────────────
    // Endpoint usato dal wizard di onboarding per pre-compilare il profilo
    // estraendo dati dal sito web del cliente senza API esterne.
    Route::post('/profile/brand/scrape-url', [TenantProfileController::class, 'scrapeUrl'])->name('profile.brand.scrape');

    // Profilo attivita e asset del tenant.
    Route::get('/profile/brand', [TenantProfileController::class, 'show'])->name('profile.brand');
    Route::post('/profile/brand', [TenantProfileController::class, 'store'])->name('profile.brand.store');
    Route::post('/profile/brand/trends/refresh', [TenantProfileController::class, 'refreshTrendBrief'])->name('profile.brand.trends.refresh');
    Route::post('/profile/brand/variables', [TenantProfileController::class, 'storeVariable'])->name('profile.brand.variables.store');
    Route::post('/profile/brand/variables/persona-pack', [TenantProfileController::class, 'storeGuidedPersonaVariable'])->name('profile.brand.variables.persona.store');
    Route::post('/profile/brand/variables/{assetVariable}/assets', [TenantProfileController::class, 'storeVariableAssets'])->name('profile.brand.variables.assets.store');
    Route::delete('/profile/brand/variables/{assetVariable}', [TenantProfileController::class, 'destroyVariable'])->name('profile.brand.variables.destroy');
    Route::delete('/profile/brand/assets', [TenantProfileController::class, 'destroyAssets'])
        ->name('profile.brand.assets.destroy');
    Route::delete('/profile/brand/assets/{asset}', [TenantProfileController::class, 'destroyAsset'])
        ->name('profile.brand.asset.destroy');
    Route::patch('/profile/brand/assets/{asset}/description', [TenantProfileController::class, 'updateAssetDescription'])
        ->name('profile.brand.asset.description.update');

    // Piano editoriale separato dal Brand Center.
    Route::get('/wizard', [PlanWizardController::class, 'start'])->name('wizard.start');
    Route::post('/wizard', [PlanWizardController::class, 'store'])->name('wizard.store');
    Route::get('/wizard/done', [PlanWizardController::class, 'done'])->name('wizard.done');
    Route::get('/wizard/progress', [PlanWizardController::class, 'progress'])->name('wizard.progress');
    Route::get('/wizard/progress/{contentPlan}', [PlanWizardController::class, 'progress'])->name('wizard.progress.plan');
    // SSE: sostituisce il polling JS. Connessione keep-alive che emette eventi
    // 'progress' e 'done' mentre la generazione AI procede in background.
    Route::get('/wizard/stream/{contentPlan}', [PlanWizardController::class, 'stream'])->name('wizard.stream');
    Route::get('/plans/{contentPlan}/generating', [PlanWizardController::class, 'generating'])->name('plans.generating');
    Route::post('/wizard/generate', [PlanWizardController::class, 'generate'])->name('wizard.generate');

    Route::post('/ai/content/{contentItem}/generate', [AiGenerateController::class, 'generateOne'])->name('ai.content.generate');
    Route::post('/ai/plan/{contentPlan}/generate', [AiGenerateController::class, 'generatePlan'])->name('ai.plan.generate');
    Route::post('/ai/content/{contentItem}/image', [AiGenerateController::class, 'generateImage'])->name('ai.content.generateImage');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/fine-tuning/start', [SettingsController::class, 'startFineTuning'])->name('settings.fine-tuning.start');
    Route::post('/settings/fine-tuning/sync', [SettingsController::class, 'syncFineTuning'])->name('settings.fine-tuning.sync');
    Route::get('/settings/social/meta/redirect', [SocialAccountController::class, 'redirectToMeta'])->name('settings.social.meta.redirect');
    Route::get('/settings/social/meta/callback', [SocialAccountController::class, 'handleMetaCallback'])->name('settings.social.meta.callback');
    Route::post('/settings/social/accounts/{socialAccount}/disconnect', [SocialAccountController::class, 'disconnect'])->name('settings.social.accounts.disconnect');

    // ── LinkedIn OAuth ────────────────────────────────────────────────────────
    Route::get('/settings/social/linkedin/redirect', [LinkedInSocialController::class, 'redirectToLinkedIn'])->name('settings.social.linkedin.redirect');
    Route::get('/settings/social/linkedin/callback', [LinkedInSocialController::class, 'handleLinkedInCallback'])->name('settings.social.linkedin.callback');

    // ── TikTok OAuth ──────────────────────────────────────────────────────────
    Route::get('/settings/social/tiktok/redirect', [TikTokSocialController::class, 'redirectToTikTok'])->name('settings.social.tiktok.redirect');
    Route::get('/settings/social/tiktok/callback', [TikTokSocialController::class, 'handleTikTokCallback'])->name('settings.social.tiktok.callback');

    // ── Google Business OAuth ─────────────────────────────────────────────────
    Route::get('/settings/social/google/redirect', [GoogleBusinessSocialController::class, 'redirectToGoogle'])->name('settings.social.google.redirect');
    Route::get('/settings/social/google/callback', [GoogleBusinessSocialController::class, 'handleGoogleCallback'])->name('settings.social.google.callback');
    Route::get('/settings/integrations/canva/redirect', [CanvaIntegrationController::class, 'redirect'])->name('settings.integrations.canva.redirect');
    Route::get('/settings/integrations/canva/callback', [CanvaIntegrationController::class, 'callback'])->name('settings.integrations.canva.callback');
    Route::post('/settings/integrations/canva/disconnect', [CanvaIntegrationController::class, 'disconnect'])->name('settings.integrations.canva.disconnect');
    Route::post('/settings/integrations/canva/templates/refresh', [CanvaIntegrationController::class, 'refreshTemplates'])->name('settings.integrations.canva.templates.refresh');
    Route::post('/settings/integrations/canva/templates/map', [CanvaIntegrationController::class, 'updateTemplateMapping'])->name('settings.integrations.canva.templates.map');

    Route::prefix('posts')->name('posts.')->group(function () {
        Route::get('/', [ContentItemController::class, 'index'])->name('index');
        Route::get('/generation-feed', [ContentItemController::class, 'activeGenerations'])->name('generation.feed');

        Route::get('/create', [ContentItemController::class, 'create'])->name('create');
        Route::redirect('/reels/create', '/posts/create?preset=reel');
        Route::post('/', [ContentItemController::class, 'store'])->name('store');

        Route::get('/{contentItem}/generating', [ContentItemController::class, 'generating'])->name('generating');
        Route::get('/{contentItem}/generation-status', [ContentItemController::class, 'generationStatus'])->name('generation.status');
        Route::get('/{contentItem}/edit', [ContentItemController::class, 'edit'])->name('edit');
        Route::put('/{contentItem}', [ContentItemController::class, 'update'])->name('update');
        Route::post('/{contentItem}/feedback', [ContentFeedbackController::class, 'store'])->name('feedback.store');
        Route::delete('/{contentItem}', [ContentItemController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('content-items')->name('content-items.')->group(function () {
        Route::redirect('/', '/posts');
        Route::get('/{contentItem}', [ContentItemController::class, 'show'])->name('show');
        Route::post('/{contentItem}/canva/send', [CanvaIntegrationController::class, 'sendContentItem'])->name('canva.send');
    });

    Route::prefix('canva')->name('canva.')->group(function () {
        Route::get('/designs/{canvaDesign}/handoff', [CanvaIntegrationController::class, 'showHandoff'])->name('designs.handoff');
        Route::post('/designs/{canvaDesign}/link', [CanvaIntegrationController::class, 'linkManualDesign'])->name('designs.link');
        Route::post('/designs/{canvaDesign}/export', [CanvaIntegrationController::class, 'export'])->name('designs.export');
        Route::post('/exports/{canvaExportJob}/refresh', [CanvaIntegrationController::class, 'refreshExportStatus'])->name('exports.refresh');
    });

    // ── Alter Ego Digitale ────────────────────────────────────────────────────
    Route::prefix('alter-ego')->name('alter-ego.')->group(function (): void {
        Route::get('/', [AlterEgoController::class, 'index'])->name('index');
        Route::get('/create', [AlterEgoController::class, 'create'])->name('create');
        Route::post('/wizard/step', [AlterEgoController::class, 'storeStep'])->name('wizard.step');
        Route::post('/extract-voice', [AlterEgoController::class, 'extractVoice'])->name('extract-voice');
        Route::post('/marketplace/import', [AlterEgoController::class, 'importTemplate'])->name('marketplace.import');
        Route::post('/', [AlterEgoController::class, 'store'])->name('store');
        Route::get('/{alterEgo}/edit', [AlterEgoController::class, 'edit'])->name('edit');
        Route::get('/{alterEgo}/analytics', [AlterEgoController::class, 'analytics'])->name('analytics');
        Route::put('/{alterEgo}', [AlterEgoController::class, 'update'])->name('update');
        Route::delete('/{alterEgo}', [AlterEgoController::class, 'destroy'])->name('destroy');
        Route::post('/{alterEgo}/default', [AlterEgoController::class, 'setDefault'])->name('set-default');
        Route::post('/{alterEgo}/media', [AlterEgoController::class, 'uploadMedia'])->name('media.upload');
        Route::delete('/{alterEgo}/media', [AlterEgoController::class, 'destroyMedia'])->name('media.destroy');
    });

    Route::get('/push/public-key', [PushController::class, 'publicKey'])->name('push.publicKey');
    Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/test', [PushController::class, 'test'])->name('push.test');
});

require __DIR__ . '/auth.php';

