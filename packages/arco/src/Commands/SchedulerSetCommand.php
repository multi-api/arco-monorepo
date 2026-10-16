<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\EditsSettings;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'scheduler:set', description: 'Run the Laravel scheduler every N minutes (turns the console function on)')]
final class SchedulerSetCommand extends ArcoCommand
{
    use ResolvesTarget;
    use EditsSettings;

    protected function configure(): void
    {
        $this->addArgument('minutes', InputArgument::OPTIONAL, 'Minutes between schedule:run invocations (' . implode(', ', Validator::SCHEDULER_INTERVALS) . ')');
        $this->addTargetOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $given = $input->getArgument('minutes');
            $minutes = is_string($given) && $given !== ''
                ? (preg_match('/^-?\d{1,9}$/', $given) === 1 ? (int) $given : $given)
                : $this->askInterval($input, $io, 1);
            $error = Validator::settingError('scheduler', $minutes);
            if ($error !== null) {
                throw new UserError($this->t($error['key'], $error['params']));
            }
            [$raw, $environment] = $this->target($input, $io);
            $consoleOff = false;
            foreach ($environment === null ? ConfigResolver::environments($raw) : [$environment] as $env) {
                $consoleOff = $consoleOff || ConfigResolver::resolve($raw, $env)->console === false;
            }
            $from = $environment === null ? self::levelValue($raw, null, 'scheduler') : ConfigResolver::resolve($raw, $environment)->scheduler;
            if ($from === $minutes) {
                $io->note($this->t('settings.unchanged', ['where' => $this->where($environment)]));
                return self::SUCCESS;
            }
            if ($consoleOff) {
                // The scheduler needs the console function: replace console(false), here or inherited, at this level.
                $this->writeSetting($environment, 'console', true);
                $io->success($this->t('console.changed', ['environment' => $this->levelLabel($environment), 'from' => $this->t('console.off'), 'to' => $this->t('console.on')]));
            }
            $this->writeSetting($environment, 'scheduler', $minutes);
            $io->success($this->t('scheduler.changed', [
                'environment' => $this->levelLabel($environment),
                'from' => is_int($from) ? $this->t('scheduler.every', ['minutes' => $from]) : $this->t('scheduler.off'),
                'to' => $this->t('scheduler.every', ['minutes' => $minutes]),
            ]));
            $io->writeln($this->t('settings.result', ['where' => $this->where($environment)]));
            if ($consoleOff) {
                $io->writeln('    ' . ArcoFileEditor::settingLine('console', true));
            }
            $io->writeln('    ' . ArcoFileEditor::settingLine('scheduler', $minutes));
            $this->printDeployNext($io, $raw, $environment);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $io->error($this->describeError($e) ?? throw $e);
            return self::FAILURE;
        }
    }

    /** Asks for one of the allowed intervals; $default is the one taken on a plain Enter. */
    private function askInterval(InputInterface $input, SymfonyStyle $io, int $default): int
    {
        if (! $input->isInteractive()) {
            throw new UserError($this->t('scheduler.minutes_required', ['intervals' => implode(', ', Validator::SCHEDULER_INTERVALS)]));
        }
        $choices = [];
        foreach (Validator::SCHEDULER_INTERVALS as $interval) {
            $choices[$interval] = (string) $interval;
        }
        $question = new ChoiceQuestion($this->t('scheduler.ask'), $choices, (string) $default);
        $answer = $io->askQuestion($question);
        return is_numeric($answer) ? (int) $answer : $default;
    }
}
