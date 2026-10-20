<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\LocalState;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'profile', description: 'Show or change the AWS profile remembered for each environment')]
final class ProfileCommand extends ArcoCommand
{
    use ResolvesTarget;

    public function __construct()
    {
        $this->awsFactory = fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the remembered profiles as JSON')
            ->addOption('set', null, InputOption::VALUE_REQUIRED, 'Profile to remember for the environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $state = new LocalState($this->projectDir());
            $only = $input->getOption('env');
            if ($input->getOption('json') && $input->getOption('set') === null || ! is_string($only)) {
                $raw = (new ConfigLoader())->load($this->projectDir() . '/arco.php');
                if ($input->getOption('json')) {
                    $profiles = [];
                    foreach (ConfigResolver::environments($raw) as $env) {
                        if (is_string($only) && $env !== $only) {
                            continue;
                        }
                        $profiles[] = ['environment' => $env, 'profile' => $state->profile($env)];
                    }
                    $output->writeln((string) json_encode(['schema' => 1, 'profiles' => $profiles], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    return self::SUCCESS;
                }
                $io->table(
                    [$this->t('profile.environment'), $this->t('profile.profile')],
                    array_map(fn (string $env) => [$env, $state->profile($env) ?? '-'], ConfigResolver::environments($raw)),
                );
                return self::SUCCESS;
            }

            $config = $this->loadTarget($input, $io);
            $profile = $input->getOption('set');
            if (! is_string($profile) || $profile === '') {
                $profile = $this->askProfile($config, $io);
            }
            $state->rememberProfile($config->environment, $profile);
            $io->success($this->t('profile.saved', ['environment' => $config->environment, 'profile' => $profile]));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }
}
