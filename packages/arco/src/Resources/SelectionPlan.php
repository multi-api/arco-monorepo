<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\TableConfig;
use Multiapi\Arco\Config\VpcConfig;

/**
 * Turns what the person ticked in the picker into the editing commands that write it (spec §6, §8), as argv like the
 * JSON's `apply`, so the picker, CI and Arco Cloud end up with the same arco.php. Also says which arco.php line each
 * command adds or replaces, for the preview (spec §7 step 5), without writing anything.
 */
final class SelectionPlan
{
    private const NETWORK = [ResourceType::Subnet, ResourceType::SecurityGroup, ResourceType::Endpoint];

    /**
     * Subnets, security groups and endpoints become one vpc:set with the full list (it sets, it does not merge):
     * the ticked ones, in-use ones included since the picker pre-ticks them, so an unticked one is dropped, and
     * unticking all of them is a vpc:remove. A network type the person was not shown keeps what the config has, as
     * do the endpoints Arco creates. Every other pick runs its `apply`, with the Arco name and the table use chosen;
     * picks already in use are left as they are.
     *
     * @param list<ResourceItem> $picked every ticked item, annotated (inUse, apply, suggestedName)
     * @param array<string, string> $names item id => the Arco name chosen (buckets, queues, tables used as tables)
     * @param array<string, string> $uses table id => generic, cache or failedJobs (default generic)
     * @param list<ResourceType>|null $offered the types the person was shown; null: all of them
     * @return list<array{command: string, args: list<string>}>
     * @throws UserError resources.mixed_vpcs, resources.vpc_incomplete (the message is the key; translate it)
     */
    public static function from(AppConfig $config, array $picked, array $names, array $uses, ?array $offered = null): array
    {
        $plan = [];
        $vpc = self::vpc($config, $picked, $offered ?? ResourceType::cases());
        if ($vpc !== null) {
            $plan[] = $vpc;
        }
        foreach ($picked as $item) {
            if (in_array($item->type, self::NETWORK, true) || $item->inUse !== null) {
                continue;
            }
            $command = self::command($item, $names, $uses);
            if ($command !== null) {
                $plan[] = $command;
            }
        }
        return $plan;
    }

