<?php

namespace App\Enums;

/**
 * A check that could not be performed is Unknown, never Ok.
 *
 * This is the distinction the suspension incident turned on: a lookup that
 * fails tells you nothing about the domain, and recording it as healthy is how
 * monitoring reports green while the site is unreachable.
 */
enum CheckStatus: string
{
    case Ok = 'ok';
    case Failing = 'failing';
    case Unknown = 'unknown';
}
