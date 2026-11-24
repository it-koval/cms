<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\I18n\I18n;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The stdio transport: one JSON-RPC message per line on the input, one answer per line on the output, nothing else on the output.
 * Messages for the person who started the program (why it stopped) go to the error stream. The key is checked again for every message, so revoking it,
 * blocking its owner or switching MCP off stops a running assistant at its next call.
 * The program may run for days: when the database closes the connection (a restart, wait_timeout) the check of the key closes it on our side too and tries again over a new one;
 * while the database is starting up or stopping it refuses connections: both are waited out the same way (a pause between the tries, about twenty seconds in all).
 * Only a database that does not come back stops the program (with a message, not with an exception). A key refused here is a line of the audit log (channel itk_mcp) as well as a message.
 *
 * @internal
 */
#[WithMonologChannel('itk_mcp')]
final class McpStdio
{
    /** How many times the check of the key is tried when the database cannot be reached (a lost connection, a refused one). */
    public const ATTEMPTS = 20;

    /** @var \Closure(int): void waits the given number of seconds (a test passes a quick one) */
    private readonly \Closure $pause;

    public function __construct(
        private readonly McpServer $server,
        private readonly McpSettings $settings,
        private readonly ApiTokenService $tokens,
        private readonly McpContextFactory $contexts,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        ?\Closure $pause = null,
        private readonly int $attempts = self::ATTEMPTS,
        #[Autowire(service: 'services_resetter')]
        private readonly ?ResetInterface $resetter = null,
    ) {
        $this->pause = $pause ?? static function (int $seconds): void {
            \sleep($seconds);
        };
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     *
     * @return int exit code: 0 when the input ended, 1 when the key or the switch refused
     */
    public function serve(string $plainToken, $in, $out, $err): int
    {
        if (!$this->open($plainToken, $err)) {
            return 1;
        }
        while (false !== ($line = \fgets($in))) {
            $line = \trim($line);
            if ('' === $line) {
                continue;
            }
            if (!$this->open($plainToken, $err, $context)) {
                return 1;
            }
            $answer = $this->server->handle($line, $context);
            if (null !== $answer) {
                \fwrite($out, $answer . "\n");
                \fflush($out);
            }
        }

        return 0;
    }

    /**
     * @param resource $err
     */
    private function open(string $plain, $err, ?McpContext &$context = null): bool
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                return $this->check($plain, $err, $context);
            } catch (DriverException $e) {
                if (!self::isConnectionFailure($e)) {
                    throw $e;
                }
                $this->em->getConnection()->close(); // the next query opens a new connection
                if ($this->em->isOpen()) {
                    $this->em->clear();
                }
                if ($attempt >= $this->attempts) {
                    \fwrite($err, I18n::t('ops.mcp_db_lost') . "\n");

                    return false;
                }
                if ($attempt > 1) {
                    ($this->pause)(1); // the first retry is at once (a closed idle connection); a database that is restarting needs time
                }
            }
        }
    }

    private static function isConnectionFailure(DriverException $e): bool
    {
        $state = (string) $e->getSQLState();

        return $e instanceof ConnectionException || \str_starts_with($state, '08') || \str_starts_with($state, '57P');
    }

    /**
     * @param resource $err
     *
     * @throws DriverException
     */
    private function check(string $plain, $err, ?McpContext &$context): bool
    {
        $this->resetter?->reset();
        if (!$this->em->isOpen()) {
            $this->registry->resetManager(); // a failed flush closes the manager for good; a program that runs for days starts with a new one
        }
        $this->settings->forget();
        $this->em->clear();
        if (!$this->settings->isEnabled()) {
            \fwrite($err, I18n::t('ops.mcp_stdio_off') . "\n");

            return false;
        }
        try {
            $token = $this->tokens->authenticate($plain);
        } catch (ApiTokenException $e) {
            $this->logger->warning('MCP sign-in refused: {reason}', ['reason' => $e->reason, 'token' => $e->token?->id, 'user' => $e->token?->user->id, 'transport' => 'stdio']);
            \fwrite($err, $e->getMessage() . ".\n");

            return false;
        }
        $this->tokens->touch($token);
        $context = $this->contexts->signIn($token, 'stdio');

        return true;
    }
}
