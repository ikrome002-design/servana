<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The /health liveness probe is registered in bootstrap/app.php (outside the
// web middleware group) so it has no session/database dependency.