    /**
     * The arco.php line a command replaces (null: none) and the one it writes (null: it removes the call), as the
     * editing command would print them.
     *
     * @param array{command: string, args: list<string>} $command
     * @return array{old: ?string, new: ?string}
     */
    public static function preview(AppConfig $config, array $command): array
    {
        $positional = [];
        $options = [];
        foreach ($command['args'] as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = explode('=', substr($arg, 2), 2) + [1 => ''];
                $options[$key][] = $value;
            } else {
                $positional[] = $arg;
            }
        }
        $existing = ['existing' => $options['existing'][0] ?? ''];
        $name = $positional[0] ?? '';
        $currentVpc = $config->vpc === null ? null : ArcoFileEditor::vpcLine(self::vpcValue($config->vpc));
        return match ($command['command']) {
            'vpc:set' => ['old' => $currentVpc, 'new' => ArcoFileEditor::vpcLine([
                'subnets' => $options['subnet'] ?? [],
                'securityGroups' => $options['security-group'] ?? [],
                'endpoints' => self::endpointValues($options['endpoint'] ?? []),
            ])],
            'vpc:remove' => ['old' => $currentVpc, 'new' => null],
            'certificate:set' => [
                'old' => $config->certificate === null ? null : ArcoFileEditor::settingLine('certificate', $config->certificate),
                'new' => ArcoFileEditor::settingLine('certificate', $name),
            ],
            'storage:add' => ['old' => null, 'new' => ArcoFileEditor::namedLine('storage', $name, $existing)],
            'queue:add' => ['old' => null, 'new' => ArcoFileEditor::queueLine($name, $existing)],
            'table:add' => ['old' => null, 'new' => ArcoFileEditor::namedLine('table', $name, $existing)],
            'cache:add' => ['old' => self::tableLine('cache', $config->cacheTable), 'new' => ArcoFileEditor::singletonLine('cache', $existing)],
            'failed-jobs:add' => ['old' => self::tableLine('failedJobsTable', $config->failedJobsTable), 'new' => ArcoFileEditor::singletonLine('failedJobsTable', $existing)],
            default => ['old' => null, 'new' => null],
        };
    }

    /**
     * @param list<ResourceItem> $picked
     * @param list<ResourceType> $offered
     * @return array{command: string, args: list<string>}|null
     */
    private static function vpc(AppConfig $config, array $picked, array $offered): ?array
    {
        $current = $config->vpc;
        $ids = static fn (ResourceType $type): array => array_values(array_map(
            static fn (ResourceItem $item): string => $item->id,
            array_filter($picked, static fn (ResourceItem $item): bool => $item->type === $type),
        ));

        $vpcs = [];
        foreach ($picked as $item) {
            $vpc = self::vpcId($item);
            if (in_array($item->type, self::NETWORK, true) && is_string($vpc) && ! in_array($vpc, $vpcs, true)) {
                $vpcs[] = $vpc;
            }
        }
        if (count($vpcs) > 1) {
            sort($vpcs);
            throw new UserError('resources.mixed_vpcs', 'resources.mixed_vpcs', ['vpcs' => implode(', ', $vpcs)]);
        }

        $subnets = in_array(ResourceType::Subnet, $offered, true) ? $ids(ResourceType::Subnet) : ($current->subnets ?? []);
        $groups = in_array(ResourceType::SecurityGroup, $offered, true) ? $ids(ResourceType::SecurityGroup) : ($current->securityGroups ?? []);
        $owned = [];
        if (in_array(ResourceType::Endpoint, $offered, true)) {
            foreach ($picked as $item) {
                $service = $item->details['service'] ?? null;
                if ($item->type === ResourceType::Endpoint && is_string($service)) {
                    $owned[$service] = $item->id;
                }
            }
        } else {
            foreach ($current->endpoints ?? [] as $endpoint) {
                if ($endpoint['id'] !== null) {
                    $owned[$endpoint['service']] = $endpoint['id'];
                }
            }
        }
        // The endpoints Arco creates are not listed: they stay, unless one of the service is now picked.
        $managed = [];
        foreach ($current->endpoints ?? [] as $endpoint) {
            if ($endpoint['id'] === null && ! isset($owned[$endpoint['service']])) {
                $managed[] = $endpoint['service'];
            }
        }

        $env = '--env=' . $config->environment;
        if ($subnets === [] && $groups === [] && $owned === []) {
            return $current === null ? null : ['command' => 'vpc:remove', 'args' => [$env]];
        }
        if ($subnets === [] || $groups === []) {
            throw new UserError('resources.vpc_incomplete', 'resources.vpc_incomplete');
        }
        $endpoints = [...$managed, ...array_map(static fn (string $service, string $id): string => "$service=$id", array_keys($owned), $owned)];
        if ($current !== null && self::same($current->subnets, $subnets) && self::same($current->securityGroups, $groups)
            && self::same(self::endpointArgs(self::vpcValue($current)['endpoints']), $endpoints)) {
            return null;
        }
        return ['command' => 'vpc:set', 'args' => [
            ...array_map(static fn (string $id): string => '--subnet=' . $id, $subnets),
            ...array_map(static fn (string $id): string => '--security-group=' . $id, $groups),
            ...array_map(static fn (string $endpoint): string => '--endpoint=' . $endpoint, $endpoints),
            $env,
        ]];
    }

    /** The VPC of a subnet, security group or endpoint, as its lister gives it. */
    public static function vpcId(ResourceItem $item): ?string
    {
        $vpc = $item->details['vpc'] ?? null;
        return is_array($vpc) && is_string($vpc['id'] ?? null) ? $vpc['id'] : null;
    }

    /**
     * @param array<string, string> $names
     * @param array<string, string> $uses
     * @return array{command: string, args: list<string>}|null
     */
    private static function command(ResourceItem $item, array $names, array $uses): ?array
    {
        $apply = $item->apply;
        $named = in_array($item->type, [ResourceType::Bucket, ResourceType::Queue], true);
        if ($item->type === ResourceType::Table) {
            $use = $uses[$item->id] ?? 'generic';
            $apply = $apply[$use] ?? null;
            $named = $use === 'generic';
        }
        if (! is_array($apply) || ! is_string($apply['command'] ?? null) || ! is_array($apply['args'] ?? null)) {
            return null;
        }
        /** @var list<string> $args */
        $args = array_values($apply['args']);
        if ($named && isset($names[$item->id]) && $args !== []) {
            $args[0] = $names[$item->id]; // the suggested name the apply carries
        }
        return ['command' => $apply['command'], 'args' => $args];
    }

    /** @return array{subnets: list<string>, securityGroups: list<string>, endpoints: array<int|string, string>} */
    private static function vpcValue(VpcConfig $vpc): array
    {
        $endpoints = [];
        foreach ($vpc->endpoints as $endpoint) {
            $endpoint['id'] === null ? $endpoints[] = $endpoint['service'] : $endpoints[$endpoint['service']] = $endpoint['id'];
        }
        return ['subnets' => $vpc->subnets, 'securityGroups' => $vpc->securityGroups, 'endpoints' => $endpoints];
    }

    /**
     * vpc:set's --endpoint values as vpc() holds them: `s3` (Arco creates it) or `s3=vpce-...` (an existing one).
     *
     * @param list<string> $values
     * @return array<int|string, string>
     */
    private static function endpointValues(array $values): array
    {
        $endpoints = [];
        foreach ($values as $value) {
            if (str_contains($value, '=')) {
                [$service, $id] = explode('=', $value, 2);
                $endpoints[$service] = $id;
            } else {
                $endpoints[] = $value;
            }
        }
        return $endpoints;
    }

    /**
     * @param array<int|string, string> $endpoints
     * @return list<string>
     */
    private static function endpointArgs(array $endpoints): array
    {
        $args = [];
        foreach ($endpoints as $key => $value) {
            $args[] = is_int($key) ? $value : "$key=$value";
        }
        return $args;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function same(array $a, array $b): bool
    {
        sort($a);
        sort($b);
        return $a === $b;
    }

    /** @param 'cache'|'failedJobsTable' $method */
    private static function tableLine(string $method, ?TableConfig $table): ?string
    {
        if ($table === null) {
            return null;
        }
        return ArcoFileEditor::singletonLine($method, $table->existing === null ? true : ['existing' => $table->existing]);
    }
}
