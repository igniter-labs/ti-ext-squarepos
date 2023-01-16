<?php

use Illuminate\Support\Facades\Route;

Route::name('igniterlabs_squarepos_webhook')
    ->post('squarepos/webhook/{token}', \IgniterLabs\SquarePOS\Controllers\Webhook::class);
