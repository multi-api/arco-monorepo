<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Multiapi\Arco\Arco;
use Multiapi\Arco\Cli\Translator;
use Multiapi\Arco\Logs\LogEvent;
use Multiapi\Arco\Logs\LogLine;
use Multiapi\Arco\Logs\LogRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class LogRendererTest extends TestCase
{
    private const AT = 1792065601000; // 2026-10-15 12:00:01 UTC
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /** @return list<string> */
    private function render(string $message, bool $verbose = false, bool $platform = false): array
    {
        $translator = new Translator('en', Arco::packageRoot() . '/lang');
        $renderer = new LogRenderer($translator->trans(...), $verbose, $platform);
        return $renderer->render(new LogEvent(self::AT, $message, '/aws/lambda/web/shop-production'), LogLine::parse($message));
    }

    public function test_app_lines_show_time_level_message_and_request_id(): void
    {
        self::assertSame(
            ['12:00:01 <fg=red>ERROR  </> Payment failed [requestId=r-1]'],
            $this->render('{"message":"Payment failed","context":{"requestId":"r-1"},"level_name":"ERROR"}'),
        );
        self::assertSame(['12:00:01 <fg=green>INFO   </> Order placed'], $this->render('{"message":"Order placed","level_name":"INFO"}'));
    }

    public function test_verbose_adds_context_and_the_stack_trace(): void
    {
        $lines = $this->render('{"message":"Boom","level_name":"ERROR","context":{"order":7,"exception":{"class":"RuntimeException","message":"boom","file":"/var/task/app/app/X.php:10","trace":["/var/task/app/app/Y.php:20"]}}}', verbose: true);

        self::assertSame([
            '12:00:01 <fg=red>ERROR  </> Boom',
            '    {"order":7}',
            '    RuntimeException: boom at /var/task/app/app/X.php:10',
            '      /var/task/app/app/Y.php:20',
        ], $lines);
    }

    public function test_plain_text_is_printed_as_is_and_escaped(): void
    {
        $lines = $this->render('PHP Warning:  a <b> tag and <fg=red>markup</>');

        self::assertCount(1, $lines);
        self::assertSame('PHP Warning:  a <b> tag and <fg=red>markup</>', (new OutputFormatter(true))->format($lines[0]), 'shown exactly as logged, never interpreted as console markup');
    }

    public function test_control_characters_never_reach_the_terminal(): void
    {
        // Log text is partly attacker-controlled (paths, user agents): no escape sequences (title, OSC 8/52, colors).
        $text = $this->render("GET /\e]0;pwned\x07x\e[31mred\e[0m\x00\x7f\tUA\r\nnext");
        $app = $this->render((string) json_encode(['message' => "Login \e]52;c;Y2xpcA==\x07as \e[2Jadmin", 'level_name' => 'INFO']));
        $verbose = $this->render((string) json_encode(['message' => 'Boom', 'level_name' => 'ERROR', 'context' => ['exception' => ['class' => 'E', 'message' => "bad \e[5mblink", 'trace' => ["/a.php:1 \e[1m"]]]]), verbose: true);

        self::assertSame(["GET /]0;pwnedx[31mred[0m\tUA next"], $text, 'C0 controls but tab are dropped (line breaks become spaces)');
        self::assertSame(['12:00:01 <fg=green>INFO   </> Login ]52;c;Y2xpcA==as [2Jadmin'], $app);
        self::assertSame(['12:00:01 <fg=red>ERROR  </> Boom', '    E: bad [5mblink', '      /a.php:1 [1m'], $verbose);
    }

    public function test_platform_events_are_summarized_or_hidden(): void
    {
        self::assertSame(['12:00:01 INIT    Cold start: 812 ms'], $this->render('{"type":"platform.initReport","record":{"status":"success","metrics":{"totalDurationMs":812}}}'));
        self::assertSame(['12:00:01 <fg=red>INIT   </> Init failed: Runtime.ExitError'], $this->render('{"type":"platform.initReport","record":{"status":"error","errorType":"Runtime.ExitError"}}'));
        self::assertSame([], $this->render('START RequestId: abc Version: 7'));
        self::assertSame([], $this->render('{"arco":"boot","ms":3}'));
        self::assertSame(['12:00:01 <fg=red>ERROR  </> {"arco":"fatal","error":"x"}'], $this->render('{"arco":"fatal","error":"x"}'));
    }

    public function test_platform_flag_shows_every_platform_event(): void
    {
        self::assertSame(['12:00:01 PLATFORM START RequestId: abc Version: 7'], $this->render('START RequestId: abc Version: 7', platform: true));
        self::assertSame(['12:00:01 <fg=gray>DEBUG  </> {"arco":"boot","ms":3}'], $this->render('{"arco":"boot","ms":3}', platform: true));
    }
}
