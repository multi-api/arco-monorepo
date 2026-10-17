<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\QueueConfig;
use PHPUnit\Framework\TestCase;

final class QueueConfigTest extends TestCase
{
    private function config(): \Multiapi\Arco\Config\AppConfig
    {
        $raw = App::make('shop')->region('us-east-1')->environment('production', fn ($e) => $e->account('123456789012'))->toArray();
        return ConfigResolver::resolve($raw, 'production');
    }

    public function test_defaults(): void
    {
        $q = new QueueConfig('emails');

        self::assertSame([60, 1024, null, 1, 3, true, false, null, false], [$q->timeout, $q->memory, $q->concurrency, $q->batchSize, $q->tries, $q->dlq, $q->fifo, $q->existing, $q->default]);
    }

    public function test_physical_names_url_and_arn(): void
    {
        $q = new QueueConfig('emails');
        $c = $this->config();

        self::assertSame('shop-production-emails', $q->physicalName($c));
        self::assertSame('shop-production-emails-dlq', $q->dlqName($c));
        self::assertSame('https://sqs.us-east-1.amazonaws.com/123456789012/shop-production-emails', $q->url($c));
        self::assertSame('arn:aws:sqs:us-east-1:123456789012:shop-production-emails', $q->arn($c));
        self::assertSame('arn:aws:sqs:us-east-1:123456789012:shop-production-emails-dlq', $q->dlqArn($c));
    }

    public function test_fifo_names(): void
    {
        $q = new QueueConfig('emails', fifo: true);
        $c = $this->config();

        self::assertSame('shop-production-emails.fifo', $q->physicalName($c));
        self::assertSame('shop-production-emails-dlq.fifo', $q->dlqName($c));
        self::assertSame('arn:aws:sqs:us-east-1:123456789012:shop-production-emails-dlq.fifo', $q->dlqArn($c));
    }

    public function test_no_dlq(): void
    {
        $q = new QueueConfig('emails', dlq: false);

        self::assertNull($q->dlqName($this->config()));
        self::assertNull($q->dlqArn($this->config()));
    }

    public function test_existing_name_url_and_arn_resolve_the_same(): void
    {
        $c = $this->config();
        foreach (['legacy', 'https://sqs.us-east-1.amazonaws.com/123456789012/legacy', 'arn:aws:sqs:us-east-1:123456789012:legacy'] as $ref) {
            $q = new QueueConfig('x', dlq: false, existing: $ref);
            self::assertSame('arn:aws:sqs:us-east-1:123456789012:legacy', $q->arn($c), $ref);
            self::assertSame('https://sqs.us-east-1.amazonaws.com/123456789012/legacy', $q->url($c), $ref);
            self::assertSame('legacy', $q->physicalName($c), $ref);
            self::assertNull($q->dlqName($c));
        }
    }

    public function test_existing_from_another_account_keeps_its_own(): void
    {
        $q = new QueueConfig('x', existing: 'arn:aws:sqs:eu-west-1:999999999999:other');

        self::assertSame('https://sqs.eu-west-1.amazonaws.com/999999999999/other', $q->url($this->config()));
    }
}
