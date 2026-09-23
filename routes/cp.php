<?php

use Goldnead\Affiliates\Http\Controllers\Cp\CommissionsController;
use Goldnead\Affiliates\Http\Controllers\Cp\JvController;
use Goldnead\Affiliates\Http\Controllers\Cp\PartnersController;
use Goldnead\Affiliates\Http\Controllers\Cp\PayoutsController;
use Goldnead\Affiliates\Http\Controllers\Cp\RatesController;
use Illuminate\Support\Facades\Route;

// The switch bites here as well as on the nav item: a hidden entry with a
// reachable URL is not a disabled screen.
if (! config('affiliates.cp.enabled', true)) {
    return;
}

// Every controller action authorises again with Gate::authorize(); the
// middleware is the first fence, not the only one.
Route::prefix('affiliates')->name('affiliates.')->group(function (): void {
    Route::middleware('can:view affiliates')->group(function (): void {
        Route::get('partners', [PartnersController::class, 'index'])->name('partners.index');
        Route::get('partners/create', [PartnersController::class, 'create'])->name('partners.create');
        Route::post('partners', [PartnersController::class, 'store'])->name('partners.store');
        Route::get('partners/{partner}', [PartnersController::class, 'show'])->whereNumber('partner')->name('partners.show');
        Route::get('partners/{partner}/edit', [PartnersController::class, 'edit'])->whereNumber('partner')->name('partners.edit');
        Route::patch('partners/{partner}', [PartnersController::class, 'update'])->whereNumber('partner')->name('partners.update');
        Route::post('partners/{partner}/status', [PartnersController::class, 'status'])->whereNumber('partner')->name('partners.status');
        Route::post('partners/{partner}/invite', [PartnersController::class, 'invite'])->whereNumber('partner')->name('partners.invite');

        Route::get('commissions', [CommissionsController::class, 'index'])->name('commissions.index');
        Route::post('commissions/{commission}/cancel', [CommissionsController::class, 'cancel'])->whereNumber('commission')->name('commissions.cancel');
    });

    Route::middleware('can:manage affiliate payouts')->group(function (): void {
        Route::get('payouts', [PayoutsController::class, 'index'])->name('payouts.index');
        Route::post('payouts/build', [PayoutsController::class, 'build'])->name('payouts.build');
        Route::get('payouts/csv', [PayoutsController::class, 'csv'])->name('payouts.csv');
        Route::post('payouts/{payout}/paid', [PayoutsController::class, 'paid'])->whereNumber('payout')->name('payouts.paid');
    });

    Route::middleware('can:manage affiliates')->group(function (): void {
        Route::get('rates', [RatesController::class, 'index'])->name('rates.index');
        Route::get('rates/create', [RatesController::class, 'create'])->name('rates.create');
        Route::post('rates', [RatesController::class, 'store'])->name('rates.store');
        Route::get('rates/{rate}/edit', [RatesController::class, 'edit'])->whereNumber('rate')->name('rates.edit');
        Route::patch('rates/{rate}', [RatesController::class, 'update'])->whereNumber('rate')->name('rates.update');
        Route::delete('rates/{rate}', [RatesController::class, 'destroy'])->whereNumber('rate')->name('rates.destroy');

        Route::get('jv', [JvController::class, 'index'])->name('jv.index');
        Route::get('jv/create', [JvController::class, 'create'])->name('jv.create');
        Route::post('jv', [JvController::class, 'store'])->name('jv.store');
        Route::get('jv/{contract}/edit', [JvController::class, 'edit'])->whereNumber('contract')->name('jv.edit');
        Route::patch('jv/{contract}', [JvController::class, 'update'])->whereNumber('contract')->name('jv.update');
        Route::delete('jv/{contract}', [JvController::class, 'destroy'])->whereNumber('contract')->name('jv.destroy');
    });
});
