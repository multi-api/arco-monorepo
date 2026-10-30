<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Resources\Inventory;
use Multiapi\Arco\Resources\ResourceItem;
use Multiapi\Arco\Resources\ResourceType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Lists the existing AWS resources an environment could use (subnets, security groups, gateway endpoints, buckets,
 * queues, tables, certificates, hosted zones), each with the command that adds it to arco.php. Read-only: it makes
 * List*, Describe* and Get* calls and writes nothing. `--json` is the document Arco Cloud reads (spec §8).
 */
#[AsCommand(name: 'resources', description: 'List existing AWS resources and the commands that add them to arco.php')]
final class ResourcesCommand extends ArcoCommand
{
    use ResolvesTarget;

    /**
     * @param (Closure(?string, string): AwsClients)|null $awsFactory
     * @param (Closure(): int)|null $clock the current time, for the certificates' expiry (tests)
     */
    public function __construct(?Closure $awsFactory = null, private readonly ?Closure $clock = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS profile to use')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'AWS region to list (default: the environment\'s)')
            ->addOption('dns-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile whose Route 53 zones are listed too (default: the environment\'s dns() profile)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these types: ' . implode(', ', array_map(fn (ResourceType $t) => $t->value, ResourceType::cases())))
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also show the resources hidden by default (Arco\'s own, other regions, the default VPC)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON')
            ->addOption(ConfigLayer::OPTION, null, InputOption::VALUE_REQUIRED, ConfigLayer::DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : new NullOutput();
        $io = new SymfonyStyle($input, $output);
        $errorIo = new SymfonyStyle($input, $stderr);
        if ($json) {
            // No profile prompt or "profile saved" text in the JSON stream: it needs a profile that resolves.
            $input->setInteractive(false);
        }
        try {
            $types = $this->types($input);
            // With --json, config warnings are diagnostics: stderr, never the document.
            $config = $json
                ? $this->loadTarget($input, $errorIo, fn (string $key, array $params) => $errorIo->warning($this->t($key, $params)))
                : $this->loadTarget($input, $io);
            $region = $input->getOption('region');
            if (is_string($region) && $region !== '') {
                $config = $config->withRegion($region);
            }
            $aws = $this->awsClients($config, $input, $io);
            $located = $config->account === null ? $config->withAccount($aws->accountId()) : $config;
            $dns = in_array(ResourceType::Zone, $types, true) ? $this->dnsClients($located, $input) : null;
            if ($dns !== null && $this->dnsCredentialProfile === $this->credentialProfile) {
                $dns = null; // the same account: its zones are the deploy profile's
            }

            $inventory = new Inventory($this->credentialProfile ?? 'environment', $this->dnsCredentialProfile, $this->clock);
            $result = $inventory->collect($located, $aws, $dns, $types, function (ResourceType $type) use ($json, $stderr): void {
                if (! $json) {
                    $stderr->writeln($this->t('resources.listing_type', ['type' => mb_strtolower($this->t('resources.type.' . $type->value))]));
                }
            });
            foreach ($inventory->warnings as $warning) {
                $errorIo->warning($this->t($warning['key'], $warning['params']));
            }

            if ($json) {
                $this->printJson($output, $located, $result, $inventory->warnings);
                return self::SUCCESS;
            }
            if ($input->isInteractive()) {
                // Branch point for the interactive picker (spec §7, Task 9). Until it lands, a terminal gets the plain listing.
                return $this->printPlain($io, $located, $result, (bool) $input->getOption('all'));
            }
            return $this->printPlain($io, $located, $result, (bool) $input->getOption('all'));
        } catch (Throwable $e) {
            $message = $this->describeError($e) ?? throw $e;
            if ($json) {
                $output->writeln((string) json_encode(['schema' => 1, 'error' => self::error($this->describeErrorKey($e), self::params($e), $message)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $io->error($message);
            }
            return self::FAILURE;
        }
    }

    /**
     * The --type values in the order the command lists them; every type when none is given.
     *
     * @return list<ResourceType>
     */
    private function types(InputInterface $input): array
    {
        $option = $input->getOption('type');
        $wanted = is_array($option) ? array_values(array_filter($option, 'is_string')) : [];
        foreach ($wanted as $value) {
            if (ResourceType::tryFrom($value) === null) {
                $params = ['type' => $value, 'types' => implode(', ', array_map(fn (ResourceType $t) => $t->value, ResourceType::cases()))];
                throw new UserError($this->t('resources.unknown_type', $params), 'resources.unknown_type', $params);
            }
        }
        return array_values(array_filter(ResourceType::cases(), fn (ResourceType $t) => $wanted === [] || in_array($t->value, $wanted, true)));
    }

    /**
     * Spec §8. A failed type is {ok: false, error: {key, params, message}}; `warnings` holds what went wrong without
     * failing a type (a DNS profile whose zones could not be read), each {key, params, message}.
     *
     * @param array<string, array{ok: true, items: list<ResourceItem>}|array{ok: false, error: array{key: string, params: array<string, string>}}> $result
     * @param list<array{key: string, params: array<string, string>}> $warnings
     */
    private function printJson(OutputInterface $output, AppConfig $config, array $result, array $warnings): void
    {
        $types = [];
        foreach ($result as $type => $listed) {
            $types[$type] = $listed['ok']
                ? ['ok' => true, 'items' => array_map(fn (ResourceItem $item) => $item->toArray(), $listed['items'])]
                : ['ok' => false, 'error' => self::error($listed['error']['key'], $listed['error']['params'], $this->t($listed['error']['key'], $listed['error']['params']))];
        }
        $output->writeln((string) json_encode([
            'schema' => 1,
            'account' => $config->account,
            'region' => $config->region,
            'environment' => $config->environment,
            'types' => (object) $types,
            'warnings' => array_map(fn (array $w) => self::error($w['key'], $w['params'], $this->t($w['key'], $w['params'])), $warnings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * One section per type: each resource's id and name, its details, warnings, what picking it would replace, and the
     * command that adds it. Hidden resources only with --all. Never prompts and writes nothing.
     *
     * @param array<string, array{ok: true, items: list<ResourceItem>}|array{ok: false, error: array{key: string, params: array<string, string>}}> $result
     */
    private function printPlain(SymfonyStyle $io, AppConfig $config, array $result, bool $all): int
    {
        $io->writeln($this->t('resources.target', [
            'account' => (string) $config->account,
            'profile' => $this->credentialProfile ?? 'environment',
            'region' => (string) $config->region,
            'environment' => $config->environment,
        ]));
        $network = false;
        foreach ($result as $type => $listed) {
            $title = $this->t('resources.type.' . $type);
            if (! $listed['ok']) {
                $io->section($title);
                $io->writeln('<error>' . $this->t($listed['error']['key'], $listed['error']['params']) . '</error>');
                continue;
            }
            $shown = array_values(array_filter($listed['items'], fn (ResourceItem $item) => $all || ! $item->hidden));
            $hidden = count($listed['items']) - count($shown);
            $io->section($title . ' (' . count($shown) . ')');
            if ($shown === []) {
                $io->writeln($this->t('resources.none'));
            }
            foreach ($shown as $item) {
                $this->printItem($io, $item);
                $network = $network || in_array($item->type, [ResourceType::Subnet, ResourceType::SecurityGroup, ResourceType::Endpoint], true);
            }
            if ($hidden > 0) {
                $io->writeln($this->t('resources.hidden_count', ['count' => $hidden]));
            }
        }
        $io->newLine();
        $io->writeln($this->t('resources.nothing_written'));
        if ($network) {
            $io->writeln($this->t('resources.vpc_full_list'));
        }
        return self::SUCCESS;
    }

    private function printItem(SymfonyStyle $io, ResourceItem $item): void
    {
        $marks = [];
        if ($item->inUse !== null) {
            $marks[] = $this->t('resources.in_use', ['source' => $item->inUse['source']]);
        }
        if ($item->hidden) {
            $marks[] = $this->t('resources.hidden');
        }
        if ($item->unnamed()) {
            $marks[] = $this->t('resources.unnamed');
        }
        $label = $item->name === null || $item->name === $item->id ? $item->id : "{$item->name}  {$item->id}";
        $io->writeln(' <info>' . $label . '</info>' . ($marks === [] ? '' : '  · ' . implode(' · ', $marks)));
        $io->writeln('   ' . $this->details($item));
        foreach ($item->warnings as $warning) {
            $io->writeln('   <comment>! ' . $this->t($warning['key'], $warning['params']) . '</comment>');
        }
        if ($item->conflict !== null) {
            $io->writeln('   ' . $this->t('resources.conflict', $item->conflict));
        }
        $apply = $item->apply;
        if ($apply === null) {
            return;
        }
        if (isset($apply['command']) && is_string($apply['command'])) {
            /** @var array{command: string, args: list<string>} $apply */
            $io->writeln('   ' . self::shellLine($apply));
            return;
        }
        /** @var array<string, array{command: string, args: list<string>}> $apply */
        foreach ($apply as $use => $command) {
            $io->writeln('   ' . $this->t('resources.use.' . $use) . ': ' . self::shellLine($command));
        }
    }

    /** The type's identifying fields of spec §5.2 on one line. */
    private function details(ResourceItem $item): string
    {
        $d = $item->details;
        $text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '-';
        $vpc = is_array($d['vpc'] ?? null) ? $text($d['vpc']['id'] ?? null) . (is_string($d['vpc']['name'] ?? null) ? " ({$d['vpc']['name']})" : '') : '-';
        $list = static fn (mixed $values): string => is_array($values) && $values !== [] ? implode(', ', array_filter($values, 'is_string')) : '-';
        $params = match ($item->type) {
            ResourceType::Subnet => ['vpc' => $vpc, 'az' => $text($d['az'] ?? null), 'cidr' => $text($d['cidr'] ?? null), 'free' => $text($d['freeIps'] ?? null), 'egress' => $text($d['egress'] ?? null)],
            ResourceType::SecurityGroup => ['vpc' => $vpc, 'description' => $text($d['description'] ?? null)],
            ResourceType::Endpoint => ['vpc' => $vpc, 'service' => $text($d['service'] ?? null), 'routeTables' => $list($d['routeTables'] ?? null)],
            ResourceType::Bucket => ['region' => $text($d['region'] ?? null), 'created' => $text($d['created'] ?? null)],
            ResourceType::Queue => ['arn' => $text($d['arn'] ?? null), 'timeout' => $text($d['visibilityTimeout'] ?? null), 'dlq' => $text($d['dlq'] ?? null)],
            ResourceType::Table => [
                'keys' => is_array($d['keys'] ?? null) && $d['keys'] !== []
                    ? implode(', ', array_map(static fn (mixed $k): string => is_array($k) ? $text($k['name'] ?? null) . ' (' . $text($k['type'] ?? null) . ', ' . $text($k['role'] ?? null) . ')' : '', $d['keys']))
                    : '-',
                'billing' => $text($d['billing'] ?? null),
                'ttl' => $text($d['ttl'] ?? null),
            ],
            ResourceType::Certificate => ['status' => $text($d['status'] ?? null), 'expires' => $text($d['expires'] ?? null), 'domains' => $list($d['domains'] ?? null), 'inUseBy' => $text($d['inUseBy'] ?? null)],
            ResourceType::Zone => [
                'visibility' => $this->t(($d['private'] ?? false) === true ? 'resources.zone_private' : 'resources.zone_public'),
                'records' => $text($d['records'] ?? null),
                'account' => $text($d['account'] ?? null),
            ],
        };
        return $this->t('resources.detail.' . $item->type->value, $params);
    }

    /**
     * An `apply` argv as one line a person can paste into a shell: `arco` and every element quoted when a POSIX shell
     * would split or expand it.
     *
     * @param array{command: string, args: list<string>} $apply
     */
    public static function shellLine(array $apply): string
    {
        $quote = static fn (string $arg): string => preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#D', $arg) === 1
            ? $arg
            : "'" . str_replace("'", "'\\''", $arg) . "'";
        return implode(' ', array_map($quote, ['arco', $apply['command'], ...$apply['args']]));
    }

    /**
     * @param array<string, string|int> $params
     * @return array{key: string, params: object, message: string}
     */
    private static function error(string $key, array $params, string $message): array
    {
        return ['key' => $key, 'params' => (object) $params, 'message' => $message];
    }

    /** @return array<string, string|int> */
    private static function params(Throwable $e): array
    {
        return match (true) {
            $e instanceof UserError => $e->params,
            $e instanceof ConfigException => $e->params,
            default => [],
        };
    }
}
