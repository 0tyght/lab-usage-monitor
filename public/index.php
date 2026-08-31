<?php

declare(strict_types=1);

// Only this entry point and static assets are exposed by the Docker web server.
require dirname(__DIR__) . '/index.php';
