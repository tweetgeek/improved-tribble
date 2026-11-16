<?php

namespace App\Services\Health;

enum ComponentState: string
{
    case Up = 'up';
    case Down = 'down';
}
