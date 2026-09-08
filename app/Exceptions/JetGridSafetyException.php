<?php

namespace App\Exceptions;

use RuntimeException;

/** Base for every refusal produced by JetGrid's safety layer. */
abstract class JetGridSafetyException extends RuntimeException
{
    abstract public function userMessage(): string;
}
