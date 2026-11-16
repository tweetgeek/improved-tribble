<?php

namespace App\Services\Health\Checks;

use App\Services\Health\Component;
use App\Services\Health\ComponentStatus;

interface HealthCheck
{
    public function component(): Component;

    /**
     * @param  bool  $detailed  CLI mode: the reason may include the exception class and message, never secrets.
     */
    public function check(bool $detailed): ComponentStatus;
}
