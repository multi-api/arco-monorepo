<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\Acm\Exception\AcmException;
use Aws\Result;
use Multiapi\Arco\Commands\CertificateRemoveCommand;
use Multiapi\Arco\Commands\CertificateSetCommand;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CertificateCommandsTest extends TestCase
{
    private const ARN = 'arn:aws:acm:us-east-1:111111111111:certificate/abc';

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

    private function setter(?string $arcoPhp = null): CommandTester
    {
        $this->project = new ProjectFixture($arcoPhp ?? self::withDomain());
        return $this->project->tester(new CertificateSetCommand(fn (?string $profile, string $region) => $this->mocks->clients($region)));
    }

    private static function withDomain(): string
    {
        return str_replace('->timeout(60)', "->timeout(60)->domain('app.example.com')", ProjectFixture::standardConfig());
    }

    private function arcoPhp(): string
    {
        return (string) file_get_contents($this->project->dir . '/arco.php');
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @param array<string, mixed> $certificate */
    private function describe(array $certificate): Result
    {
        return new Result(['Certificate' => $certificate]);
    }

    /** @return array<string, mixed> */
    private static function exec(CommandTester $tester, string $arn = self::ARN): array
    {
        return ['status' => $tester->execute(['arn' => $arn, '--env' => 'production', '--profile' => 'p'], ['interactive' => false])];
    }

    public function test_sets_the_certificate(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), $this->describe(['Status' => 'ISSUED', 'DomainName' => 'app.example.com', 'NotAfter' => time() + 90 * 86400]));
        $tester = $this->setter();
        $status = self::exec($tester)['status'];

        self::assertSame(0, $status);
        self::assertStringContainsString("->certificate('" . self::ARN . "')", $this->arcoPhp());
        self::assertStringNotContainsString('does not cover', self::display($tester));
        self::assertContains('DescribeCertificate', $this->mocks->calls);
    }

    public function test_says_what_it_replaced(): void
    {
        $old = 'arn:aws:acm:us-east-1:111111111111:certificate/old';
        $tester = $this->setter(str_replace("->domain('app.example.com')", "->domain('app.example.com')->certificate('$old')", self::withDomain()));
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), $this->describe(['Status' => 'ISSUED', 'DomainName' => 'app.example.com', 'NotAfter' => time() + 90 * 86400]));
        $status = self::exec($tester)['status'];

        self::assertSame(0, $status);
        self::assertStringContainsString($old, self::display($tester));
        self::assertStringNotContainsString($old, $this->arcoPhp());
    }

    public function test_unchanged_when_already_set(): void
    {
        $tester = $this->setter(str_replace("->domain('app.example.com')", "->domain('app.example.com')->certificate('" . self::ARN . "')", self::withDomain()));
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), $this->describe(['Status' => 'ISSUED', 'DomainName' => 'app.example.com', 'NotAfter' => time() + 90 * 86400]));
        $status = self::exec($tester)['status'];

        self::assertSame(0, $status);
        self::assertStringContainsString('already', self::display($tester));
    }

    public function test_refuses_a_non_acm_arn(): void
    {
        $tester = $this->setter();
        $before = $this->arcoPhp();
        $status = self::exec($tester, 'arn:aws:iam::1:role/x')['status'];

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('arn:aws:iam::1:role/x', self::display($tester));
        self::assertStringContainsString('not an ACM certificate', self::display($tester));
        self::assertSame($before, $this->arcoPhp());
        self::assertSame([], $this->mocks->calls);
    }

    public function test_refuses_a_certificate_outside_us_east_1(): void
    {
        $tester = $this->setter();
        $before = $this->arcoPhp();
        $status = self::exec($tester, 'arn:aws:acm:eu-west-1:111111111111:certificate/abc')['status'];

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('eu-west-1', self::display($tester));
        self::assertSame($before, $this->arcoPhp());
        self::assertSame([], $this->mocks->calls);
    }

    public function test_warns_when_not_covering_the_domains(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), $this->describe(['Status' => 'ISSUED', 'DomainName' => '*.other.com', 'NotAfter' => time() + 90 * 86400]));
        $tester = $this->setter();
        $status = self::exec($tester)['status'];

        self::assertSame(0, $status);
        self::assertStringContainsString('app.example.com', self::display($tester));
        self::assertStringContainsString('does not cover', self::display($tester));
        self::assertStringContainsString("->certificate('" . self::ARN . "')", $this->arcoPhp());
    }

    public function test_warns_when_not_issued_or_expiring(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), $this->describe(['Status' => 'PENDING_VALIDATION', 'DomainName' => 'app.example.com', 'NotAfter' => time() + 10 * 86400]));
        $tester = $this->setter();
        $status = self::exec($tester)['status'];

        self::assertSame(0, $status);
        self::assertStringContainsString('PENDING_VALIDATION', self::display($tester));
        self::assertStringContainsString('expires in', self::display($tester));
        self::assertStringContainsString("->certificate('" . self::ARN . "')", $this->arcoPhp());
    }

    public function test_reports_a_certificate_that_does_not_exist_and_still_writes(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), AwsMocks::error(AcmException::class, 'DescribeCertificate', 'ResourceNotFoundException', 400));
        $tester = $this->setter();
        $status = self::exec($tester)['status'];

        self::assertSame(0, $status);
        self::assertStringContainsString('was not found', self::display($tester));
        self::assertStringContainsString("->certificate('" . self::ARN . "')", $this->arcoPhp());
    }

    public function test_offline_validates_shape_only(): void
    {
        $this->mocks->handler->append(AwsMocks::error(AcmException::class, 'GetCallerIdentity', 'ExpiredToken', 403));
        $tester = $this->setter();
        $status = self::exec($tester)['status'];

        self::assertSame(0, $status);
        self::assertStringNotContainsString('credentials', strtolower(self::display($tester)));
        self::assertStringContainsString("->certificate('" . self::ARN . "')", $this->arcoPhp());
    }

    public function test_remove(): void
    {
        $this->project = new ProjectFixture(str_replace("->domain('app.example.com')", "->domain('app.example.com')->certificate('" . self::ARN . "')", self::withDomain()));
        $tester = $this->project->tester(new CertificateRemoveCommand());
        $status = $tester->execute(['--env' => 'production'], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertStringNotContainsString('certificate(', $this->arcoPhp());
    }

    public function test_remove_without_a_certificate_changes_nothing(): void
    {
        $this->project = new ProjectFixture(self::withDomain());
        $tester = $this->project->tester(new CertificateRemoveCommand());
        $status = $tester->execute(['--env' => 'production'], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertSame(self::withDomain(), $this->arcoPhp());
        self::assertStringContainsString('already', self::display($tester));
    }
}
