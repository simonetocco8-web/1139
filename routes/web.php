<?php

use App\Http\Middleware\RequireVerifiedSession;
use App\Livewire\Organization\LodgeSelector;
use App\Livewire\Organization\Manager;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(RequireVerifiedSession::class)->group(function (): void {
    Route::livewire('/admin/organizzazione/{kind}', Manager::class)
        ->whereIn('kind', ['obediences', 'rites', 'structures', 'lodges'])
        ->middleware('can:manage-organization')->name('organization.index');
    Route::livewire('/loggia', LodgeSelector::class)->name('lodge.select');
});

require __DIR__.'/identity.php';
