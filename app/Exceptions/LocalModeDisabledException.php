<?php

namespace App\Exceptions;

/**
 * L0. Raised whenever local discovery or local process control is reached and
 * the four-check gate has not passed.
 *
 * It carries the failed check names because the alternative — a bare "forbidden"
 * — is the thing that gets a gate disabled out of frustration rather than fixed.
 */
class LocalModeDisabledException extends JetGridSafetyException
{
    /** @param list<string> $failed */
    public function __construct(public readonly array $failed, public readonly string $action = 'local control')
    {
        parent::__construct(
            'Refused ['.$action.']: local mode gate failed — '.implode(', ', $failed).'.'
        );
    }

    public function userMessage(): string
    {
        return 'Local mode is off. JetGrid will not start, stop or scan local projects unless every one of its four checks passes. Failing: '
            .implode(', ', $this->failed).'.';
    }
}
