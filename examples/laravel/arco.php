<?php

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\Environment;

// The real-AWS end-to-end run (composer test:e2e-aws) deploys under its own app name per run.
$app = getenv('ARCO_E2E_APP') ?: 'arco-example';
$e2eAccount = getenv('ARCO_E2E_ACCOUNT') ?: null;

return App::make($app)
    ->region('us-east-1')
    // Local simulation in Docker: no AWS calls, so no secret references.
    ->environment('ci', fn (Environment $env) => $env
        ->variables([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('a', 32)),
        ]))
    // Local end-to-end run against the Floci emulator (bin/test-docker e2e).
    ->environment('floci', fn (Environment $env) => $env
        ->console()
        ->scheduler(1)
        ->queue('default')
        ->storage('files')
        ->cache()
        ->table('state', ttl: 'expires_at')
        ->domain(['app.arco-e2e.test'])
        ->variables([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('a', 32)),
        ]))
    // Real AWS, Lambda URL only: the manual real-AWS end-to-end run (composer test:e2e-aws,
    // docs/maintainers/e2e.md), which creates the APP_KEY parameter itself. With ARCO_E2E_ACCOUNT set,
    // every arco command also refuses credentials of any other account.
    ->environment('e2e', function (Environment $env) use ($app, $e2eAccount) {
        $env->console()
            ->scheduler(1)
            ->queue('default')
            ->storage('files')
            ->cache()
            ->table('state', ttl: 'expires_at')
            ->maxInstances(2)
            ->variables([
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_KEY' => "arco-ssm:/$app/e2e/app-key",
            ]);
        if ($e2eAccount !== null) {
            $env->account($e2eAccount);
        }
        return $env;
    });
