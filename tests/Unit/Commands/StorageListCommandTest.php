<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\ResourceGroupsTaggingAPI\Exception\ResourceGroupsTaggingAPIException;
use Aws\Result;
use Multiapi\Arco\Commands\StorageListCommand;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class StorageListCommandTest extends TestCase
{
    private ?ProjectFixture $project = null;
    private AwsMocks $mocks;

    protected function setUp(): void
    {
        $this->mocks = new AwsMocks();
    }

    protected function tearDown(): void
    {
        $this->project?->restore();
    }

    private function run_(string $storage): CommandTester
    {
        $this->project = new ProjectFixture(str_replace('->concurrency(48)', '->concurrency(48)' . $storage, ProjectFixture::standardConfig()));
        $tester = $this->project->tester(new StorageListCommand(fn (?string $profile, string $region) => $this->mocks->clients($region)));
        $tester->execute(['--env' => 'production', '--profile' => 'p'], ['interactive' => false]);
        return $tester;
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @param array<string, string> $tags */
    private static function bucket(string $name, array $tags): array
    {
        $list = [];
        foreach ($tags as $key => $value) {
            $list[] = ['Key' => $key, 'Value' => $value];
        }
        return ['ResourceARN' => 'arn:aws:s3:::' . $name, 'Tags' => $list];
    }

    public function test_lists_the_storages_in_arco_php_and_the_retained_buckets(): void
    {
        $tags = fn (string $resource) => ['arco:app' => 'shop', 'arco:environment' => 'production', 'arco:resource' => $resource];
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result([
                'ResourceTagMappingList' => [self::bucket('shop-production-uploads-111111111111', $tags('storage:uploads'))],
                'PaginationToken' => 'next',
            ]),
            new Result(['ResourceTagMappingList' => [
                self::bucket('shop-production-avatars-111111111111', $tags('storage:avatars')),
                self::bucket('shop-production-other-111111111111', $tags('queue:other')),
            ]]),
        );

        $tester = $this->run_("->storage('uploads', public: ['public/', 'avatars/'], expire: ['tmp/' => 7], cors: ['https://shop.test'])->storage('legacy', existing: 'old-bucket')");

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'GetResources', 'GetResources'], $this->mocks->calls);
        self::assertSame(['s3'], $this->mocks->params[1]['ResourceTypeFilters']);
        self::assertSame([
            ['Key' => 'arco:app', 'Values' => ['shop']],
            ['Key' => 'arco:environment', 'Values' => ['production']],
            ['Key' => 'arco:resource'],
        ], $this->mocks->params[1]['TagFilters']);
        self::assertSame('next', $this->mocks->params[2]['PaginationToken']);

        $display = self::display($tester);
        self::assertStringContainsString('Bucket shop-production-uploads-111111111111', $display);
        self::assertStringContainsString('URL https://shop-production-uploads-111111111111.s3.us-east-1.amazonaws.com', $display);
        self::assertStringContainsString('Managed created by Arco', $display);
        self::assertStringContainsString('Default disk yes', $display);
        self::assertStringContainsString('Public prefixes public/, avatars/ (anyone can read)', $display);
        self::assertStringContainsString('Expire tmp/ after 7 days', $display);
        self::assertStringContainsString('CORS origins https://shop.test', $display);
        self::assertStringContainsString('Bucket old-bucket', $display);
        self::assertStringContainsString('Managed existing (not managed by Arco)', $display);
        self::assertStringContainsString('Default disk no', $display);
        self::assertStringContainsString('Public prefixes none', $display);
        self::assertStringContainsString('Retained buckets (not in arco.php)', $display);
        self::assertStringContainsString('avatars shop-production-avatars-111111111111', $display);
        self::assertStringNotContainsString('shop-production-other', $display);
        self::assertSame(1, substr_count($display, 'shop-production-uploads-111111111111 '), 'a bucket in arco.php is not listed as retained');
    }

    public function test_one_day_expire_rule_is_singular(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), new Result(['ResourceTagMappingList' => []]));

        $tester = $this->run_("->storage('uploads', expire: ['tmp/' => 1, 'logs/' => 2])");

        self::assertStringContainsString('Expire tmp/ after 1 day, logs/ after 2 days', self::display($tester));
    }

    public function test_default_disk_overridden_by_variables_is_flagged(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), new Result(['ResourceTagMappingList' => []]));

        $tester = $this->run_("->storage('uploads')->variables(['FILESYSTEM_DISK' => 'local', 'AWS_BUCKET' => 'other-bucket'])");

        $display = self::display($tester);
        self::assertStringContainsString('Default disk yes, but variables() set FILESYSTEM_DISK, AWS_BUCKET, so the app uses disk local, bucket other-bucket', $display);
    }

    public function test_variables_equal_to_what_arco_sets_do_not_override(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), new Result(['ResourceTagMappingList' => []]));

        $tester = $this->run_("->storage('uploads')->variables(['FILESYSTEM_DISK' => 's3', 'AWS_BUCKET' => 'shop-production-uploads-111111111111'])");

        self::assertStringContainsString('Default disk yes Public', self::display($tester));
    }

    public function test_without_storage_says_so_and_unreadable_retained_buckets_are_unknown(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'AccessDeniedException', 400),
        );

        $tester = $this->run_('');

        $tester->assertCommandIsSuccessful();
        $display = self::display($tester);
        self::assertStringContainsString('No storage in arco.php for production', $display);
        self::assertStringContainsString('Retained buckets unknown: AccessDeniedException (needs tag:GetResources)', $display);
    }

    public function test_no_retained_buckets_is_said_so(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), new Result(['ResourceTagMappingList' => []]));

        $tester = $this->run_("->storage('uploads')");

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No retained buckets for production.', self::display($tester));
    }

    public function test_json_lists_storage_and_retained_buckets_with_schema_1(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result(['ResourceTagMappingList' => [self::bucket('shop-production-avatars-111111111111', ['arco:resource' => 'storage:avatars'])]]),
        );
        $this->project = new ProjectFixture(str_replace('->concurrency(48)', "->concurrency(48)->storage('uploads', expire: ['tmp/' => 7])", ProjectFixture::standardConfig()));
        $tester = $this->project->tester(new StorageListCommand(fn (?string $profile, string $region) => $this->mocks->clients($region)));

        $tester->execute(['--env' => 'production', '--profile' => 'p', '--json' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $json['schema']);
        self::assertSame('schema', array_key_first($json));
        self::assertSame('uploads', $json['storage'][0]['name']);
        self::assertSame('shop-production-uploads-111111111111', $json['storage'][0]['bucket']);
        self::assertTrue($json['storage'][0]['default']);
        self::assertSame(['tmp/' => 7], $json['storage'][0]['expire']);
        self::assertSame([['name' => 'avatars', 'bucket' => 'shop-production-avatars-111111111111']], $json['retained']);
    }

    public function test_json_marks_unreadable_retained_buckets_as_unknown(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'AccessDeniedException', 400),
        );
        $this->project = new ProjectFixture(ProjectFixture::standardConfig());
        $tester = $this->project->tester(new StorageListCommand(fn (?string $profile, string $region) => $this->mocks->clients($region)));

        $tester->execute(['--env' => 'production', '--profile' => 'p', '--json' => true], ['interactive' => false]);

        $json = json_decode($tester->getDisplay(), true);
        self::assertSame([], $json['storage']);
        self::assertNull($json['retained']);
        self::assertSame('AccessDeniedException', $json['retainedError']);
    }

    public function test_json_failure_prints_schema_and_error_and_never_prompts(): void
    {
        $this->project = new ProjectFixture(ProjectFixture::standardConfig());
        $tester = $this->project->tester(new StorageListCommand(fn (?string $profile, string $region) => $this->mocks->clients($region)));

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => true]);

        self::assertSame(1, $tester->getStatusCode());
        $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $json['schema']);
        self::assertNotSame('', $json['error']);
    }
}
