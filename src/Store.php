<?php
declare(strict_types=1);

use MyPHP\{App, PathName};

/**
 * What the app keeps between runs (profiles, favorites, history, settings): one JSON file in the app's data folder,
 * written whole each time. Without a path (the tests) nothing is read or written.
 */
final class Store
{
    public function __construct(private readonly ?string $path = null) {}

    /** The store in the app's data folder (named after the app: set App::setName first). */
    public static function inUserData(): self
    {
        return new self(App::path(PathName::UserData) . '/linking.json');
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        if ($this->path === null || !is_file($this->path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($this->path), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        if ($this->path === null) {
            return;
        }
        $tmp = $this->path . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $this->path); // a write cut short leaves the file as it was
        }
    }
}
