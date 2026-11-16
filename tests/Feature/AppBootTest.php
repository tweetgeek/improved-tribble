<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('responds on an empty database with the service status and a correlation id', function () {
    $this->get('/')
        ->assertOk()
        ->assertExactJson(['service' => 'payment-gateway', 'status' => 'running'])
        ->assertHeader('X-Correlation-ID');
});

it('exposes the framework liveness endpoint', function () {
    $this->get('/up')->assertOk();
});

it('does not log errors while serving the boot endpoints', function () {
    Log::spy();

    $this->get('/')->assertOk();
    $this->get('/up')->assertOk();

    Log::shouldNotHaveReceived('error');
});
