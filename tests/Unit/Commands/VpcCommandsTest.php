<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\Ec2\Exception\Ec2Exception;
use Aws\Result;
use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Commands\VpcRemoveCommand;
use Multiapi\Arco\Commands\VpcSetCommand;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

final class VpcCommandsTest extends TestCase
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

    private function setter(?string $arcoPhp = null): CommandTester
    {
        $this->project = new ProjectFixture($arcoPhp ?? ProjectFixture::standardConfig());
        return $this->project->tester(new VpcSetCommand(fn (?string $profile, string $region) => $this->mocks->clients($region)));
    }

    private function arcoPhp(): string
    {
        return (string) file_get_contents($this->project->dir . '/arco.php');
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @param list<string> $vpcIds one per subnet */
    private function subnets(array $vpcIds): Result
    {
        $subnets = [];
        foreach ($vpcIds as $i => $vpc) {
            $subnets[] = ['SubnetId' => 'subnet-' . $i, 'VpcId' => $vpc];
        }
        return new Result(['Subnets' => $subnets]);
    }

    public function test_sets_vpc_for_the_environment(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), $this->subnets(['vpc-1']));
        $tester = $this->setter();
        $status = $tester->execute(['--env' => 'production', '--profile' => 'p', '--subnet' => ['subnet-a1'], '--security-group' => ['sg-1']], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertStringContainsString('vpc', self::display($tester));
        self::assertStringContainsString("->vpc(subnets: ['subnet-a1'], securityGroups: ['sg-1'])", $this->arcoPhp());
        self::assertContains('DescribeSubnets', $this->mocks->calls);
    }

    public function test_endpoint_option_forms(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), $this->subnets(['vpc-1']));
        $tester = $this->setter();
        $status = $tester->execute([
            '--env' => 'production', '--profile' => 'p', '--subnet' => ['subnet-a1'], '--security-group' => ['sg-1'],
            '--endpoint' => ['s3', 'dynamodb=vpce-0abc'],
        ], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertStringContainsString("endpoints: ['s3', 'dynamodb' => 'vpce-0abc']", $this->arcoPhp());
    }

    public function test_says_what_it_replaced(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), $this->subnets(['vpc-1']));
        $config = str_replace('->timeout(60)', "->timeout(60)->vpc(subnets: ['subnet-old'], securityGroups: ['sg-old'])", ProjectFixture::standardConfig());
        $tester = $this->setter($config);
        $status = $tester->execute(['--env' => 'production', '--profile' => 'p', '--subnet' => ['subnet-new'], '--security-group' => ['sg-new']], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertStringContainsString('subnet-old', self::display($tester));
        self::assertStringNotContainsString('subnet-old', $this->arcoPhp());
        self::assertStringContainsString("subnets: ['subnet-new']", $this->arcoPhp());
    }

    public function test_refuses_invalid_ids_before_writing(): void
    {
        $tester = $this->setter();
        $before = $this->arcoPhp();
        $status = $tester->execute(['--env' => 'production', '--profile' => 'p', '--subnet' => ['nope'], '--security-group' => ['sg-1']], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('is not a subnet id', self::display($tester));
        self::assertSame($before, $this->arcoPhp());
        self::assertSame([], $this->mocks->calls);
    }

    public function test_requires_one_subnet_and_one_sg(): void
    {
        $tester = $this->setter();
        $before = $this->arcoPhp();
        $status = $tester->execute(['--env' => 'production', '--profile' => 'p', '--subnet' => ['subnet-a1']], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('between 1 and 5 security groups', self::display($tester));
        self::assertSame($before, $this->arcoPhp());
    }

    public function test_mixed_vpcs_refused_when_credentials_work(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), $this->subnets(['vpc-1', 'vpc-2']));
        $tester = $this->setter();
        $before = $this->arcoPhp();
        $status = $tester->execute(['--env' => 'production', '--profile' => 'p', '--subnet' => ['subnet-0', 'subnet-1'], '--security-group' => ['sg-1']], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('more than one VPC (vpc-1, vpc-2)', self::display($tester));
        self::assertSame($before, $this->arcoPhp());
    }

    public function test_vpc_set_warns_public_subnet(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result(['Subnets' => [['SubnetId' => 'subnet-a1', 'VpcId' => 'vpc-1', 'AvailableIpAddressCount' => 100, 'Tags' => []]]]),
            new Result(['RouteTables' => [['RouteTableId' => 'rtb-main', 'VpcId' => 'vpc-1', 'Associations' => [['Main' => true]], 'Routes' => [['DestinationCidrBlock' => '0.0.0.0/0', 'GatewayId' => 'igw-1']]]]]),
            new Result(['Vpcs' => []]),
        );
        $tester = $this->setter();
        $status = $tester->execute(['--env' => 'production', '--profile' => 'p', '--subnet' => ['subnet-a1'], '--security-group' => ['sg-1']], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertStringContainsString('subnet-a1', self::display($tester));
        self::assertStringContainsString('gets no public IP', self::display($tester));
        self::assertStringContainsString('Only 100 free addresses', self::display($tester));
        self::assertStringContainsString("->vpc(subnets: ['subnet-a1'], securityGroups: ['sg-1'])", $this->arcoPhp());
    }

    public function test_unknown_subnet_fails_before_writing(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            AwsMocks::error(Ec2Exception::class, 'DescribeSubnets', 'InvalidSubnetID.NotFound', 400, "The subnet ID 'subnet-zz' does not exist"),
        );
        $tester = $this->setter();
        $before = $this->arcoPhp();
        $status = $tester->execute(['--env' => 'production', '--profile' => 'p', '--subnet' => ['subnet-zz'], '--security-group' => ['sg-1']], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Subnet subnet-zz was not found', self::display($tester));
        self::assertSame($before, $this->arcoPhp());
    }

    public function test_denied_describe_subnets_stays_offline(): void
    {
        $this->mocks->handler->append(
            new Result(['Account' => '111111111111']),
            AwsMocks::error(Ec2Exception::class, 'DescribeSubnets', 'UnauthorizedOperation', 403),
        );
        $tester = $this->setter();
        $status = $tester->execute(['--env' => 'production', '--profile' => 'p', '--subnet' => ['subnet-a1'], '--security-group' => ['sg-1']], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertStringContainsString("->vpc(subnets: ['subnet-a1']", $this->arcoPhp());
    }

    public function test_offline_validates_shape_only(): void
    {
        $this->mocks->handler->append(AwsMocks::error(Ec2Exception::class, 'GetCallerIdentity', 'ExpiredToken', 403));
        $tester = $this->setter();
        $status = $tester->execute(['--env' => 'production', '--profile' => 'p', '--subnet' => ['subnet-a1'], '--security-group' => ['sg-1']], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertStringNotContainsString('credentials', strtolower(self::display($tester)));
        self::assertStringContainsString("->vpc(subnets: ['subnet-a1'], securityGroups: ['sg-1'])", $this->arcoPhp());
    }

    public function test_remove_writes_false_when_app_level_has_vpc(): void
    {
        $config = str_replace('->concurrency(48)', "->concurrency(48)->vpc(subnets: ['subnet-a1'], securityGroups: ['sg-1'])", ProjectFixture::standardConfig());
        $this->project = new ProjectFixture($config);
        $tester = $this->project->tester(new VpcRemoveCommand());
        $status = $tester->execute(['--env' => 'production'], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertStringContainsString('->vpc(false)', $this->arcoPhp());
        self::assertStringContainsString("->vpc(subnets: ['subnet-a1']", $this->arcoPhp());
    }

    public function test_remove_deletes_the_environment_call(): void
    {
        $config = str_replace('->timeout(60)', "->timeout(60)->vpc(subnets: ['subnet-a1'], securityGroups: ['sg-1'])", ProjectFixture::standardConfig());
        $this->project = new ProjectFixture($config);
        $tester = $this->project->tester(new VpcRemoveCommand());
        $status = $tester->execute(['--env' => 'production'], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertStringNotContainsString('vpc(', $this->arcoPhp());
    }

    public function test_remove_without_a_vpc_changes_nothing(): void
    {
        $this->project = new ProjectFixture();
        file_put_contents($this->project->dir . '/arco.php', ProjectFixture::standardConfig());
        $tester = $this->project->tester(new VpcRemoveCommand());
        $status = $tester->execute(['--env' => 'production'], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertSame(ProjectFixture::standardConfig(), $this->arcoPhp());
        self::assertStringContainsString('already', self::display($tester));
    }

    public function test_spanish_strings(): void
    {
        $config = str_replace('->timeout(60)', "->timeout(60)->vpc(subnets: ['subnet-a1'], securityGroups: ['sg-1'])", ProjectFixture::standardConfig());
        $this->project = new ProjectFixture($config);
        $app = Application::create();
        $app->addCommands([new VpcRemoveCommand()]);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $tester->run(['command' => 'vpc:remove', '--env' => 'production', '--lang' => 'es'], ['interactive' => false]);

        self::assertStringContainsString('Se quitó vpc()', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }
}
