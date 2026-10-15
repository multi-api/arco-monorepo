<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Aws;

use Multiapi\Arco\Aws\ProfileLister;
use PHPUnit\Framework\TestCase;

final class ProfileListerTest extends TestCase
{
    public function test_lists_profiles_from_config_and_credentials(): void
    {
        $dir = sys_get_temp_dir() . '/arco-aws-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/config", <<<'INI'
            [default]
            region = us-east-1

            [profile acme-prod]
            sso_session = multiapi
            sso_account_id = 123456789012
            sso_role_name = AdministratorAccess

            [sso-session multiapi]
            sso_start_url = https://example.awsapps.com/start

            [profile deployer]
            role_arn = arn:aws:iam::111122223333:role/deploy
            source_profile = default
            INI);
        file_put_contents("$dir/credentials", <<<'INI'
            [default]
            aws_access_key_id = AKIAEXAMPLE
            aws_secret_access_key = secret

            [test]
            aws_access_key_id = AKIATEST
            aws_secret_access_key = secret
            INI);

        $profiles = ProfileLister::list("$dir/config", "$dir/credentials");

        self::assertSame(
            [
                ['acme-prod', 'sso', '123456789012'],
                ['default', 'keys', null],
                ['deployer', 'other', '111122223333'],
                ['test', 'keys', null],
            ],
            array_map(fn ($p) => [$p->name, $p->type, $p->accountId], $profiles),
        );
    }

    public function test_missing_files_give_an_empty_list(): void
    {
        self::assertSame([], ProfileLister::list('/nope/config', '/nope/credentials'));
    }
}
