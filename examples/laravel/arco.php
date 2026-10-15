<?php

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\Environment;

return App::make('arco-example')
    ->region('us-east-1')
    // Local simulation in Docker: no AWS calls, so no secret references.
    ->environment('ci', fn (Environment $env) => $env
        ->variables([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('a', 32)),
        ]))
    // Real deploy from GitHub Actions (see .github/workflows/e2e.yml).
    ->environment('e2e', fn (Environment $env) => $env
        ->maxInstances(2)
        ->variables([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'arco-ssm:/arco-example/e2e/app-key',
        ]));
