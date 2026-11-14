<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\Entity\Request;

/** @internal a messenger that RequestDeliverer sends to in two steps: the network first, the request row after */
interface ChannelNotifier
{
    /** Can a request of this form be sent right now: the messenger is active and has a chat for the form? */
    public function reaches(string $form): bool;

    /** Sends the request and reports what happened; does not touch the request. */
    public function attempt(Request $r, bool $resend = false): ChannelOutcome;

    /** Writes the outcome into the request and announces it. Returns true when it needs no retry. */
    public function record(Request $r, ChannelOutcome $outcome): bool;
}
