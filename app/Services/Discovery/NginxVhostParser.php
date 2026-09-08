<?php

namespace App\Services\Discovery;

/**
 * A deliberately small nginx config reader.
 *
 * It extracts the handful of directives JetGrid needs to display a site and
 * nothing else. It does not attempt to model nginx semantics, and it never
 * rewrites what it reads — the parsed output is used for display and for
 * matching a vhost to a webroot, never to regenerate the file.
 */
class NginxVhostParser
{
    /** @return list<array{server_names:list<string>, root:?string, listen:list<string>, ssl_certificate:?string, is_default:bool}> */
    public function parse(string $contents): array
    {
        $servers = [];

        foreach ($this->serverBlocks($contents) as $block) {
            $names = $this->directive($block, 'server_name');
            $root = $this->directive($block, 'root')[0] ?? null;
            $listen = $this->directive($block, 'listen');
            $cert = $this->directive($block, 'ssl_certificate')[0] ?? null;

            $servers[] = [
                'server_names' => array_values(array_filter(
                    $names,
                    static fn (string $n): bool => $n !== '_' && $n !== ''
                )),
                'root' => $root !== null ? rtrim($root, '/;') : null,
                'listen' => $listen,
                'ssl_certificate' => $cert,
                'is_default' => in_array('_', $names, true)
                    || (bool) preg_grep('/default_server/', $listen),
            ];
        }

        return $servers;
    }

    /**
     * Extract each top-level `server { ... }` body by brace counting. Regex
     * cannot do this correctly once a server block contains location blocks.
     *
     * @return list<string>
     */
    private function serverBlocks(string $contents): array
    {
        $contents = $this->stripComments($contents);
        $blocks = [];
        $length = strlen($contents);
        $offset = 0;

        while (($pos = $this->findServerKeyword($contents, $offset)) !== null) {
            $brace = strpos($contents, '{', $pos);

            if ($brace === false) {
                break;
            }

            $depth = 0;
            $start = $brace;

            for ($i = $brace; $i < $length; $i++) {
                if ($contents[$i] === '{') {
                    $depth++;
                } elseif ($contents[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $blocks[] = substr($contents, $start + 1, $i - $start - 1);
                        $offset = $i;

                        continue 2;
                    }
                }
            }

            // Unbalanced braces: stop rather than guess.
            break;
        }

        return $blocks;
    }

    private function findServerKeyword(string $contents, int $offset): ?int
    {
        if (preg_match('/\bserver\s*\{/', $contents, $m, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            return null;
        }

        return $m[0][1];
    }

    /**
     * All values of a directive, at any depth inside the given block.
     *
     * @return list<string>
     */
    private function directive(string $block, string $name): array
    {
        if (preg_match_all('/^\s*'.preg_quote($name, '/').'\s+([^;]+);/mi', $block, $matches) === 0) {
            return [];
        }

        $values = [];

        foreach ($matches[1] as $raw) {
            foreach (preg_split('/\s+/', trim($raw)) ?: [] as $value) {
                if ($value !== '') {
                    $values[] = trim($value, '"\'');
                }
            }
        }

        return $values;
    }

    private function stripComments(string $contents): string
    {
        return preg_replace('/(^|\s)#[^\n]*/m', '', $contents) ?? $contents;
    }
}
