<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Str;
use Tests\Support\LockContender;

pest()->group('integration');

it('lets exactly one of 100 concurrent processes acquire the same lock', function () {
    $key = 'it:lock:'.Str::uuid();

    try {
        $results = Concurrency::driver('process')->run(
            array_fill(0, 100, LockContender::attempt($key, 120)),
        );
    } finally {
        Cache::store('redis')->lock($key)->forceRelease();
    }

    expect($results)->toHaveCount(100)
        ->and(array_count_values(array_map('intval', $results))[1] ?? 0)->toBe(1);
});
