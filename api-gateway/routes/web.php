<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;

Route::get('/', function () {
    return view('welcome');
});

// Serves OpenAPI specs
Route::get('/openapi/auth.yaml', function () {
    $path = '/openapi/auth.yaml';
    if (file_exists($path)) {
        return Response::make(file_get_contents($path), 200)
            ->header('Content-Type', 'application/x-yaml');
    }
    abort(404);
});

Route::get('/openapi/tasks.yaml', function () {
    $path = '/openapi/tasks.yaml';
    if (file_exists($path)) {
        return Response::make(file_get_contents($path), 200)
            ->header('Content-Type', 'application/x-yaml');
    }
    abort(404);
});
