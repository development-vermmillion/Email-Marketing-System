<?php

use App\Http\Controllers\EmailValidationController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AudienceController;
use App\Http\Controllers\AudienceGroupController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SmtpSettingController;
use App\Http\Controllers\SuppressionController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

/* ------------------------------------------------------------------ Public */

// Unsubscribe (token = 64 random chars, the only credential). POST is exempt from CSRF: see bootstrap/app.php.
Route::middleware('throttle:unsubscribe')->prefix('unsubscribe/{token}')->group(function () {
    Route::get('/', [UnsubscribeController::class, 'show'])->name('unsubscribe.show');
    Route::post('/', [UnsubscribeController::class, 'store'])->name('unsubscribe.store');
    Route::post('/resubscribe', [UnsubscribeController::class, 'resubscribe'])->name('unsubscribe.resubscribe');
});

// Open pixel + click redirect.
Route::middleware('throttle:tracking')->prefix('t')->group(function () {
    Route::get('/o/{token}.gif', [TrackingController::class, 'open'])->where('token', '[A-Za-z0-9]{64}')->name('track.open');
    Route::get('/c/{token}', [TrackingController::class, 'click'])->where('token', '[A-Za-z0-9]{64}')->name('track.click');
});

// Provider webhooks: authenticated by signature, not by session/CSRF.
Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
    ->where('provider', '[a-z]+')->middleware('throttle:webhooks')->name('webhooks.handle');

/* -------------------------------------------------------------------- Auth */

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

/* ------------------------------------------------------------ Admin panel */

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Campaigns
    Route::get('campaigns/estimate', [CampaignController::class, 'estimate'])->name('campaigns.estimate');
    Route::resource('campaigns', CampaignController::class)->except(['show']);
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->withTrashed()->name('campaigns.show');
    Route::post('campaigns/{campaign}/restore', [CampaignController::class, 'restore'])->withTrashed()->name('campaigns.restore');
    Route::get('campaigns/{campaign}/progress', [CampaignController::class, 'progress'])->name('campaigns.progress');
    Route::get('campaigns/{campaign}/preview', [CampaignController::class, 'preview'])->name('campaigns.preview');
    Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->name('campaigns.duplicate');
    Route::post('campaigns/{campaign}/test', [CampaignController::class, 'test'])->middleware('throttle:test-email')->name('campaigns.test');
    Route::middleware('throttle:sending-actions')->group(function () {
        Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send'])->name('campaigns.send');
        Route::post('campaigns/{campaign}/schedule', [CampaignController::class, 'schedule'])->name('campaigns.schedule');
        Route::post('campaigns/{campaign}/unschedule', [CampaignController::class, 'unschedule'])->name('campaigns.unschedule');
        Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
        Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
        Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
    });

    // Audiences + CSV import
    Route::post('audiences/bulk-delete', [AudienceController::class, 'bulkDestroy'])->name('audiences.bulk-delete');
    Route::post('audiences/bulk-groups', [AudienceController::class, 'bulkGroups'])->name('audiences.bulk-groups');
    Route::resource('audiences', AudienceController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::prefix('imports')->name('imports.')->group(function () {
        Route::get('/', [ImportController::class, 'create'])->name('create');
        Route::post('/', [ImportController::class, 'store'])->name('store');
        Route::get('sample', [ImportController::class, 'sample'])->name('sample');
        Route::get('{import}', [ImportController::class, 'show'])->name('show');
        Route::get('{import}/progress', [ImportController::class, 'progress'])->name('progress');
        Route::get('{import}/download', [ImportController::class, 'download'])->name('download');
    });

    // Email validation
    Route::prefix('email-validation')->name('validation.')->group(function () {
        Route::get('/', [EmailValidationController::class, 'index'])->name('index');
        Route::post('/', [EmailValidationController::class, 'store'])->middleware('throttle:sending-actions')->name('store');
        Route::post('check', [EmailValidationController::class, 'check'])->middleware('throttle:60,1')->name('check');
        Route::post('deactivate-invalid', [EmailValidationController::class, 'deactivate'])->name('deactivate');
        Route::get('export', [EmailValidationController::class, 'export'])->name('export');
        Route::get('{run}', [EmailValidationController::class, 'show'])->name('show')->whereNumber('run');
        Route::get('{run}/progress', [EmailValidationController::class, 'progress'])->name('progress')->whereNumber('run');
        Route::post('{run}/cancel', [EmailValidationController::class, 'cancel'])->name('cancel')->whereNumber('run');
    });

    Route::resource('groups', AudienceGroupController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['groups' => 'group']);

    // Email templates
    Route::post('templates/preview', [TemplateController::class, 'preview'])->name('templates.preview');
    Route::resource('templates', TemplateController::class)->except(['show']);
    Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('templates.duplicate');
    Route::post('templates/{template}/test', [TemplateController::class, 'test'])->middleware('throttle:test-email')->name('templates.test');

    // Reports
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

    // Suppression list
    Route::get('suppression', [SuppressionController::class, 'index'])->name('suppression.index');
    Route::get('suppression/export', [SuppressionController::class, 'export'])->name('suppression.export');
    Route::post('suppression', [SuppressionController::class, 'store'])->name('suppression.store');
    Route::delete('suppression/{entry}', [SuppressionController::class, 'destroy'])->name('suppression.destroy');

    // SMTP settings
    Route::get('smtp', [SmtpSettingController::class, 'index'])->name('smtp.index');
    Route::post('smtp', [SmtpSettingController::class, 'store'])->name('smtp.store');
    Route::put('smtp/{smtp}', [SmtpSettingController::class, 'update'])->name('smtp.update');
    Route::delete('smtp/{smtp}', [SmtpSettingController::class, 'destroy'])->name('smtp.destroy');
    Route::post('smtp/{smtp}/toggle', [SmtpSettingController::class, 'toggle'])->name('smtp.toggle');
    Route::post('smtp/{smtp}/test-connection', [SmtpSettingController::class, 'testConnection'])->middleware('throttle:test-email')->name('smtp.test-connection');
    Route::post('smtp/{smtp}/test-email', [SmtpSettingController::class, 'testEmail'])->middleware('throttle:test-email')->name('smtp.test-email');

    // Activity log + system settings
    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
    Route::get('settings', [SystemSettingController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SystemSettingController::class, 'update'])->name('settings.update');
});
