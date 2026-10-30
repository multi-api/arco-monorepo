<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Closure;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\ResourcesCommand;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ArcoFileEditor;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The interactive half of `arco resources` (spec §7): one checklist per type with what is in use ticked, the names
 * and table uses, "replace X with Y?" for what would change a value arco.php sets (§6.3), the preview of arco.php's
 * lines and of the equivalent commands, then the editing commands one by one, stopping at the first that fails. It
 * writes nothing itself: the editing commands do, through ArcoFileEditor.
 */
final class Picker
{
    private const NETWORK = [ResourceType::Subnet, ResourceType::SecurityGroup, ResourceType::Endpoint];
    private const USES = ['generic', 'cache', 'failedJobs'];
    /** The table use => the arco.php method its command writes. */
    private const TABLE_METHODS = ['cache' => 'cache', 'failedJobs' => 'failedJobsTable'];

    /**
     * @param Closure(string, array<string, string|int>): string $t translates a message key
     * @param Closure(ResourceItem): string $label one line that identifies a resource
     * @param Closure(array{command: string, args: list<string>}, OutputInterface): int $run runs an editing command
     */
    public function __construct(
        private readonly PickerUi $ui,
        private readonly OutputInterface $output,
        private readonly Closure $t,
        private readonly Closure $label,
        private readonly Closure $run,
    ) {
    }

    /** Spec §7 step 1: the account, profile and region, confirmed before anything is listed. */
    public function confirmTarget(AppConfig $config, string $profile): bool
    {
        return $this->ui->confirm($this->t('resources.confirm_target', [
            'account' => (string) $config->account,
            'profile' => $profile,
            'region' => (string) $config->region,
            'environment' => $config->environment,
        ]), true);
    }

    /**
     * @param array<string, array{ok: true, items: list<ResourceItem>}|array{ok: false, error: array{key: string, params: array<string, string>}}> $result
     */
    public function run(AppConfig $config, array $result, bool $all): int
    {
        [$picked, $listed] = $this->pick($result, $all);
        $picked = $this->confirmConflicts($config, $picked, $listed);
        [$picked, $names, $uses] = $this->namesAndUses($config, $picked);
        try {
            $plan = SelectionPlan::from($config, $picked, $names, $uses, $listed);
        } catch (UserError $e) {
            $this->output->writeln('<error>' . $this->t((string) $e->key, $e->params) . '</error>');
            return 1;
        }
        if ($plan === []) {
            $this->output->writeln($this->t('resources.nothing_to_change'));
            return 0;
        }

        $this->preview($config, $plan);
        if (! $this->ui->confirm($this->t('resources.confirm_apply'), true)) {
            $this->output->writeln($this->t('resources.cancelled'));
            return 0;
        }
        return $this->apply($config, $plan);
    }

    /**
     * Spec §7 step 3: a checklist per listed type, in-use resources ticked. Subnets, security groups and endpoints of
     * two VPCs are refused at once and the list asked again; so is more than one certificate. Zones are shown only:
     * there is nothing to pick them for (spec §6.1).
     *
     * @param array<string, array{ok: true, items: list<ResourceItem>}|array{ok: false, error: array{key: string, params: array<string, string>}}> $result
     * @return array{list<ResourceItem>, list<ResourceItem>} the ticked items and every item the person was shown
     */
    private function pick(array $result, bool $all): array
    {
        $picked = [];
        $offered = [];
        foreach ($result as $value => $listed) {
            $type = ResourceType::from($value);
            $title = $this->t('resources.type.' . $value);
            if (! $listed['ok']) {
                $this->output->writeln("<error>$title: " . $this->t($listed['error']['key'], $listed['error']['params']) . '</error>');
                continue;
            }
            $shown = array_values(array_filter($listed['items'], fn (ResourceItem $item) => $all || ! $item->hidden || $item->inUse !== null));
            if ($type === ResourceType::Zone) {
                $this->showZones($title, $shown);
                continue;
            }
            $offered = [...$offered, ...$shown];
            if ($shown === []) {
                $this->output->writeln("$title: " . $this->t('resources.none'));
                continue;
            }
            $options = [];
            $byId = [];
            foreach ($shown as $item) {
                $options[$item->id] = ($this->label)($item);
                $byId[$item->id] = $item;
            }
            $default = array_values(array_map(fn (ResourceItem $item) => $item->id, array_filter($shown, fn (ResourceItem $item) => $item->inUse !== null)));
            while (true) {
                $ids = $this->ui->multiselect($this->t('resources.pick', ['type' => $title]), $options, $default);
                $chosen = array_values(array_filter(array_map(fn (string $id) => $byId[$id] ?? null, $ids)));
                $refused = $this->refused($type, [...$picked, ...$chosen], $chosen);
                if ($refused === null) {
                    break;
                }
                $this->output->writeln('<error>' . $refused . '</error>');
                $default = $ids;
            }
            $picked = [...$picked, ...$chosen];
        }
        return [$picked, $offered];
    }

