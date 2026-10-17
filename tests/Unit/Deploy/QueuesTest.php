<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Multiapi\Arco\Deploy\Queues;
use PHPUnit\Framework\TestCase;

final class QueuesTest extends TestCase
{
    private const URL = 'https://sqs.us-east-1.amazonaws.com/123456789012/shop-production-emails';

    private function queues(AwsMocks $mocks): Queues
    {
        $aws = $mocks->clients();
        return new Queues($aws->sqs(), $aws->lambda());
    }

    public function test_message_count(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['Attributes' => ['ApproximateNumberOfMessages' => '42']]));

        self::assertSame(42, $this->queues($mocks)->messageCount(self::URL));
        self::assertSame(['GetQueueAttributes'], $mocks->calls);
        self::assertSame(self::URL, $mocks->params[0]['QueueUrl']);
        self::assertSame(['ApproximateNumberOfMessages'], $mocks->params[0]['AttributeNames']);
    }

    public function test_message_count_is_null_when_it_cannot_be_read(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(SqsException::class, 'GetQueueAttributes', 'AWS.SimpleQueueService.NonExistentQueue', 400));

        self::assertNull($this->queues($mocks)->messageCount(self::URL));
    }

    public function test_url_of_a_queue_or_null_when_it_does_not_exist(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['QueueUrl' => self::URL]),
            AwsMocks::error(SqsException::class, 'GetQueueUrl', 'AWS.SimpleQueueService.NonExistentQueue', 400),
            AwsMocks::error(SqsException::class, 'GetQueueUrl', 'QueueDoesNotExist', 400),
        );
        $queues = $this->queues($mocks);

        self::assertSame(self::URL, $queues->url('shop-production-emails'));
        self::assertNull($queues->url('missing'));
        self::assertNull($queues->url('missing'));
        self::assertSame('shop-production-emails', $mocks->params[0]['QueueName']);
    }

    public function test_url_rethrows_other_errors(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(SqsException::class, 'GetQueueUrl', 'AccessDenied', 403));

        $this->expectException(SqsException::class);
        $this->queues($mocks)->url('shop-production-emails');
    }

    public function test_consumers(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['EventSourceMappings' => [
            ['FunctionArn' => 'arn:aws:lambda:us-east-1:123456789012:function:shop-production-queue-emails'],
            ['FunctionArn' => 'arn:aws:lambda:us-east-1:123456789012:function:other'],
        ]]));

        self::assertSame([
            'arn:aws:lambda:us-east-1:123456789012:function:shop-production-queue-emails',
            'arn:aws:lambda:us-east-1:123456789012:function:other',
        ], $this->queues($mocks)->consumers('arn:aws:sqs:us-east-1:123456789012:shop-production-emails'));
        self::assertSame(['ListEventSourceMappings'], $mocks->calls);
        self::assertSame('arn:aws:sqs:us-east-1:123456789012:shop-production-emails', $mocks->params[0]['EventSourceArn']);
    }
}
