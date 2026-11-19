<?php
declare(strict_types=1);

/**
 * The routing data Xray's Rule mode reads (geoip.dat: which addresses are of which country; geosite.dat: which domains
 * are of which kind of site). The app is built with a copy; this fetches newer ones into the folder Xray reads them from.
 * A running connection has read its own: a new copy is taken the next time it connects.
 */
final class RoutingData
{
    public const SOURCES = [
        'geoip.dat' => 'https://github.com/v2fly/geoip/releases/latest/download/geoip.dat',
        'geosite.dat' => 'https://github.com/v2fly/domain-list-community/releases/latest/download/dlc.dat',
    ];
    /** What a file smaller than this is not: the data, but an error page or nothing. */
    private const MIN_BYTES = 100_000;
    private const MARKER = '.updated';

    public function __construct(private readonly string $dir) {}

    /** When it was last updated here, as a date; null while the copy that came with the app is the one in use. */
    public function updatedOn(): ?string
    {
        $text = is_file("{$this->dir}/" . self::MARKER) ? trim((string) file_get_contents("{$this->dir}/" . self::MARKER)) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null;
    }

    /**
     * Fetches both files and puts them in place only when both are good, so one that failed leaves the pair as it was.
     * @param \Closure(string): string $fetch the body of a URL, throwing when it cannot be had
     * @return ?string null when it is done, else what went wrong
     */
    public function update(\Closure $fetch, string $today): ?string
    {
        $bodies = [];
        foreach (self::SOURCES as $name => $url) {
            try {
                $body = $fetch($url);
            } catch (\Throwable $e) {
                return "$name could not be fetched: " . $e->getMessage();
            }
            if (strlen($body) < self::MIN_BYTES) {
                return "$name is not the routing data (" . strlen($body) . ' bytes).';
            }
            $bodies[$name] = $body;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true)) {
            return "{$this->dir} cannot be made.";
        }
        $tmp = [];
        foreach ($bodies as $name => $body) {
            $tmp[$name] = "{$this->dir}/$name.new";
            if (@file_put_contents($tmp[$name], $body) !== strlen($body)) {
                array_map('unlink', array_filter($tmp, 'is_file'));
                return "$name could not be written.";
            }
        }
        foreach ($tmp as $name => $path) {
            if (!@rename($path, "{$this->dir}/$name")) {
                return "$name could not be put in place.";
            }
        }
        @file_put_contents("{$this->dir}/" . self::MARKER, $today);
        return null;
    }
}
