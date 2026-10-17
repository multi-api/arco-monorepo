<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\ResourcesStack;
use PHPUnit\Framework\TestCase;

final class ResourcesStackTest extends TestCase
{
    private function config(): AppConfig
    {
        $raw = App::make('shop')->region('us-east-1')
            ->queue('emails', timeout: 120, tries: 5)
            ->queue('high-priority', fifo: true, dlq: false)
            ->queue('legacy', existing: 'arn:aws:sqs:us-east-1:123456789012:legacy', dlq: false)
            ->environment('production', fn ($e) => $e->account('123456789012'))
            ->toArray();
        return ConfigResolver::resolve($raw, 'production');
    }

    public function test_name(): void
    {
        self::assertSame('arco-shop-production-resources', ResourcesStack::name($this->config()));
    }

    public function test_logical_ids_are_unique_per_queue_name(): void
    {
        self::assertSame('QueueEmails', ResourcesStack::logicalId('emails'));
        self::assertSame('QueueHighXpriority', ResourcesStack::logicalId('high-priority'));
        self::assertNotSame(ResourcesStack::logicalId('a-1'), ResourcesStack::logicalId('a1'));
        self::assertSame('QueueEmailsDlq', ResourcesStack::dlqLogicalId('emails'));
    }

    public function test_template_with_a_standard_queue_with_dlq_a_fifo_queue_without_and_an_existing_queue_left_out(): void
    {
        $template = ResourcesStack::template($this->config());

        $tags = fn (string $resource) => [
            ['Key' => 'arco:app', 'Value' => 'shop'],
            ['Key' => 'arco:environment', 'Value' => 'production'],
            ['Key' => 'arco:resource', 'Value' => $resource],
        ];
        self::assertSame([
            'QueueEmails' => [
                'Type' => 'AWS::SQS::Queue',
                'DeletionPolicy' => 'Retain',
                'UpdateReplacePolicy' => 'Retain',
                'Properties' => [
                    'QueueName' => 'shop-production-emails',
                    'VisibilityTimeout' => 720,
                    'RedrivePolicy' => [
                        'deadLetterTargetArn' => ['Fn::GetAtt' => ['QueueEmailsDlq', 'Arn']],
                        'maxReceiveCount' => 7,
                    ],
                    'Tags' => $tags('queue:emails'),
                ],
            ],
            'QueueEmailsDlq' => [
                'Type' => 'AWS::SQS::Queue',
                'DeletionPolicy' => 'Retain',
                'UpdateReplacePolicy' => 'Retain',
                'Properties' => [
                    'QueueName' => 'shop-production-emails-dlq',
                    'MessageRetentionPeriod' => 1209600,
                    'Tags' => $tags('queue:emails:dlq'),
                ],
            ],
            'QueueHighXpriority' => [
                'Type' => 'AWS::SQS::Queue',
                'DeletionPolicy' => 'Retain',
                'UpdateReplacePolicy' => 'Retain',
                'Properties' => [
                    'QueueName' => 'shop-production-high-priority.fifo',
                    'VisibilityTimeout' => 360,
                    'FifoQueue' => true,
                    'Tags' => $tags('queue:high-priority'),
                ],
            ],
        ], $template['Resources']);
        self::assertSame([
            'QueueEmailsUrl' => ['Value' => ['Ref' => 'QueueEmails']],
            'QueueEmailsArn' => ['Value' => ['Fn::GetAtt' => ['QueueEmails', 'Arn']]],
            'QueueHighXpriorityUrl' => ['Value' => ['Ref' => 'QueueHighXpriority']],
            'QueueHighXpriorityArn' => ['Value' => ['Fn::GetAtt' => ['QueueHighXpriority', 'Arn']]],
        ], $template['Outputs']);
        self::assertSame('2010-09-09', $template['AWSTemplateFormatVersion']);
    }

    public function test_a_fifo_queue_gets_a_fifo_dlq(): void
    {
        $raw = App::make('shop')->region('us-east-1')->queue('orders', fifo: true)->environment('production')->toArray();
        $resources = ResourcesStack::template(ConfigResolver::resolve($raw, 'production'))['Resources'];

        self::assertIsArray($resources['QueueOrdersDlq']['Properties'] ?? null);
        self::assertSame('shop-production-orders-dlq.fifo', $resources['QueueOrdersDlq']['Properties']['QueueName']);
        self::assertTrue($resources['QueueOrdersDlq']['Properties']['FifoQueue']);
    }

    public function test_created_queues_leave_existing_ones_out(): void
    {
        self::assertSame(['emails', 'high-priority'], array_map(fn ($q) => $q->name, ResourcesStack::createdQueues($this->config())));
    }
}
