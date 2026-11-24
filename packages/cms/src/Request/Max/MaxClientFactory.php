<?php

declare(strict_types=1);

namespace Itk\Cms\Request\Max;

use MaxMessenger\Bot\Bundle\Service\MaxApiConfigFactory;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\MaxApiConfig;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * Builds an admin connection without asking the server token's environment variable to exist.
 *
 * @internal
 */
final class MaxClientFactory
{
    public function __construct(private readonly ?ContainerBagInterface $parameters = null)
    {
    }

    public function create(#[\SensitiveParameter] string $token, ?MaxApiConfig $server = null): MaxApiClient
    {
        return new MaxApiClient($this->config($token, $server));
    }

    public function config(#[\SensitiveParameter] string $token, ?MaxApiConfig $server = null): MaxApiConfig
    {
        if (null !== $server) {
            return (clone $server)->setAccessToken($token)->setRetryAttempts([]);
        }

        $baseUrl = (string) $this->option('base_url', 'https://platform-api2.max.ru');
        $connectTimeout = (int) $this->option('connect_timeout', 5000);
        $timeout = (int) $this->option('timeout', 10000);
        $russianCa = $this->option('russian_trusted_ca', null);
        $russianCa = \is_bool($russianCa) ? $russianCa : null;
        if (\class_exists(MaxApiConfigFactory::class)) {
            return MaxApiConfigFactory::create($token, $baseUrl, $connectTimeout, $timeout, [], $russianCa);
        }

        $config = (new MaxApiConfig($token, null, $baseUrl))
            ->setConnectTimeout($connectTimeout)->setTimeout($timeout)->setRetryAttempts([]);
        if ($russianCa ?? 'platform-api2.max.ru' === \strtolower((string) \parse_url($baseUrl, \PHP_URL_HOST))) {
            $config->useRussianTrustedCaCertificates();
        }

        return $config;
    }

    private function option(string $key, mixed $default): mixed
    {
        $name = 'max_bot.' . $key;

        return $this->parameters?->has($name) ? $this->parameters->get($name) : $default;
    }
}
