<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $isProduction = (string)app_config('app.env', 'local') === 'production';
    $result = initialize_database(db(), !$isProduction);
    $productionSeeded = $isProduction ? seed_production_data(db()) : false;
    echo "LUMS database ready\n";
    echo 'Driver: ' . $result['driver'] . "\n";
    if ($isProduction) {
        echo 'Production admin: ' . ($productionSeeded ? 'created from environment' : 'already present') . "\n";
    } else {
        echo 'Demo data: ' . ($result['seeded'] ? 'created' : 'already present') . "\n";
        echo "Demo login: admin@lums.local / admin123\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Initialization failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
