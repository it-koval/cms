<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Mcp\ApiTokenService;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Mcp\McpStdio;
use Itk\Cms\Tests\Core\Fixture\McpSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** itk:mcp: lines in, lines out, nothing else on the output; the key is checked for every line. */
final class McpStdioTest extends KernelTestCase
{
    use McpSupport;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->mcpBoot();
    }

    protected function tearDown(): void
    {
        $this->mcpClean();
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, what went to the output, what went to the error stream
     */
    private function serve(string $plain, string $input): array
    {
        $in = \fopen('php://memory', 'w+');
        \fwrite($in, $input);
        \rewind($in);
        $out = \fopen('php://memory', 'w+');
        $err = \fopen('php://memory', 'w+');
        $code = self::getContainer()->get(McpStdio::class)->serve($plain, $in, $out, $err);
        \rewind($out);
        \rewind($err);

        return [$code, (string) \stream_get_contents($out), (string) \stream_get_contents($err)];
    }

    #[Test]
    public function oneAnswerPerLineAndNothingElseOnTheOutput(): void
    {
        $key = $this->mcpKey($this->mcpUser(), [McpScopes::READ, McpScopes::WRITE]);
        $input = \implode("\n", [
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '',
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"pages_list","arguments":{"limit":1}}}',
            'not json',
        ]) . "\n";
        [$code, $out, $err] = $this->serve($key['plain'], $input);
        self::assertSame(0, $code);
        self::assertSame('', $err);
        $lines = \explode("\n", \rtrim($out, "\n"));
        self::assertCount(4, $lines, 'the notification and the empty line have no answer');
        foreach ($lines as $line) {
            self::assertIsArray(\json_decode($line, true), 'every line of the output is a JSON-RPC message: ' . $line);
        }
        $answers = \array_map(static fn (string $l): array => \json_decode($l, true), $lines);
        self::assertSame('2025-06-18', $answers[0]['result']['protocolVersion']);
        self::assertContains('page_create', \array_column($answers[1]['result']['tools'], 'name'));
        self::assertFalse($answers[2]['result']['isError']);
        self::assertSame(-32700, $answers[3]['error']['code']);
        self::assertStringNotContainsString($key['plain'], $out);
    }

    #[Test]
    public function aBadKeyOrASwitchedOffServerStopsWithAMessageOnTheErrorStreamOnly(): void
    {
        [$code, $out, $err] = $this->serve('itk_' . \str_repeat('x', 43), '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n");
        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('not valid', $err);

        $key = $this->mcpKey($this->mcpUser());
        self::getContainer()->get(McpSettings::class)->setEnabled(false);
        [$code, $out, $err] = $this->serve($key['plain'], '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n");
        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('switched off', $err);
    }

    #[Test]
    public function revokingTheKeyStopsARunningSession(): void
    {
        $key = $this->mcpKey($this->mcpUser());
        $svc = self::getContainer()->get(ApiTokenService::class);
        // the input hands over the second line only after the key has been revoked: as if it happened while the assistant was running
        TestRevokingStream::$lines = ["{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n", "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n"];
        TestRevokingStream::$beforeSecond = static function () use ($svc, $key): void {
            $svc->revoke($svc->find($key['token']->id));
        };
        if (!\in_array('testrevoking', \stream_get_wrappers(), true)) {
            \stream_wrapper_register('testrevoking', TestRevokingStream::class);
        }
        $in = \fopen('testrevoking://in', 'r');
        $out = \fopen('php://memory', 'w+');
        $err = \fopen('php://memory', 'w+');
        $code = self::getContainer()->get(McpStdio::class)->serve($key['plain'], $in, $out, $err);
        \rewind($out);
        \rewind($err);
        $lines = \explode("\n", \rtrim((string) \stream_get_contents($out), "\n"));
        self::assertSame(1, $code);
        self::assertCount(1, $lines, 'the first ping was answered, the second one was not');
        self::assertSame(1, \json_decode($lines[0], true)['id']);
        self::assertStringContainsString('revoked', (string) \stream_get_contents($err));
    }

    /** The database closes the connection of the running program (a restart, idle_session_timeout): the same as PostgreSQL does when somebody terminates our backend. */
    private function killDatabaseConnection(): void
    {
        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $pid = (int) $connection->fetchOne('SELECT pg_backend_pid()');
        \Doctrine\DBAL\DriverManager::getConnection($connection->getParams())->executeStatement('SELECT pg_terminate_backend(?)', [$pid]);
    }

    #[Test]
    public function aLostDatabaseConnectionIsReconnectedBeforeAMessageAndAtTheStart(): void
    {
        $key = $this->mcpKey($this->mcpUser());
        $this->killDatabaseConnection(); // gone before the first message: the first check of the key must not end the program
        TestRevokingStream::$lines = ["{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n", "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n", "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"ping\"}\n"];
        $kills = 0;
        TestRevokingStream::$beforeSecond = function () use (&$kills): void {
            ++$kills;
            $this->killDatabaseConnection(); // and again in the middle of the work
        };
        if (!\in_array('testrevoking', \stream_get_wrappers(), true)) {
            \stream_wrapper_register('testrevoking', TestRevokingStream::class);
        }
        $in = \fopen('testrevoking://in', 'r');
        $out = \fopen('php://memory', 'w+');
        $err = \fopen('php://memory', 'w+');
        $code = self::getContainer()->get(McpStdio::class)->serve($key['plain'], $in, $out, $err);
        \rewind($out);
        \rewind($err);
        $answers = \array_map(static fn (string $l): array => \json_decode($l, true), \explode("\n", \rtrim((string) \stream_get_contents($out), "\n")));

        self::assertSame(0, $code, (string) \stream_get_contents($err));
        self::assertSame(1, $kills);
        self::assertSame([1, 2, 3], \array_column($answers, 'id'), 'every message was answered');
        self::assertSame('', (string) \stream_get_contents($err), 'nothing to tell the person: it healed by itself');
    }

    /** @param list<string> $lines */
    private function feed(array $lines): mixed
    {
        TestRevokingStream::$lines = $lines;
        TestRevokingStream::$beforeSecond = null;
        if (!\in_array('testrevoking', \stream_get_wrappers(), true)) {
            \stream_wrapper_register('testrevoking', TestRevokingStream::class);
        }

        return \fopen('testrevoking://in', 'r');
    }

    /** Where the connection goes: a port nobody listens on (the database is starting up and refuses connections) or the right one again. */
    private function pointConnectionAt(?int $port): void
    {
        $property = new \ReflectionProperty(\Doctrine\DBAL\Connection::class, 'params');
        $params = $property->getValue(self::getContainer()->get(\Doctrine\DBAL\Connection::class));
        self::$realPort ??= $params['port'] ?? 3306;
        $params['port'] = $port ?? self::$realPort;
        $property->setValue(self::getContainer()->get(\Doctrine\DBAL\Connection::class), $params);
    }

    private static ?int $realPort = null;

    private function closedPort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) \parse_url((string) \stream_socket_get_name($socket, false), \PHP_URL_PORT);
        \fclose($socket); // nobody listens there any more: a connection is refused, like a database that is not up yet

        return $port;
    }

    private function stdioWith(\Closure $pause, int $attempts): McpStdio
    {
        $c = self::getContainer();

        return new McpStdio(
            $c->get(\Itk\Cms\Mcp\McpServer::class),
            $c->get(McpSettings::class),
            $c->get(ApiTokenService::class),
            $c->get(\Itk\Cms\Mcp\McpContextFactory::class),
            $c->get(\Doctrine\ORM\EntityManagerInterface::class),
            $c->get('doctrine'),
            new \Psr\Log\NullLogger(),
            $pause,
            $attempts,
        );
    }

    #[Test]
    public function aDatabaseThatRefusesConnectionsForAWhileIsWaitedOutAndTheNextMessageIsAnswered(): void
    {
        $key = $this->mcpKey($this->mcpUser());
        $in = $this->feed(["{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n", "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n"]);
        $pauses = 0;
        $stdio = $this->stdioWith(function (int $seconds) use (&$pauses): void {
            if (3 === ++$pauses) {
                $this->pointConnectionAt(null); // the database is back
            }
        }, 10);
        // lost (the server closed it), then refused (the server is restarting), then back
        TestRevokingStream::$beforeSecond = function (): void {
            $this->killDatabaseConnection();
            $this->pointConnectionAt($this->closedPort());
        };
        $out = \fopen('php://memory', 'w+');
        $err = \fopen('php://memory', 'w+');
        try {
            $code = $stdio->serve($key['plain'], $in, $out, $err);
        } finally {
            $this->pointConnectionAt(null);
        }
        \rewind($out);
        \rewind($err);
        $answers = \array_map(static fn (string $l): array => \json_decode($l, true), \explode("\n", \rtrim((string) \stream_get_contents($out), "\n")));

        self::assertSame(0, $code, (string) \stream_get_contents($err));
        self::assertGreaterThanOrEqual(3, $pauses, 'it waited for the database');
        self::assertSame([1, 2], \array_column($answers, 'id'), 'both messages were answered');
        self::assertSame('', (string) \stream_get_contents($err));
    }

    #[Test]
    public function aDatabaseThatDoesNotComeBackStopsTheProgramWithAMessageNotWithAnException(): void
    {
        $key = $this->mcpKey($this->mcpUser());
        $in = $this->feed(["{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n", "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n"]);
        $pauses = 0;
        $stdio = $this->stdioWith(static function () use (&$pauses): void {
            ++$pauses;
        }, 4);
        TestRevokingStream::$beforeSecond = function (): void {
            $this->killDatabaseConnection();
            $this->pointConnectionAt($this->closedPort());
        };
        $out = \fopen('php://memory', 'w+');
        $err = \fopen('php://memory', 'w+');
        try {
            $code = $stdio->serve($key['plain'], $in, $out, $err);
        } finally {
            $this->pointConnectionAt(null);
        }
        \rewind($out);
        \rewind($err);
        $lines = \explode("\n", \rtrim((string) \stream_get_contents($out), "\n"));

        self::assertSame(1, $code);
        self::assertCount(1, $lines, 'the first message was answered, the second was not');
        self::assertStringContainsString('did not come back', (string) \stream_get_contents($err));
        self::assertSame(2, $pauses, 'the first retry is at once, the others wait');
    }

    /** The next queries of the program fail with the given error of PostgreSQL: what a driver answers while the database goes down, which no pg_terminate_backend and no closed port can show. */
    private function failNextQueries(int $times, int $code, ?string $state): TestFailingDriver
    {
        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $property = new \ReflectionProperty(\Doctrine\DBAL\Connection::class, 'driver');
        $driver = new TestFailingDriver($property->getValue($connection), $times, $code, $state);
        $property->setValue($connection, $driver);
        $connection->close(); // the next query connects through it

        return $driver;
    }

    private function realDriverAgain(TestFailingDriver $driver): void
    {
        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);
        (new \ReflectionProperty(\Doctrine\DBAL\Connection::class, 'driver'))->setValue($connection, $driver->real);
        $connection->close();
    }

    /** @return iterable<string, array{0: int, 1: ?string}> */
    public static function errorsOfADatabaseThatGoesDown(): iterable
    {
        yield 'the server is shutting down' => [7, '57P01'];
        yield 'the server is starting up' => [7, '57P03'];
        yield 'the server crashed' => [7, '57P02'];
        yield 'any other error of the connection class' => [7, '08001'];
    }

    /** DBAL turns none of the 57P states into ConnectionException: to the check of the key they are plain driver errors, and the process used to die with the exception. */
    #[Test]
    #[DataProvider('errorsOfADatabaseThatGoesDown')]
    public function anErrorOfADatabaseThatIsGoingDownIsWaitedOutToo(int $code, ?string $state): void
    {
        $key = $this->mcpKey($this->mcpUser());
        $in = $this->feed(["{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n", "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n"]);
        $pauses = 0;
        $stdio = $this->stdioWith(static function () use (&$pauses): void {
            ++$pauses;
        }, 10);
        $failing = null;
        TestRevokingStream::$beforeSecond = function () use (&$failing, $code, $state): void {
            $failing = $this->failNextQueries(3, $code, $state);
        };
        $out = \fopen('php://memory', 'w+');
        $err = \fopen('php://memory', 'w+');
        try {
            $result = $stdio->serve($key['plain'], $in, $out, $err);
        } finally {
            if (null !== $failing) {
                $this->realDriverAgain($failing);
            }
        }
        \rewind($out);
        \rewind($err);
        $answers = \array_map(static fn (string $l): array => \json_decode($l, true), \explode("\n", \rtrim((string) \stream_get_contents($out), "\n")));

        self::assertSame(0, $result, (string) \stream_get_contents($err));
        self::assertSame(3, $failing->thrown, 'every failure went through the check of the key');
        self::assertSame(2, $pauses, 'the first retry is at once, the others wait');
        self::assertSame([1, 2], \array_column($answers, 'id'), 'both messages were answered');
        self::assertSame('', (string) \stream_get_contents($err));
    }

    #[Test]
    public function anErrorThatHasNothingToDoWithTheConnectionIsNotRetried(): void
    {
        $key = $this->mcpKey($this->mcpUser());
        $in = $this->feed(["{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n", "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n"]);
        $stdio = $this->stdioWith(static function (): void {
            self::fail('there is nothing to wait for');
        }, 10);
        $failing = null;
        TestRevokingStream::$beforeSecond = function () use (&$failing): void {
            $failing = $this->failNextQueries(5, 1054, '42S22');
        };
        try {
            $stdio->serve($key['plain'], $in, \fopen('php://memory', 'w+'), \fopen('php://memory', 'w+'));
            self::fail('the error goes up as it did');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertSame(1, $failing->thrown, 'one try, no more');
        } finally {
            $this->realDriverAgain($failing);
        }
    }

    #[Test]
    public function aManagerThatAFailedSaveClosedIsReplacedBeforeTheNextMessage(): void
    {
        $key = $this->mcpKey($this->mcpUser());
        $in = $this->feed(["{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n", "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n"]);
        TestRevokingStream::$beforeSecond = static function (): void {
            self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->close();
        };
        $out = \fopen('php://memory', 'w+');
        $err = \fopen('php://memory', 'w+');
        $code = self::getContainer()->get(McpStdio::class)->serve($key['plain'], $in, $out, $err);
        \rewind($out);
        \rewind($err);
        $answers = \array_map(static fn (string $l): array => \json_decode($l, true), \explode("\n", \rtrim((string) \stream_get_contents($out), "\n")));

        self::assertSame(0, $code, (string) \stream_get_contents($err));
        self::assertSame([1, 2], \array_column($answers, 'id'));
        self::assertTrue(self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->isOpen());
    }

    #[Test]
    public function aRefusedKeyIsInTheAuditLogToo(): void
    {
        $lines = [];
        $logger = new class($lines) extends \Psr\Log\AbstractLogger {
            /** @param list<array{string, string, array<string, mixed>}> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message, $context];
            }
        };
        $c = self::getContainer();
        $stdio = new McpStdio($c->get(\Itk\Cms\Mcp\McpServer::class), $c->get(McpSettings::class), $c->get(ApiTokenService::class), $c->get(\Itk\Cms\Mcp\McpContextFactory::class), $c->get(\Doctrine\ORM\EntityManagerInterface::class), $c->get('doctrine'), $logger);
        $key = $this->mcpKey($this->mcpUser());
        $c->get(ApiTokenService::class)->revoke($c->get(ApiTokenService::class)->find($key['token']->id));
        $in = $this->feed(["{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n"]);
        $code = $stdio->serve($key['plain'], $in, \fopen('php://memory', 'w+'), \fopen('php://memory', 'w+'));

        self::assertSame(1, $code);
        self::assertCount(1, $lines);
        self::assertSame('warning', $lines[0][0]);
        self::assertSame('MCP sign-in refused: {reason}', $lines[0][1]);
        self::assertSame('revoked', $lines[0][2]['reason']);
        self::assertSame('stdio', $lines[0][2]['transport']);
        self::assertSame($key['token']->id, $lines[0][2]['token']);
        self::assertStringNotContainsString($key['plain'], \json_encode($lines));
    }

    #[Test]
    public function theCommandReadsTheKeyFromTheEnvironmentOrTheOptionAndComplainsWithoutOne(): void
    {
        $command = (new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel))->find('itk:mcp');
        $t = new CommandTester($command);
        $code = $t->execute([], ['capture_stderr_separately' => true]);
        self::assertSame(1, $code, 'no key');
        self::assertSame('', $t->getDisplay(), 'nothing on the output');
        self::assertStringContainsString('No key', $t->getErrorOutput());
        self::assertTrue($command->getDefinition()->hasOption('token'));
    }
}

/** @internal an error of the driver with the code and the SQLSTATE of PostgreSQL */
final class TestDriverError extends \Doctrine\DBAL\Driver\AbstractException
{
}

/** @internal a driver whose next queries fail, then it works again */
final class TestFailingDriver extends \Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware
{
    public int $thrown = 0;

    public function __construct(public readonly \Doctrine\DBAL\Driver $real, private int $times, private readonly int $code, private readonly ?string $state)
    {
        parent::__construct($real);
    }

    public function connect(#[\SensitiveParameter] array $params): \Doctrine\DBAL\Driver\Connection
    {
        $failing = $this;

        return new class(parent::connect($params), $failing) extends \Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware {
            public function __construct(\Doctrine\DBAL\Driver\Connection $wrapped, private readonly TestFailingDriver $failing)
            {
                parent::__construct($wrapped);
            }

            public function prepare(string $sql): \Doctrine\DBAL\Driver\Statement
            {
                $this->failing->query();

                return parent::prepare($sql);
            }

            public function query(string $sql): \Doctrine\DBAL\Driver\Result
            {
                $this->failing->query();

                return parent::query($sql);
            }
        };
    }

    /** @throws TestDriverError while there are failures left */
    public function query(): void
    {
        if ($this->times > 0) {
            --$this->times;
            ++$this->thrown;

            throw new TestDriverError('The database says no', $this->state, $this->code);
        }
    }
}

/** @internal a stream that calls a function before it gives the second line */
final class TestRevokingStream
{
    /** @var list<string> */
    public static array $lines = [];
    public static ?\Closure $beforeSecond = null;
    /** @var resource */
    public $context;
    private int $i = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->i >= \count(self::$lines)) {
            return '';
        }
        if (1 === $this->i && null !== self::$beforeSecond) {
            (self::$beforeSecond)();
        }

        return self::$lines[$this->i++];
    }

    public function stream_eof(): bool
    {
        return $this->i >= \count(self::$lines);
    }

    /** @return array<mixed> */
    public function stream_stat(): array
    {
        return [];
    }
}
