<?php
declare(strict_types=1);

/**
 * The part of YAML a Clash subscription's proxies are written in, and no more: block mappings and sequences by
 * indentation, flow mappings and sequences ({a: 1, b: [x, y]}), quoted and plain scalars, comments. No anchors, tags,
 * multi-line scalars or documents: a file that needs them is not read (null), not read wrongly.
 */
final class MiniYaml
{
    /** @return mixed the document, or null when it is not in what this reads */
    public static function parse(string $text): mixed
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $raw) {
            $line = rtrim(self::stripComment(str_replace("\t", '  ', $raw)));
            if (trim($line) === '' || trim($line) === '---') {
                continue;
            }
            $lines[] = [strlen($line) - strlen(ltrim($line)), ltrim($line)];
        }
        if ($lines === []) {
            return null;
        }
        try {
            $i = 0;
            $value = self::block($lines, $i, $lines[0][0]);
            return $i === count($lines) ? $value : null;
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** A `#` that begins a comment: at the start or after a space, outside quotes. */
    private static function stripComment(string $line): string
    {
        $quote = '';
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $c = $line[$i];
            if ($quote !== '') {
                if ($c === '\\' && $quote === '"') { $i++; }
                elseif ($c === $quote) { $quote = ''; }
            } elseif (($c === '"' || $c === "'") && ($i === 0 || strpos(" [{,:-", $line[$i - 1]) !== false)) {
                $quote = $c;
            } elseif ($c === '#' && ($i === 0 || $line[$i - 1] === ' ')) {
                return substr($line, 0, $i);
            }
        }
        return $line;
    }

    /** @param list<array{0: int, 1: string}> $lines */
    private static function block(array &$lines, int &$i, int $indent): mixed
    {
        $text = $lines[$i][1];
        return $text === '-' || str_starts_with($text, '- ') ? self::sequence($lines, $i, $indent) : self::mapping($lines, $i, $indent);
    }

    /** @param list<array{0: int, 1: string}> $lines */
    private static function sequence(array &$lines, int &$i, int $indent): array
    {
        $out = [];
        while ($i < count($lines) && $lines[$i][0] === $indent && ($lines[$i][1] === '-' || str_starts_with($lines[$i][1], '- '))) {
            $rest = ltrim(substr($lines[$i][1], 1));
            if ($rest === '') {
                $i++;
                $out[] = $i < count($lines) && $lines[$i][0] > $indent ? self::block($lines, $i, $lines[$i][0]) : null;
            } elseif ($rest[0] !== '{' && $rest[0] !== '[' && $rest[0] !== '"' && $rest[0] !== "'" && self::splitKey($rest) !== null) {
                // "- key: value": a mapping whose first entry is on this line, the others under it
                $inner = $indent + (strlen($lines[$i][1]) - strlen($rest));
                $lines[$i] = [$inner, $rest];
                $out[] = self::mapping($lines, $i, $inner);
            } else {
                $i++;
                $out[] = self::inline($rest);
            }
        }
        return $out;
    }

    /** @param list<array{0: int, 1: string}> $lines */
    private static function mapping(array &$lines, int &$i, int $indent): array
    {
        $out = [];
        while ($i < count($lines) && $lines[$i][0] === $indent) {
            $entry = self::splitKey($lines[$i][1]);
            if ($entry === null) {
                throw new \RuntimeException('not a mapping entry: ' . $lines[$i][1]);
            }
            [$key, $value] = $entry;
            $i++;
            if ($value === '') {
                if ($i < count($lines) && $lines[$i][0] > $indent) {
                    $out[$key] = self::block($lines, $i, $lines[$i][0]);
                } elseif ($i < count($lines) && $lines[$i][0] === $indent && ($lines[$i][1] === '-' || str_starts_with($lines[$i][1], '- '))) {
                    $out[$key] = self::sequence($lines, $i, $indent); // a sequence may sit at its key's own indent
                } else {
                    $out[$key] = null;
                }
            } else {
                $out[$key] = self::inline($value);
            }
        }
        if ($i < count($lines) && $lines[$i][0] > $indent) {
            throw new \RuntimeException('unexpected indentation: ' . $lines[$i][1]);
        }
        return $out;
    }

    /** @return ?array{0: string, 1: string} the key and what follows its colon, null when the line is no entry */
    private static function splitKey(string $text): ?array
    {
        if ($text !== '' && ($text[0] === '"' || $text[0] === "'")) {
            $end = self::quotedEnd($text, 0);
            $after = substr($text, $end);
            if ($after !== '' && $after[0] === ':' && ($after === ':' || $after[1] === ' ')) {
                return [self::quoted(substr($text, 0, $end)), trim(substr($after, 1))];
            }
            return null;
        }
        if (preg_match('/^([^\s\'"{\[][^:]*?|[^\s\'"{\[]):(?:\s+(.*))?$/', $text, $m) === 1) {
            return [trim($m[1]), trim($m[2] ?? '')];
        }
        return null;
    }

    private static function inline(string $text): mixed
    {
        $pos = 0;
        $value = self::flow($text, $pos, false);
        if (trim(substr($text, $pos)) !== '') {
            throw new \RuntimeException('trailing text: ' . substr($text, $pos));
        }
        return $value;
    }

    /** A flow value at $pos: {…}, […], a quoted string, or a plain scalar (to the next , } ] when inside a flow collection). */
    private static function flow(string $s, int &$pos, bool $inside): mixed
    {
        self::skip($s, $pos);
        $c = $s[$pos] ?? '';
        if ($c === '{') {
            $pos++;
            $map = [];
            while (true) {
                self::skip($s, $pos);
                if (($s[$pos] ?? '') === '}') { $pos++; return $map; }
                $key = ($s[$pos] ?? '') === '"' || ($s[$pos] ?? '') === "'" ? self::flow($s, $pos, true) : self::plainKey($s, $pos);
                self::skip($s, $pos);
                if (($s[$pos] ?? '') !== ':') { throw new \RuntimeException('a flow mapping needs ":"'); }
                $pos++;
                $map[(string) $key] = self::flow($s, $pos, true);
                self::skip($s, $pos);
                if (($s[$pos] ?? '') === ',') { $pos++; }
                elseif (($s[$pos] ?? '') !== '}') { throw new \RuntimeException('a flow mapping ends with "}"'); }
            }
        }
        if ($c === '[') {
            $pos++;
            $list = [];
            while (true) {
                self::skip($s, $pos);
                if (($s[$pos] ?? '') === ']') { $pos++; return $list; }
                $list[] = self::flow($s, $pos, true);
                self::skip($s, $pos);
                if (($s[$pos] ?? '') === ',') { $pos++; }
                elseif (($s[$pos] ?? '') !== ']') { throw new \RuntimeException('a flow sequence ends with "]"'); }
            }
        }
        if ($c === '"' || $c === "'") {
            $end = self::quotedEnd($s, $pos);
            $v = self::quoted(substr($s, $pos, $end - $pos));
            $pos = $end;
            return $v;
        }
        $start = $pos;
        while ($pos < strlen($s) && !($inside && strpos(',}]', $s[$pos]) !== false)) {
            $pos++;
        }
        return self::scalar(trim(substr($s, $start, $pos - $start)));
    }

    private static function plainKey(string $s, int &$pos): string
    {
        $start = $pos;
        while ($pos < strlen($s) && !($s[$pos] === ':' && (($s[$pos + 1] ?? ' ') === ' '))) {
            $pos++;
        }
        return trim(substr($s, $start, $pos - $start));
    }

    private static function skip(string $s, int &$pos): void
    {
        while ($pos < strlen($s) && ($s[$pos] === ' ' || $s[$pos] === "\t")) {
            $pos++;
        }
    }

    /** The offset after the quoted string that begins at $from. */
    private static function quotedEnd(string $s, int $from): int
    {
        $q = $s[$from];
        for ($i = $from + 1, $n = strlen($s); $i < $n; $i++) {
            if ($q === '"' && $s[$i] === '\\') { $i++; continue; }
            if ($s[$i] === $q) {
                if ($q === "'" && ($s[$i + 1] ?? '') === "'") { $i++; continue; } // '' is a quote in single quotes
                return $i + 1;
            }
        }
        throw new \RuntimeException('a quoted string is not closed');
    }

    private static function quoted(string $text): string
    {
        $inner = substr($text, 1, -1);
        if ($text[0] === "'") {
            return str_replace("''", "'", $inner);
        }
        $decoded = json_decode('"' . $inner . '"');
        return is_string($decoded) ? $decoded : $inner;
    }

    private static function scalar(string $text): mixed
    {
        return match (true) {
            $text === '' || $text === '~' || strtolower($text) === 'null' => null,
            strtolower($text) === 'true' => true,
            strtolower($text) === 'false' => false,
            preg_match('/^-?(0|[1-9]\d{0,14})$/', $text) === 1 => (int) $text,
            default => $text,
        };
    }
}
