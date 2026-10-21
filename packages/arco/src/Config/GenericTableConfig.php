<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/**
 * One single-table DynamoDB table of the app, ->table('<name>', ...): Arco creates <app>-<env>-<name>
 * (PK and SK strings, on demand, TTL on `ttl` when set), or references the `existing` one as given.
 */
final readonly class GenericTableConfig
{
    private const ARN = '/^arn:aws[a-z-]*:dynamodb:([a-z0-9-]+):(\d{12}):table\/([A-Za-z0-9_.-]{3,255})$/';

    public function __construct(
        public string $name,
        /** the attribute DynamoDB reads the expiry (epoch seconds) from; null: no TTL */
        public ?string $ttl = null,
        /** name or ARN of a table Arco does not manage, used as given */
        public ?string $existing = null,
    ) {
    }

    /** Whether `existing` can name a table: a DynamoDB table name or a table ARN. */
    public static function isExisting(string $value): bool
    {
        return TableConfig::isTableName($value) || preg_match(self::ARN, $value) === 1;
    }

    /** The name of the table Arco creates for a table(): <app>-<environment>-<name>. */
    public static function managedName(string $app, string $environment, string $name): string
    {
        return $app . '-' . $environment . '-' . $name;
    }

    /** What the app gets in ARCO_TABLES: the created table's name, or `existing` as given (name or ARN). */
    public function tableName(AppConfig $config): string
    {
        return $this->existing ?? self::managedName($config->app, $config->environment, $this->name);
    }

    /** The table's name alone (the part after table/ of an ARN). */
    public function physicalName(AppConfig $config): string
    {
        $table = $this->tableName($config);
        return preg_match(self::ARN, $table, $match) === 1 ? $match[3] : $table;
    }

    /** An `existing` ARN as given; otherwise the table in the app's region and account. */
    public function arn(AppConfig $config): string
    {
        $table = $this->tableName($config);
        if (preg_match(self::ARN, $table) === 1) {
            return $table;
        }
        return 'arn:' . Partition::of($config->region ?? '') . ':dynamodb:' . ($config->region ?? '') . ':' . ($config->account ?? '') . ':table/' . $table;
    }
}
