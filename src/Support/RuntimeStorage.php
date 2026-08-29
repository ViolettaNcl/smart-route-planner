<?php

namespace App\Support;

/**
 * Writable runtime storage shared by cache, rate limiting, logs and demo
 * model state. Vercel Functions expose an ephemeral /tmp filesystem, while
 * local/Docker deployments keep using the repository's var/ directory.
 */
final class RuntimeStorage
{
    private static ?string $baseDir = null;

    public static function baseDir(): string
    {
        if (self::$baseDir !== null) {
            return self::$baseDir;
        }

        $configured = trim((string) (getenv('APP_RUNTIME_DIR') ?: ''));
        if ($configured !== '') {
            $baseDir = rtrim($configured, '/\\');
        } elseif (getenv('VERCEL') || getenv('AWS_LAMBDA_FUNCTION_NAME')) {
            $baseDir = rtrim(sys_get_temp_dir(), '/\\') . '/smart-route-planner';
        } else {
            $baseDir = dirname(__DIR__, 2) . '/var';
        }

        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }

        self::$baseDir = $baseDir;

        return $baseDir;
    }

    public static function path(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException('Runtime path must be a safe relative path.');
        }

        $path = self::baseDir() . '/' . $relativePath;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $path;
    }

    public static function modelWeightsPath(): string
    {
        $runtimePath = self::path('mlp_weights.json');
        if (is_file($runtimePath)) {
            return $runtimePath;
        }

        $baselinePath = dirname(__DIR__) . '/ML/mlp_weights.json';
        if (is_file($baselinePath) && @copy($baselinePath, $runtimePath)) {
            return $runtimePath;
        }

        return $baselinePath;
    }

    public static function resetModelWeights(): bool
    {
        $baselinePath = dirname(__DIR__) . '/ML/mlp_weights.trained.json';

        return is_file($baselinePath) && @copy($baselinePath, self::path('mlp_weights.json'));
    }
}
