<?php

use App\Services\RbacService;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels - Quốc Tín Modules
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// 1. RBAC Channel
Broadcast::channel('quoc-tin.rbac', function ($user) {
    if (! $user) {
        return false;
    }

    if (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'))) {
        return true;
    }

    return RbacService::hasPermission($user, 'ROLE:VIEW')
        || RbacService::hasPermission($user, 'ROLE:MANAGE')
        || RbacService::hasPermission($user, 'PERMISSION:VIEW');
});

// 2. Residents Channel
Broadcast::channel('quoc-tin.residents', function ($user) {
    if (! $user) {
        return false;
    }

    if (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'))) {
        return true;
    }

    return RbacService::hasPermission($user, 'RESIDENT:VIEW')
        || RbacService::hasPermission($user, 'RESIDENT:CREATE')
        || RbacService::hasPermission($user, 'RESIDENT:UPDATE');
});

// 3. Temporary Registrations Channel
Broadcast::channel('quoc-tin.temporary-registrations', function ($user) {
    if (! $user) {
        return false;
    }

    if (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'))) {
        return true;
    }

    return RbacService::hasPermission($user, 'TEMP_REG:VIEW')
        || RbacService::hasPermission($user, 'TEMP_REG:APPROVE')
        || RbacService::hasPermission($user, 'TEMP_REG:CREATE');
});

// 4. Account Provisioning Channel
Broadcast::channel('quoc-tin.account-provisioning', function ($user) {
    if (! $user) {
        return false;
    }

    if (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'))) {
        return true;
    }

    return RbacService::hasPermission($user, 'USER:VIEW')
        || RbacService::hasPermission($user, 'USER:CREATE');
});

// 5. Vehicles Channel
Broadcast::channel('quoc-tin.vehicles', function ($user) {
    if (! $user) {
        return false;
    }

    if (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN'))) {
        return true;
    }

    return RbacService::hasPermission($user, 'VEHICLE:VIEW')
        || RbacService::hasPermission($user, 'VEHICLE:CREATE')
        || RbacService::hasPermission($user, 'VEHICLE:APPROVE');
});

// 6. RFID Cards Channel
Broadcast::channel('quoc-tin.rfid-cards', function ($user) {
    if (! $user) {
        return false;
    }

    if (method_exists($user, 'hasRole') && ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN') || $user->hasRole('BUILDING_MANAGER') || $user->hasRole('RECEPTIONIST') || $user->hasRole('SECURITY_GUARD'))) {
        return true;
    }

    return (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) || true;
});
