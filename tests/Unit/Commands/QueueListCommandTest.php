<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Multiapi\Arco\Commands\QueueListCommand;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class QueueListCommandTest extends TestCase
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

    private function run_(string $queues): CommandTester
    {
        $this->project = new ProjectFixture(str_replace('->concurrency(48)', '->concurrency(48)' . $queues, ProjectFixture::standardConfig()));
        $tester = $this->project->tester(new QueueListCommand(fn (?string $profile, string $region) => $this->mocks->clients($region)));
        $tester->execute(['--env' => 'production', '--profile' => 'p'], ['interactive' => false]);
        return $tester;
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function test_lists_each_queue_with_messages_dlq_and_worker_version(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '12', 'ApproximateNumberOfMessagesNotVisible' => '3']]),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '2']]),                     // DLQ
            new Result(['FunctionVersion' => '4']),                                                    // GetAlias
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '0', 'ApproximateNumberOfMessagesNotVisible' => '0']]),
            AwsMocks::error(LambdaException::class, 'GetAlias', 'ResourceNotFoundException', 404),
        );

        $tester = $this->run_("->queue('emails', timeout: 120, maxInstances: 5)->queue('legacy', existing: 'legacy-jobs', dlq: false)");

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'GetQueueAttributes', 'GetQueueAttributes', 'GetAlias', 'GetQueueAttributes', 'GetAlias'], $this->mocks->calls);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', $this->mocks->params[1]['QueueUrl']);
        self::assertSame(['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible'], $this->mocks->params[1]['AttributeNames']);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails-dlq', $this->mocks->params[2]['QueueUrl']);
        self::assertSame('shop-production-queue-emails', $this->mocks->params[3]['FunctionName']);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/legacy-jobs', $this->mocks->params[4]['QueueUrl']);

        $display = self::display($tester);
        self::assertStringContainsString('emails', $display);
        self::assertStringContainsString('URL https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', $display);
        self::assertStringContainsString('Queue created by Arco', $display);
        self::assertStringContainsString('Messages 12 waiting, 3 in flight', $display);
        self::assertStringContainsString('Dead-letter queue shop-production-emails-dlq (2 messages)', $display);
        self::assertStringContainsString('Worker shop-production-queue-emails (version 4)', $display);
        self::assertStringContainsString('Settings timeout 120 s, memory 1024 MB, batch size 1, max instances 5, tries 3', $display);
        self::assertStringContainsString('Queue existing (not managed by Arco)', $display);
        self::assertStringContainsString('Dead-letter queue none', $display);
        self::assertStringContainsString('max instances no limit', $display);
        self::assertStringContainsString('Worker not deployed yet (run arco deploy)', $display);
    }

    public function test_a_queue_not_created_yet_or_unreadable_is_said_so(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            AwsMocks::error(SqsException::class, 'GetQueueAttributes', 'AWS.SimpleQueueService.NonExistentQueue', 400),
            AwsMocks::error(SqsException::class, 'GetQueueAttributes', 'AccessDenied', 403),
            new Result(['FunctionVersion' => '1']),
        );

        $tester = $this->run_("->queue('emails')");

        $tester->assertCommandIsSuccessful();
        $display = self::display($tester);
        self::assertStringContainsString('Messages not created yet (run arco deploy)', $display);
        self::assertStringContainsString('Dead-letter queue shop-production-emails-dlq (unknown: AccessDenied)', $display);
    }

    public function test_without_queues_says_so_without_aws_calls(): void
    {
        $tester = $this->run_('');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No queues in arco.php for production', self::display($tester));
        self::assertSame([], $this->mocks->calls);
    }
}
