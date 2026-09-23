<?php

use Goldnead\Affiliates\Http\Controllers\PartnerAreaController;
use Illuminate\Support\Facades\Route;

// Mounted by Statamic under /!/affiliates/, inside the `web` group.
if (! config('affiliates.routes.enabled', true)) {
    return;
}

$throttle = 'throttle:'.config('affiliates.routes.throttle', '20,1');

Route::get('go/{code}', [PartnerAreaController::class, 'go'])->name('affiliates.go');

Route::middleware($throttle)->group(function (): void {
    Route::post('apply', [PartnerAreaController::class, 'apply'])->name('affiliates.apply');
    Route::get('invite/{token}', [PartnerAreaController::class, 'invite'])->name('affiliates.invite');
    Route::post('details', [PartnerAreaController::class, 'details'])->name('affiliates.details');
});
