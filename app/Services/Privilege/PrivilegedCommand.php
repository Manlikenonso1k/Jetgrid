<?php

namespace App\Services\Privilege;

use App\Exceptions\InvalidCommandArgumentException;

/**
 * An immutable, whitelisted command definition.
 *
 * argv is stored as an ARRAY and is executed as an array — it is never passed
 * through a shell, so no argument can inject a second command. Placeholders are
 * written as {name} and every placeholder must have a validating pattern.
 */
final class PrivilegedCommand
{
    /**
     * @param  list<string>  $argv
     * @param  array<string,string>  $patterns  placeholder => PCRE pattern
     */
    public function __construct(
        public readonly string $key,
        public readonly array $argv,
        public readonly array $patterns,
        public readonly bool $isWrite,
        public readonly bool $needsRoot,
        public readonly string $justification,
    ) {}

    /** @param array<string,string|int> $args */
    public function bind(array $args): BoundCommand
    {
        $bound = [];

        foreach ($this->argv as $token) {
            $bound[] = preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($args) {
                $name = $m[1];

                if (! array_key_exists($name, $args)) {
                    throw new InvalidCommandArgumentException($name, '<missing>');
                }

                $value = (string) $args[$name];

                if (! isset($this->patterns[$name])) {
                    // A placeholder with no pattern would be an unvalidated hole.
                    throw new InvalidCommandArgumentException($name, $value);
                }

                if (! preg_match($this->patterns[$name], $value)) {
                    throw new InvalidCommandArgumentException($name, $value);
                }

                return $value;
            }, $token);
        }

        return new BoundCommand($this, $bound, $args);
    }

    /**
     * The sudoers line(s) for this command.
     *
     * Returns a LIST because a placeholder with a small closed set of legal
     * values (a PHP version, tcp/udp) is expanded into one explicit line per
     * value rather than left as a wildcard. Everything else uses the narrowest
     * glob SudoersGlobs can express — crucially keeping literal prefixes like
     * `jetgrid-`, which a naive `*` would throw away.
     *
     * @return list<string>
     */
    public function sudoersPatterns(): array
    {
        if (! $this->needsRoot) {
            return [];
        }

        $tokens = $this->argv;

        // Drop the leading `sudo` — sudoers lines describe the command being run.
        if (($tokens[0] ?? null) === 'sudo') {
            array_shift($tokens);
        }

        $globs = SudoersGlobs::map();
        $lines = [''];

        foreach ($tokens as $token) {
            $variants = $this->expandToken($token, $globs);
            $next = [];

            foreach ($lines as $line) {
                foreach ($variants as $variant) {
                    $next[] = $line === '' ? $variant : $line.' '.$variant;
                }
            }

            $lines = $next;

            // A cartesian blow-up would mean a set that is too big to enumerate;
            // fall back rather than emitting hundreds of lines.
            if (count($lines) > 24) {
                return [$this->wildcardLine($tokens)];
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * Placeholders in this command that could not be narrowed, with reasons.
     *
     * @return array<string,string>
     */
    public function broadArguments(): array
    {
        $globs = SudoersGlobs::map();
        $reasons = SudoersGlobs::broadReasons();
        $broad = [];

        foreach ($this->patterns as $name => $pattern) {
            if (! isset($globs[$pattern])) {
                $broad[$name] = $reasons[$pattern] ?? 'No narrower sudoers expression is available for this argument.';
            }
        }

        return $broad;
    }

    /**
     * One argv token may contain literal text around a placeholder — for
     * example `php{phpver}-fpm` or `{port}/{proto}`. Substituting the whole
     * token with `*` would lose that literal text, so each placeholder is
     * replaced in place instead.
     *
     * @param  array<string,string|list<string>>  $globs
     * @return list<string>
     */
    private function expandToken(string $token, array $globs): array
    {
        if (! str_contains($token, '{')) {
            return [$token];
        }

        $results = [$token];

        preg_match_all('/\{(\w+)\}/', $token, $matches);

        foreach ($matches[1] as $name) {
            $pattern = $this->patterns[$name] ?? null;
            $glob = $pattern !== null ? ($globs[$pattern] ?? '*') : '*';
            $values = is_array($glob) ? $glob : [$glob];

            $expanded = [];

            foreach ($results as $result) {
                foreach ($values as $value) {
                    $expanded[] = str_replace('{'.$name.'}', $value, $result);
                }
            }

            $results = $expanded;
        }

        return $results;
    }

    /** @param list<string> $tokens */
    private function wildcardLine(array $tokens): string
    {
        return implode(' ', array_map(
            static fn (string $t): string => preg_replace('/\{(\w+)\}/', '*', $t),
            $tokens,
        ));
    }
}
