<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Provider\ProviderAuthController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\ProviderUserController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserRbacController;
use App\Http\Controllers\Provider\ProviderUserManagementController;
use App\Http\Controllers\Admin\StudentAssignmentController;
use App\Http\Controllers\Provider\ProviderStudentAssignmentController;
use App\Http\Controllers\Provider\StudentGameplayController;
use App\Http\Controllers\Provider\IcdLookupController;
use App\Http\Controllers\Provider\StudentDashboardController;
use App\Http\Controllers\Provider\ProviderAdminDashboardController;
use App\Http\Controllers\Admin\AdminDashboardController;


// -------- Admin Auth --------
Route::prefix('admin')->group(function () {
    Route::post('auth/login', [AdminAuthController::class, 'login']);

    Route::middleware('auth:admin_api')->group(function () {
        Route::post('auth/logout', [AdminAuthController::class, 'logout']);
        Route::post('auth/refresh', [AdminAuthController::class, 'refresh']);
        Route::get('auth/me', [AdminAuthController::class, 'me']);

        // Providers CRUD
        Route::apiResource('providers', ProviderController::class);

        // Provider users under a provider
        Route::get('providers/{provider}/users', [ProviderUserController::class, 'index']);
        Route::post('providers/{provider}/users', [ProviderUserController::class, 'store']);
        Route::get('providers/{provider}/users/{user}', [ProviderUserController::class, 'show']);
        Route::put('providers/{provider}/users/{user}', [ProviderUserController::class, 'update']);
        Route::delete('providers/{provider}/users/{user}', [ProviderUserController::class, 'destroy']);

        Route::prefix('dashboard')->group(function () {
            Route::get('summary', [AdminDashboardController::class, 'summary']);
            Route::get('growth', [AdminDashboardController::class, 'growth']);
            Route::get('activity', [AdminDashboardController::class, 'activity']);
            Route::get('providers', [AdminDashboardController::class, 'providers']);   // leaderboard/table
            Route::get('categories', [AdminDashboardController::class, 'categories']); // performance by category
            Route::get('mistakes', [AdminDashboardController::class, 'mistakes']);     // top wrong/missing codes
            Route::get('funnel', [AdminDashboardController::class, 'funnel']);         // platform funnel
        });
    });
});



Route::middleware('auth:admin_api')->prefix('admin')->group(function () {
    Route::apiResource('roles', RoleController::class);
    Route::get('permissions', [PermissionController::class, 'index']);
    Route::apiResource('admin-users', AdminUserController::class);
    Route::get('users/{user}/rbac', [UserRbacController::class, 'show']);
    Route::put('users/{user}/roles', [UserRbacController::class, 'syncRoles']);
    Route::post('users/{user}/roles', [UserRbacController::class, 'attachRoles']);
    Route::delete('users/{user}/roles/{role}', [UserRbacController::class, 'detachRole']);

    Route::put('students/{student}/category-settings', [StudentAssignmentController::class, 'upsertCategorySettings']);
    Route::post('students/{student}/generate-assignments', [StudentAssignmentController::class, 'generateAssignments']);
});

Route::prefix('provider')->group(function () {

    Route::post('auth/login', [ProviderAuthController::class, 'login']);


    Route::middleware('auth:provider_api')->group(function () {

        Route::post('auth/logout', [ProviderAuthController::class, 'logout']);
        Route::post('auth/refresh', [ProviderAuthController::class, 'refresh']);
        Route::get('auth/me', [ProviderAuthController::class, 'me']);

        // Provider admin / RBAC-based user management (scoped by provider_id)
        Route::get('users', [ProviderUserManagementController::class, 'index']);
        // ->middleware('permission:provider_users.view');

        Route::post('users', [ProviderUserManagementController::class, 'store']);
        // ->middleware('permission:provider_users.create');

        Route::get('users/{user}', [ProviderUserManagementController::class, 'show']);
        // ->middleware('permission:provider_users.view');

        Route::put('users/{user}', [ProviderUserManagementController::class, 'update']);
        // ->middleware('permission:provider_users.update');

        Route::delete('users/{user}', [ProviderUserManagementController::class, 'destroy']);
        // ->middleware('permission:provider_users.delete');

        Route::put('students/{student}/category-settings', [ProviderStudentAssignmentController::class, 'upsertCategorySettings']);
        // ->middleware('permission:provider_users.update');

        Route::post('students/{student}/generate-assignments', [ProviderStudentAssignmentController::class, 'generateAssignments']);
        // ->middleware('permission:provider_users.update');

        Route::get('my/categories', [StudentGameplayController::class, 'myCategories']);
        Route::get('my/assignments', [StudentGameplayController::class, 'myAssignments']);

        Route::get('assignments/{assignment}/question', [StudentGameplayController::class, 'question']);

        Route::post('assignments/{assignment}/submit', [StudentGameplayController::class, 'submit']);
            // ->middleware('throttle:10,1');

        Route::get('icd-lookup', [IcdLookupController::class, 'index'])
            ->middleware('throttle:60,1');

        Route::prefix('my/dashboard')->group(function () {
            Route::get('summary', [StudentDashboardController::class, 'summary']);
            Route::get('category-progress', [StudentDashboardController::class, 'categoryProgress']);
            Route::get('activity', [StudentDashboardController::class, 'activity']);
            Route::get('mistakes', [StudentDashboardController::class, 'mistakes']);
            Route::get('recent-attempts', [StudentDashboardController::class, 'recentAttempts']);
        });

        Route::prefix('admin/dashboard')->group(function () {
            Route::get('summary', [ProviderAdminDashboardController::class, 'summary']);
            Route::get('category-progress', [ProviderAdminDashboardController::class, 'categoryProgress']);
            Route::get('activity', [ProviderAdminDashboardController::class, 'activity']);
            Route::get('mistakes', [ProviderAdminDashboardController::class, 'mistakes']);
            Route::get('students', [ProviderAdminDashboardController::class, 'students']);
        });
    });
});
