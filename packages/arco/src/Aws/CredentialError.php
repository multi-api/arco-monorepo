<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Throwable;

/** Recognises failures caused by missing or expired AWS credentials, anywhere in the exception chain. */
final class CredentialError
{
    private const CODES = ['ExpiredToken', 'ExpiredTokenException', 'UnrecognizedClientException', 'InvalidClientTokenId'];

    public static function matches(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof CredentialsException) {
                return true;
            }
            if ($current instanceof AwsException && in_array($current->getAwsErrorCode(), self::CODES, true)) {
                return true;
            }
        }
        return false;
    }

    /** `aws sso login --profile <p>` when the error or the profile's configuration points to IAM Identity Center. */
    public static function loginCommand(Throwable $e, ?string $profile): ?string
    {
        if ($profile === null) {
            return null;
        }
        $hint = SsoHint::command($e, $profile);
        if ($hint !== null) {
            return $hint;
        }
        [$configPath, $credentialsPath] = ProfileLister::defaultPaths();
        foreach (ProfileLister::list($configPath, $credentialsPath) as $candidate) {
            if ($candidate->name === $profile && $candidate->type === 'sso') {
                return "aws sso login --profile $profile";
            }
        }
        return null;
    }
}
