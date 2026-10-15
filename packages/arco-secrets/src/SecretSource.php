<?php declare(strict_types=1);

namespace Multiapi\Arco\Secrets;

enum SecretSource: string
{
    case Ssm = 'ssm';
    case SecretsManager = 'secretsmanager';
}
