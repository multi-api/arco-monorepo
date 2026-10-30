<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Closure;
use LogicException;
use Multiapi\Arco\Resources\PickerUi;

/**
 * A PickerUi that answers from queues, one per kind of question, and records every question asked. An answer may be
 * a closure, called with the question's label (and options and default) when it is asked. A question with no answer
 * left fails the test.
 */
final class FakePickerUi implements PickerUi
{
    /** @var list<bool|Closure> */
    public array $confirms = [];
    /** @var list<list<string>|Closure> */
    public array $multiselects = [];
    /** @var list<string|Closure> */
    public array $texts = [];
    /** @var list<string|Closure> */
    public array $selects = [];
    /** @var list<array{string, string, mixed}> kind, label and default of every question, in order */
    public array $asked = [];

    public function confirm(string $label, bool $default): bool
    {
        $this->asked[] = ['confirm', $label, $default];
        $answer = array_shift($this->confirms) ?? throw new LogicException("Unexpected confirm: $label");
        return (bool) ($answer instanceof Closure ? $answer($label) : $answer);
    }

    public function multiselect(string $label, array $options, array $default): array
    {
        $this->asked[] = ['multiselect', $label, $default];
        $answer = array_shift($this->multiselects) ?? throw new LogicException("Unexpected multiselect: $label");
        /** @var list<string> */
        return $answer instanceof Closure ? $answer($label, $options, $default) : $answer;
    }

    public function text(string $label, string $default): string
    {
        $this->asked[] = ['text', $label, $default];
        $answer = array_shift($this->texts) ?? throw new LogicException("Unexpected text: $label");
        return (string) ($answer instanceof Closure ? $answer($label, $default) : $answer);
    }

    public function select(string $label, array $options, string $default): string
    {
        $this->asked[] = ['select', $label, $default];
        $answer = array_shift($this->selects) ?? throw new LogicException("Unexpected select: $label");
        return (string) ($answer instanceof Closure ? $answer($label, $options, $default) : $answer);
    }
}
