<?php

namespace App\Http\Controllers;

use App\Services\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $report = $checker->run(detailed: false);

        return response()
            ->json($report->toArray(), $report->isUp() ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