    /**
     * @param list<ResourceItem> $all everything ticked so far, this list included
     * @param list<ResourceItem> $chosen this list's ticks
     */
    private function refused(ResourceType $type, array $all, array $chosen): ?string
    {
        if ($type === ResourceType::Certificate && count($chosen) > 1) {
            return $this->t('resources.one_certificate');
        }
        $vpcs = [];
        foreach ($all as $item) {
            $vpc = SelectionPlan::vpcId($item);
            if (in_array($item->type, self::NETWORK, true) && is_string($vpc)) {
                $vpcs[$vpc] = true;
            }
        }
        if (count($vpcs) <= 1) {
            return null;
        }
        $ids = array_keys($vpcs);
        sort($ids);
        return $this->t('resources.mixed_vpcs', ['vpcs' => implode(', ', $ids)]);
    }

    /** @param list<ResourceItem> $zones */
    private function showZones(string $title, array $zones): void
    {
        if ($zones === []) {
            return;
        }
        $this->output->writeln("<info>$title</info>");
        foreach ($zones as $zone) {
            $this->output->writeln('  ' . ($this->label)($zone));
        }
        $this->output->writeln('  ' . $this->t('resources.zones_not_picked'));
    }

    /**
     * Spec §6.3: a pick that would change what arco.php sets is asked about, and a "no" leaves that value alone.
     * Adding subnets, groups or endpoints to the vpc() in use changes nothing it has, so it is not asked; unticking
     * one in use is, once, with the vpc() line before and after. A certificate replacing another is asked too.
     *
     * @param list<ResourceItem> $picked
     * @param list<ResourceItem> $listed
     * @return list<ResourceItem>
     */
    private function confirmConflicts(AppConfig $config, array $picked, array $listed): array
    {
        $isNetwork = fn (ResourceItem $item): bool => in_array($item->type, self::NETWORK, true);
        $unticked = array_values(array_filter($listed, fn (ResourceItem $item) => $isNetwork($item) && $item->inUse !== null && ! in_array($item, $picked, true)));
        if ($unticked !== [] && $config->vpc !== null) {
            $network = array_values(array_filter($picked, $isNetwork));
            try {
                $command = SelectionPlan::from($config, $network, [], [], $listed)[0] ?? null;
            } catch (UserError) {
                $command = null; // refused: the plan says why, once the rest is asked
            }
            if ($command !== null) {
                $lines = SelectionPlan::preview($config, $command);
                $change = $this->ui->confirm($this->t('resources.replace_vpc', [
                    'old' => (string) $lines['old'],
                    'source' => $unticked[0]->inUse['source'] ?? 'arco.php',
                    'new' => $lines['new'] ?? $this->t('settings.remove_call', ['method' => 'vpc']),
                ]), false);
                if (! $change) {
                    // vpc() as it is: what is in use ticked again, nothing new.
                    $inUse = array_filter($listed, fn (ResourceItem $item) => $isNetwork($item) && $item->inUse !== null);
                    $picked = [...array_filter($picked, fn (ResourceItem $item) => ! $isNetwork($item)), ...$inUse];
                }
            }
        }
        $declined = [];
        foreach ($picked as $item) {
            if ($item->type === ResourceType::Certificate && $item->conflict !== null && ! $this->replace($item->conflict, $item->id)) {
                $declined[] = $item;
            }
        }
        return array_values(array_filter($picked, fn (ResourceItem $item) => ! in_array($item, $declined, true)));
    }

    /** @param array{with: string, source: string} $conflict */
    private function replace(array $conflict, string $by): bool
    {
        return $this->ui->confirm($this->t('resources.replace', ['with' => $conflict['with'], 'source' => $conflict['source'], 'by' => $by]), false);
    }

