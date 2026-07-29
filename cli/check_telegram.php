<?php

try {
    $serviceDirectory = __DIR__ . '/../Gas/telegram_php';
    chdir($serviceDirectory);
    require $serviceDirectory . '/madeline.php';
    if (!class_exists(\danog\MadelineProto\API::class)) {
        throw new RuntimeException('MadelineProto API class was not loaded.');
    }
    fwrite(STDOUT, "PHP " . PHP_VERSION . PHP_EOL);
    fwrite(STDOUT, "MadelineProto loaded successfully." . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
