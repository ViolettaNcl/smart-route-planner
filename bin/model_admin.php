<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\ML\ModelRegistry;
use App\Support\RuntimeStorage;

$registry = new ModelRegistry(RuntimeStorage::path('model_versions'));
$command = $argv[1] ?? 'status';

if ($command === 'status') {
    echo json_encode($registry->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($command === 'rollback') {
    $version = $argv[2] ?? '';
    if ($version === '' || !$registry->rollback($version, RuntimeStorage::path('mlp_weights.json'))) {
        fwrite(STDERR, "Rollback failed. Usage: php bin/model_admin.php rollback mlp-xxxxxxxx\n");
        exit(2);
    }
    echo "Rolled back to {$version}.\n";
    exit(0);
}

fwrite(STDERR, "Usage: php bin/model_admin.php status|rollback <version>\n");
exit(2);
