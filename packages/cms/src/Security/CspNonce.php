<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * The site sets this trusted per-response value and uses the same value in its CSP and itk_stylesheets({nonce: …}).
 *
 * @internal
 */
final class CspNonce
{
    public const ATTRIBUTE = 'csp_nonce';

    public static function fromRequest(Request $request): ?string
    {
        $nonce = $request->attributes->get(self::ATTRIBUTE);

        return \is_string($nonce) && '' !== $nonce ? $nonce : null;
    }
}
