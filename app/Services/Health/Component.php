<?php

namespace App\Services\Health;

enum Component: string
{
    case App = 'app';
    case Database = 'database';
    case Queue = 'queue';
    case Cache = 'cache';
    case Worker = 'worker';
    case Mail = 'mail';
}
