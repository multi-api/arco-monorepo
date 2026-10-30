<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

use Laravel\Prompts\MultiSearchPrompt;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The picker's questions with laravel/prompts (owner's decision R7): real checkboxes, and a search box (`multisearch`)
 * when a list is longer than one screen. Only `arco resources` uses it, and only in a terminal.
 */
final class PromptsPickerUi implements PickerUi
{
    private const SCROLL = 15;

    public function __construct(OutputInterface $output)
    {
        Prompt::setOutput($output);
    }

    public function confirm(string $label, bool $default): bool
    {
        return confirm($label, $default);
    }

    public function multiselect(string $label, array $options, array $default): array
    {
        if (count($options) <= self::SCROLL) {
            return self::ids(multiselect($label, $options, $default, scroll: self::SCROLL));
        }
        $search = static fn (string $query): array => $query === ''
            ? $options
            : array_filter($options, static fn (string $text, string $id): bool => mb_stripos($text . ' ' . $id, $query) !== false, ARRAY_FILTER_USE_BOTH);
        $prompt = new MultiSearchPrompt($label, $search, scroll: self::SCROLL);
        // The multisearch helper takes no default: what is in use starts ticked through the prompt's own values.
        $prompt->values = array_intersect_key($options, array_flip($default));
        $picked = $prompt->prompt();
        return is_array($picked) ? self::ids($picked) : [];
    }

    public function text(string $label, string $default): string
    {
        return text($label, default: $default, required: true);
    }

    public function select(string $label, array $options, string $default): string
    {
        return (string) select($label, $options, $default);
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function ids(array $values): array
    {
        return array_values(array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $values));
    }
}
