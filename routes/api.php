<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BenefitTransferController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GatewayController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\SuperuserController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::get('/shared-links/token/{token}', [ReferralController::class, 'showByToken']);
Route::post('/webhooks/frasoft', [SuperuserController::class, 'frasoftWebhook']);
Route::post('/webhooks/finopal/transaction', [\App\Http\Controllers\Api\FinopalWebhookController::class, 'transaction']);

Route::middleware(['auth.api'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/switch-role', [AuthController::class, 'switchRole']);

    Route::middleware(['active.role'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'show']);
        Route::get('/organization/tree', [OrganizationController::class, 'tree']);
        Route::get('/organization/team', [OrganizationController::class, 'team']);
        Route::get('/representatives', [OrganizationController::class, 'representatives']);
        Route::get('/users/directory', [OrganizationController::class, 'directory']);
        Route::get('/geo/locations', [GeoController::class, 'locations']);

        Route::get('/wallets', [WalletController::class, 'show']);
        Route::get('/wallets/aggregate', [WalletController::class, 'aggregate']);
        Route::get('/wallet-transactions', [WalletController::class, 'transactions']);

        Route::get('/gateways', [GatewayController::class, 'index']);
        Route::get('/gateway-sales', [GatewayController::class, 'sales']);
        Route::post('/gateway-sales', [GatewayController::class, 'store']);
        Route::get('/gateway-sales/{sale}', [GatewayController::class, 'show']);
        Route::post('/gateway-sales/{sale}/inspect', [GatewayController::class, 'inspect']);
        Route::post('/gateway-sales/{sale}/parties', [GatewayController::class, 'updateParties']);
        Route::get('/commissions', [GatewayController::class, 'commissions']);

        Route::get('/referrals/codes', [ReferralController::class, 'codes']);
        Route::get('/referrals', [ReferralController::class, 'referrals']);
        Route::get('/shared-links', [ReferralController::class, 'sharedLinks']);
        Route::get('/shared-links/partners', [ReferralController::class, 'partners']);
        Route::post('/shared-links', [ReferralController::class, 'createSharedLink']);
        Route::post('/shared-links/{sharedLink}/approve', [ReferralController::class, 'approveSharedLink']);

        Route::get('/withdrawals', [WithdrawalController::class, 'index']);
        Route::post('/withdrawals', [WithdrawalController::class, 'store']);
        Route::post('/withdrawals/{withdrawal}/decide', [WithdrawalController::class, 'decide']);
        Route::post('/withdrawals/{withdrawal}/cancel', [WithdrawalController::class, 'cancel']);

        Route::get('/promotions', [PromotionController::class, 'index']);
        Route::get('/promotions/eligibility', [PromotionController::class, 'eligibility']);
        Route::get('/promotions/{promotion}', [PromotionController::class, 'show']);
        Route::post('/promotions', [PromotionController::class, 'store']);
        Route::post('/promotions/{promotion}/decide', [PromotionController::class, 'decide']);

        Route::get('/courses', [TrainingController::class, 'index']);
        Route::get('/courses/progress', [TrainingController::class, 'progress']);
        Route::get('/courses/team-progress', [TrainingController::class, 'teamProgress']);
        Route::post('/courses/{course}/levels/{level}/submit', [TrainingController::class, 'submit']);

        Route::get('/chat/directory', [ChatController::class, 'directory']);
        Route::get('/conversations/unread-count', [ChatController::class, 'unread']);
        Route::get('/conversations', [ChatController::class, 'index']);
        Route::post('/conversations', [ChatController::class, 'store']);
        Route::get('/conversations/{conversation}/messages', [ChatController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [ChatController::class, 'send']);
        Route::post('/conversations/{conversation}/read', [ChatController::class, 'read']);
        Route::post('/conversations/{conversation}/typing', [ChatController::class, 'typing']);

        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);

        Route::get('/benefit-transfers', [BenefitTransferController::class, 'index']);
        Route::post('/benefit-transfers', [BenefitTransferController::class, 'store']);
        Route::get('/users/{user}/gateway-shares', [BenefitTransferController::class, 'shares']);
        Route::post('/users/{user}/block', [OrganizationController::class, 'block']);
        Route::post('/users/{user}/unblock', [OrganizationController::class, 'unblock']);
        Route::post('/organization/reassign-manager', [OrganizationController::class, 'reassignManager']);

        Route::middleware(['course.manager'])->prefix('manage')->group(function () {
            Route::get('/roles', [SuperuserController::class, 'organizationalRoles']);
            Route::match(['get', 'post'], '/courses', [SuperuserController::class, 'courses']);
            Route::post('/courses/bulk', [SuperuserController::class, 'bulkCourses']);
            Route::put('/courses/{course}', [SuperuserController::class, 'updateCourse']);
            Route::delete('/courses/{course}', [SuperuserController::class, 'destroyCourse']);
            Route::post('/course-levels/{level}/file', [SuperuserController::class, 'uploadLevelFile']);
        });
    });

    Route::middleware(['superuser'])->prefix('superuser')->group(function () {
        Route::get('/stats', [SuperuserController::class, 'stats']);
        Route::get('/reports', [SuperuserController::class, 'reports']);
        Route::get('/reports/export', [SuperuserController::class, 'exportReports']);
        Route::get('/users', [SuperuserController::class, 'users']);
        Route::post('/users', [SuperuserController::class, 'storeUser']);
        Route::post('/users/bulk', [SuperuserController::class, 'bulkUsers']);
        Route::match(['put', 'post'], '/users/{user}', [SuperuserController::class, 'updateUser']);
        Route::delete('/users/{user}', [SuperuserController::class, 'destroyUser']);
        Route::post('/users/{user}/roles', [SuperuserController::class, 'assignRole']);
        Route::get('/roles', [SuperuserController::class, 'roles']);
        Route::get('/permissions', [SuperuserController::class, 'permissions']);
        Route::post('/permissions/assign', [SuperuserController::class, 'assignPermission']);
        Route::get('/settings', [SuperuserController::class, 'settings']);
        Route::post('/settings', [SuperuserController::class, 'updateSetting']);
        Route::get('/commission-rules', [SuperuserController::class, 'commissionRules']);
        Route::post('/commission-rules/{rule}', [SuperuserController::class, 'updateRule']);
        Route::match(['get', 'post'], '/courses', [SuperuserController::class, 'courses']);
        Route::post('/courses/bulk', [SuperuserController::class, 'bulkCourses']);
        Route::put('/courses/{course}', [SuperuserController::class, 'updateCourse']);
        Route::delete('/courses/{course}', [SuperuserController::class, 'destroyCourse']);
        Route::get('/audits', [SuperuserController::class, 'audits']);
        Route::get('/audits/export', [SuperuserController::class, 'exportAudits']);
        Route::get('/audits/{audit}', [SuperuserController::class, 'showAudit']);
        Route::get('/frasoft/logs', [SuperuserController::class, 'frasoftLogs']);
        Route::post('/frasoft/sync', [SuperuserController::class, 'frasoftSync']);
    });
});
