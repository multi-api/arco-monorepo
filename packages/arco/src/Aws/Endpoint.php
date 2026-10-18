<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

/**
 * The AWS endpoint override (AWS_ENDPOINT_URL), which the AWS SDK also honours: it points Arco at a
 * local emulator such as Floci. A value that is not http(s)://host[:port] is treated as unset.
 */
final class Endpoint
{
    public static function override(): ?string
    {
        $value = getenv('AWS_ENDPOINT_URL');
        if (! is_string($value) || ! preg_match('~^(https?)://([A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(:\d{1,5})?/*$~', $value)) {
            return null;
        }
        return rtrim($value, '/');
    }

    public static function scheme(): string
    {
        $override = self::override();
        return $override !== null && str_starts_with($override, 'http://') ? 'http' : 'https';
    }

    /** The bucket's base URL: path style behind an override, virtual-hosted style on AWS. */
    public static function bucketUrl(string $bucket, string $region): string
    {
        $override = self::override();
        return $override !== null ? "$override/$bucket" : "https://$bucket.s3.$region.amazonaws.com";
    }
}
