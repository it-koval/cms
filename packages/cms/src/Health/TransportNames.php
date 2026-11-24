<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Symfony\Contracts\Service\ServiceProviderInterface;

/** Messenger registers each receiver by its configured name and by messenger.transport.<name>. */
final class TransportNames
{
    /** @return list<string> */
    public static function of(ServiceProviderInterface $locator): array
    {
        $names = \array_keys($locator->getProvidedServices());

        return \array_values(\array_map('strval', \array_filter(
            $names,
            static fn (string|int $name): bool => !\str_starts_with((string) $name, 'messenger.transport.') || !\in_array(\substr((string) $name, 20), $names, true),
        )));
    }
}
