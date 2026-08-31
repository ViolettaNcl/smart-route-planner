<?php

namespace App\ML;

/**
 * Small file-backed model registry for reviewed CLI releases. Web requests
 * never call promote() or rollback(); those operations are intentionally kept
 * outside the public HTTP surface.
 */
final class ModelRegistry
{
    public function __construct(private string $directory)
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
    }

    /** @param array<string, mixed> $weights @param array<string, mixed> $metrics */
    public function promote(array $weights, array $metrics, string $activeWeightsPath): string
    {
        $encoded = json_encode($weights, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Could not encode candidate weights.');
        }
        $version = 'mlp-' . substr(hash('sha256', $encoded), 0, 8);
        $versionPath = $this->directory . '/' . $version . '.json';
        if (file_put_contents($versionPath, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Could not persist the candidate model.');
        }
        if (!@copy($versionPath, $activeWeightsPath)) {
            throw new \RuntimeException('Could not promote the candidate model.');
        }

        $registry = $this->readRegistry();
        $registry['active'] = $version;
        $registry['releases'][$version] = [
            'version' => $version,
            'promoted_at' => gmdate('c'),
            'metrics' => $metrics,
        ];
        $this->writeRegistry($registry);

        return $version;
    }

    public function rollback(string $version, string $activeWeightsPath): bool
    {
        if (preg_match('/^mlp-[a-f0-9]{8}$/', $version) !== 1) {
            return false;
        }
        $path = $this->directory . '/' . $version . '.json';
        if (!is_file($path) || !@copy($path, $activeWeightsPath)) {
            return false;
        }
        $registry = $this->readRegistry();
        $registry['active'] = $version;
        $registry['rollback_at'] = gmdate('c');
        $this->writeRegistry($registry);

        return true;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->readRegistry();
    }

    /** @return array<string, mixed> */
    private function readRegistry(): array
    {
        $path = $this->directory . '/registry.json';
        if (!is_file($path)) {
            return ['active' => null, 'releases' => []];
        }
        $registry = json_decode((string) file_get_contents($path), true);

        return is_array($registry) ? $registry : ['active' => null, 'releases' => []];
    }

    /** @param array<string, mixed> $registry */
    private function writeRegistry(array $registry): void
    {
        $encoded = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || file_put_contents($this->directory . '/registry.json', $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Could not update the model registry.');
        }
    }
}
