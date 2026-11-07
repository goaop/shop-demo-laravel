<?php

use App\Providers\AppServiceProvider;
use Go\Laravel\GoAopBridge\GoAopServiceProvider;

// Package discovery would register GoAopServiceProvider as well; the demo lists it here (and turns its discovery off
// in composer.json) only to run the same application without it for the "baseline" benchmark: GOAOP_ENABLED=false in
// the real environment. bootstrap/providers.php is read before .env is loaded.
return array_values(array_filter([
    getenv('GOAOP_ENABLED') === 'false' ? null : GoAopServiceProvider::class,
    AppServiceProvider::class,
]));
