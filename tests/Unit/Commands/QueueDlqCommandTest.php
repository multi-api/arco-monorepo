<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Multiapi\Arco\Commands\QueueDlqCommand;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class QueueDlqCommandTest extends TestCase
{
    private const QUEUES = "->queue('emails')->queue('legacy', existing: 'legacy-jobs', dlq: false)";
    private ?ProjectFixture $project = null;
    private AwsMocks $mocks;

    protected function setUp(): void
    {
        $this->mocks = new AwsMocks();
        $this->project = new ProjectFixture(str_replace('->concurrency(48)', '->concurrency(48)' . self::QUEUES, ProjectFixture::standardConfig()));
    }

    protected function tearDown(): void
    {
        $this->project?->restore();
    }

    /** @param array<string, mixed> $options */
    private function run_(string $queue, array $options = []): CommandTester
    {
        $tester = $this->project?->tester(new QueueDlqCommand(fn (?string $profile, string $region) => $this->mocks->clients($region))) ?? throw new \LogicException('No project.');
        $tester->execute(['name' => $queue, '--env' => 'production', '--profile' => 'p', ...$options], ['interactive' => false]);
        return $tester;
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function test_prints_the_count_and_samples_without_consuming(): void
    {
        $long = str_repeat('x', 600);
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '2']]),
            new Result(['Messages' => [
                ['MessageId' => 'm-1', 'Body' => '{"job":"SendMail"}', 'Attributes' => ['SentTimestamp' => '1792065600000', 'ApproximateReceiveCount' => '4']],
                ['MessageId' => 'm-2', 'Body' => $long, 'Attributes' => ['ApproximateReceiveCount' => '5']],
            ]]),
        );

        $tester = $this->run_('emails');

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'GetQueueAttributes', 'ReceiveMessage'], $this->mocks->calls);
        $dlq = 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails-dlq';
        self::assertSame($dlq, $this->mocks->params[1]['QueueUrl']);
        self::assertSame($dlq, $this->mocks->params[2]['QueueUrl']);
        self::assertSame(10, $this->mocks->params[2]['MaxNumberOfMessages']);
        self::assertSame(0, $this->mocks->params[2]['VisibilityTimeout']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('shop-production-emails-dlq has about 2 messages', self::display($tester));
        self::assertStringContainsString('{"job":"SendMail"}', $display);
        self::assertStringContainsString('m-1', $display);
        self::assertStringContainsString('received 4 times', $display);
        self::assertStringContainsString(str_repeat('x', 500) . '…', $display);
        self::assertStringNotContainsString(str_repeat('x', 501), $display);
        self::assertStringContainsString('arco queue:dlq emails --redrive', self::display($tester));
    }

    public function test_control_characters_in_a_body_never_reach_the_terminal(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '1']]),
            new Result(['Messages' => [
                ['MessageId' => 'm-1', 'Body' => "{\"a\":\"\e]0;pwned\x07\e[2Jx\u{9b}31m\"}\n\tnext", 'Attributes' => []],
                ['MessageId' => 'm-2', 'Body' => "bin\xff\e[31mary", 'Attributes' => []],
            ]]),
        );

        $display = $this->run_('emails')->getDisplay();

        self::assertStringNotContainsString("\e", $display);
        self::assertStringNotContainsString("\x07", $display);
        self::assertStringNotContainsString("\u{9b}", $display, 'C1 controls (CSI) too');
        self::assertStringContainsString('{"a":"]0;pwned[2Jx31m"}' . "\n\tnext", $display, 'newlines and tabs stay');
        self::assertStringContainsString('[31mary', $display, 'a body that is not UTF-8 is stripped too');
    }

    public function test_an_empty_dlq_is_said_so(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '0']]),
            new Result(['Messages' => []]),
        );

        $tester = $this->run_('emails');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('shop-production-emails-dlq is empty', self::display($tester));
    }

    public function test_redrive_moves_the_messages_back_to_the_queue(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '7']]),
            new Result(['TaskHandle' => 'task-abc']),
        );

        $tester = $this->run_('emails', ['--redrive' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'GetQueueAttributes', 'StartMessageMoveTask'], $this->mocks->calls);
        self::assertSame('arn:aws:sqs:us-east-1:111111111111:shop-production-emails-dlq', $this->mocks->params[2]['SourceArn']);
        self::assertSame('arn:aws:sqs:us-east-1:111111111111:shop-production-emails', $this->mocks->params[2]['DestinationArn']);
        self::assertStringContainsString('Moving about 7 messages from shop-production-emails-dlq back to shop-production-emails (task task-abc)', self::display($tester));
    }

    public function test_redrive_of_an_empty_dlq_starts_nothing(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '0']]),
        );

        $tester = $this->run_('emails', ['--redrive' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertNotContains('StartMessageMoveTask', $this->mocks->calls);
        self::assertStringContainsString('shop-production-emails-dlq is empty', self::display($tester));
    }

    public function test_a_queue_without_an_arco_dlq_fails_before_any_aws_call(): void
    {
        $tester = $this->run_('legacy', ['--redrive' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Queue "legacy" has no dead-letter queue created by Arco', self::display($tester));
        self::assertSame([], $this->mocks->calls);
    }

    public function test_an_unknown_queue_fails(): void
    {
        $tester = $this->run_('nope');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No queue "nope" in arco.php (queues: emails, legacy)', self::display($tester));
        self::assertSame([], $this->mocks->calls);
    }

    public function test_a_dlq_not_created_yet_fails_with_a_hint(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            AwsMocks::error(SqsException::class, 'GetQueueAttributes', 'AWS.SimpleQueueService.NonExistentQueue', 400),
        );

        $tester = $this->run_('emails');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The dead-letter queue shop-production-emails-dlq does not exist yet', self::display($tester));
    }
}