    /**
     * Spec §7 step 4: each picked bucket, queue and table gets its Arco name (prefilled with the suggested one, a name
     * arco.php already uses is asked again), and each table its use (prefilled with the use its keys fit). Using a
     * table as the cache or the failed-jobs table when arco.php has one is a replacement, so it is asked about.
     *
     * @param list<ResourceItem> $picked
     * @return array{list<ResourceItem>, array<string, string>, array<string, string>} the picks kept, names and uses by id
     */
    private function namesAndUses(AppConfig $config, array $picked): array
    {
        $taken = [
            'storage' => array_map(fn ($storage) => $storage->name, $config->storage),
            'queue' => array_map(fn ($queue) => $queue->name, $config->queues),
            'table' => array_map(fn ($table) => $table->name, $config->tables),
        ];
        $kept = [];
        $names = [];
        $uses = [];
        foreach ($picked as $item) {
            if ($item->inUse !== null) {
                $kept[] = $item;
                continue;
            }
            $kind = match ($item->type) {
                ResourceType::Bucket => 'storage',
                ResourceType::Queue => 'queue',
                ResourceType::Table => 'table',
                default => null,
            };
            if ($kind === 'table') {
                $options = [];
                foreach (self::USES as $use) {
                    $options[$use] = $this->t('resources.use.' . $use);
                }
                $use = $this->ui->select($this->t('resources.ask_use', ['resource' => $item->name ?? $item->id]), $options, self::fittingUse($item));
                $uses[$item->id] = $use;
                if ($use !== 'generic') {
                    $kind = null;
                    $current = $use === 'cache' ? $config->cacheTable : $config->failedJobsTable;
                    if ($current !== null && $current->existing !== $item->id) {
                        $method = self::TABLE_METHODS[$use];
                        $with = substr(ArcoFileEditor::singletonLine($method, $current->existing === null ? true : ['existing' => $current->existing]), 2);
                        if (! $this->replace(['with' => $with, 'source' => 'arco.php'], $item->id)) {
                            continue;
                        }
                    }
                }
            }
            if ($kind !== null) {
                $names[$item->id] = $this->askName($kind, $item, $taken[$kind]);
                $taken[$kind][] = $names[$item->id];
            }
            $kept[] = $item;
        }
        return [$kept, $names, $uses];
    }

    /** @param list<string> $taken the names of that kind arco.php (or an earlier pick) already uses */
    private function askName(string $kind, ResourceItem $item, array $taken): string
    {
        $label = $this->t('resources.ask_name', ['kind' => $this->t('resources.kind.' . $item->type->value), 'resource' => $item->name ?? $item->id]);
        while (true) {
            $name = trim($this->ui->text($label, (string) $item->suggestedName));
            if (! in_array($name, $taken, true)) {
                return $name;
            }
            $this->output->writeln('<error>' . $this->t('resources.name_taken', ['call' => "$kind('$name')"]) . '</error>');
        }
    }

    /** The use whose keys the table has (no table_schema warning for it); generic when none fits. */
    private static function fittingUse(ResourceItem $item): string
    {
        $misfits = [];
        foreach ($item->warnings as $warning) {
            if ($warning['key'] === 'resources.warning.table_schema') {
                $misfits[] = $warning['params']['use'] ?? '';
            }
        }
        foreach (self::USES as $use) {
            if (! in_array($use, $misfits, true)) {
                return $use;
            }
        }
        return 'generic';
    }

    /**
     * Spec §7 step 5: the lines each command adds or replaces in arco.php, then the same commands as a person or CI
     * would run them.
     *
     * @param list<array{command: string, args: list<string>}> $plan
     */
    private function preview(AppConfig $config, array $plan): void
    {
        $this->output->writeln('');
        $this->output->writeln('<info>' . $this->t('resources.preview_heading', ['environment' => $config->environment]) . '</info>');
        foreach ($plan as $command) {
            $lines = SelectionPlan::preview($config, $command);
            if ($lines['old'] !== null) {
                $this->output->writeln('  <fg=red>- ' . $lines['old'] . '</>');
            }
            $this->output->writeln('  <fg=green>+ ' . ($lines['new'] ?? $this->t('settings.remove_call', ['method' => 'vpc'])) . '</>');
        }
        $this->output->writeln('');
        $this->output->writeln('<info>' . $this->t('resources.preview_ci') . '</info>');
        foreach ($plan as $command) {
            $this->output->writeln('  ' . ResourcesCommand::shellLine($command));
        }
        $this->output->writeln('');
    }

    /**
     * Spec §7 step 6: the commands in order, one line each; the first failure stops the rest and shows its output.
     *
     * @param list<array{command: string, args: list<string>}> $plan
     */
    private function apply(AppConfig $config, array $plan): int
    {
        $done = 0;
        foreach ($plan as $command) {
            $buffer = new BufferedOutput($this->output->getVerbosity(), $this->output->isDecorated());
            $code = ($this->run)($command, $buffer);
            if ($code !== 0) {
                $this->output->writeln('  <error>✗</error> ' . ResourcesCommand::shellLine($command));
                $this->output->write($buffer->fetch());
                $this->output->writeln('<error>' . $this->t('resources.stopped', ['done' => $done, 'failed' => $command['command']]) . '</error>');
                return 1;
            }
            $this->output->writeln('  <info>✓</info> ' . ResourcesCommand::shellLine($command));
            $done++;
        }
        $this->output->writeln($this->t('resources.applied', ['count' => $done, 'environment' => $config->environment]));
        return 0;
    }

    /** @param array<string, string|int> $params */
    private function t(string $key, array $params = []): string
    {
        return ($this->t)($key, $params);
    }
}
