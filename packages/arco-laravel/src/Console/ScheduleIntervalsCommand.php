<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Console;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Prints the app's scheduled tasks as one JSON line, [{"task": "...", "minutes": [0, 5, ...]}]: the
 * minutes of the hour (0–59, UTC) at which each task can fire. `arco scheduler:set` and `arco deploy`
 * use it to check that the scheduler runs schedule:run often enough (multiapi/arco ScheduleInspector).
 */
final class ScheduleIntervalsCommand extends Command
{
    protected $signature = 'arco:schedule-intervals';
    protected $description = 'Print the minutes of the hour each scheduled task can fire at, as JSON (internal)';
    protected $hidden = true;

    public function handle(Schedule $schedule): int
    {
        $json = json_encode(self::tasks($schedule), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->getOutput()->writeln($json, OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }

    /** @return list<array{task: string, minutes: list<int>}> */
    public static function tasks(Schedule $schedule): array
    {
        $tasks = [];
        foreach ($schedule->events() as $event) {
            $tasks[] = ['task' => self::label($event), 'minutes' => self::minutes($event)];
        }
        return $tasks;
    }

    /**
     * The minutes (0–59, UTC) at which the event's cron expression matches. Only the part of the
     * event timezone's UTC offset below one hour moves them (Asia/Kolkata, +05:30: 30 minutes).
     *
     * @return list<int>
     */
    private static function minutes(Event $event): array
    {
        $field = (string) (new CronExpression((string) $event->expression))->getExpression(CronExpression::MINUTE);
        $matcher = new CronExpression("$field * * * *");
        $shift = self::offsetMinutes($event->timezone);
        $minutes = [];
        for ($minute = 0; $minute < 60; $minute++) {
            if ($matcher->isDue(new DateTimeImmutable(sprintf('2000-01-01 00:%02d:00', $minute), new DateTimeZone('UTC')))) {
                $minutes[] = (($minute - $shift) % 60 + 60) % 60;
            }
        }
        sort($minutes);
        return array_values(array_unique($minutes));
    }

    /** The minutes part of the timezone's current UTC offset (-59..59); the default timezone when not set. */
    private static function offsetMinutes(mixed $timezone): int
    {
        try {
            $zone = match (true) {
                $timezone instanceof DateTimeZone => $timezone,
                is_string($timezone) && $timezone !== '' => new DateTimeZone($timezone),
                default => new DateTimeZone(date_default_timezone_get()),
            };
        } catch (Throwable) {
            return 0;
        }
        return intdiv($zone->getOffset(new DateTimeImmutable('now', $zone)), 60) % 60;
    }

    private static function label(Event $event): string
    {
        if (is_string($event->description) && $event->description !== '') {
            return $event->description;
        }
        if (is_string($event->command) && $event->command !== '') {
            $artisan = trim(Application::formatCommandString(''));
            $command = str_starts_with($event->command, $artisan) ? substr($event->command, strlen($artisan)) : $event->command;
            return trim($command);
        }
        return (string) $event->getSummaryForDisplay();
    }
}
