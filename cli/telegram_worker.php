<?php

set_time_limit(0);
require __DIR__ . '/../config/telegram.php';

// Force MadelineProto to deserialize and run the authorized session inside
// this long-lived worker. Without this flag it hands the session to its own
// Windows IPC child and our CRM listener below never gets a chance to start.
$_GET['MadelineSelfRestart'] = '1';

$serviceDirectory = realpath(__DIR__ . '/../Gas/telegram_php');
if ($serviceDirectory === false || !chdir($serviceDirectory)) {
    fwrite(STDERR, "Telegram service directory is unavailable." . PHP_EOL);
    exit(1);
}

require $serviceDirectory . '/madeline.php';

\Revolt\EventLoop::setErrorHandler(static function (Throwable $error): void {
    // QR expiration and DC hand-off intentionally cancel pending requests.
    // MadelineProto may surface that normal cancellation through the Windows
    // event loop; ignore only this expected type and preserve real failures.
    if ($error instanceof \Amp\CancelledException) return;
    throw $error;
});

function telegramRetryAfterCancellation(callable $operation, int $attempts = 12): mixed
{
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            return $operation();
        } catch (Throwable $error) {
            $cancelled = $error instanceof \Amp\CancelledException
                || stripos($error->getMessage(), 'operation was cancelled') !== false;
            if (!$cancelled || $attempt === $attempts) throw $error;
            echo "Telegram is completing the login hand-off; retrying..." . PHP_EOL;
            usleep(500000);
        }
    }
    throw new RuntimeException('Telegram login hand-off did not complete.');
}

function cleanTelegramResult(string $text): string
{
    $lines = preg_split('/\R/u', $text) ?: [];
    $firstDataLine = null;
    foreach ($lines as $index => $line) {
        $plainLine = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $line);
        if (preg_match(
            '/^\s*(?:telephone|email|aadhaar(?:\s+number)?|document\s+number|adres|address|full\s+name|the\s+name\s+of\s+the\s+father|region)\s*:/i',
            (string) $plainLine
        )) {
            $firstDataLine = $index;
            break;
        }
    }

    // The bot sometimes prefixes records with a long source/leak description.
    // Display only the structured record beginning at its first actual field.
    if ($firstDataLine !== null) $lines = array_slice($lines, $firstDataLine);
    $cleaned = implode(PHP_EOL, $lines);
    $cleaned = preg_replace(
        '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u',
        '',
        $cleaned
    );
    return trim((string) $cleaned);
}

$settings = new \danog\MadelineProto\Settings;
$settings->setAppInfo(
    (new \danog\MadelineProto\Settings\AppInfo)
        ->setApiId((int) $TELEGRAM_API_ID)
        ->setApiHash((string) $TELEGRAM_API_HASH)
);

try {
    $api = new \danog\MadelineProto\API($serviceDirectory . '/worker.session', $settings);
    echo PHP_EOL . "ONE-TIME TELEGRAM QR LOGIN" . PHP_EOL;
    echo "On the logged-in phone: Telegram > Settings > Devices > Link Desktop Device." . PHP_EOL;
    echo "Scan the terminal QR code below. It refreshes automatically when expired." . PHP_EOL . PHP_EOL;

    do {
        $qr = $api->qrLogin();
        if ($qr === null) break;
        echo $qr->getQRText(2) . PHP_EOL;
        echo "Waiting for the QR scan..." . PHP_EOL;
        try {
            $qr = $qr->waitForLoginOrQrCodeExpiration();
        } catch (\Amp\CancelledException) {
            echo "QR expired or Telegram changed data centre; generating a fresh code..." . PHP_EOL;
            $qr = $api->qrLogin();
        }
    } while ($qr !== null);

    if ($api->getAuthorization() === \danog\MadelineProto\API::WAITING_PASSWORD) {
        $password = \danog\MadelineProto\Tools::readLine('Telegram two-step verification password: ');
        $api->complete2faLogin($password);
    }

    $self = telegramRetryAfterCancellation(fn() => $api->getSelf());
    if (!$self) {
        throw new RuntimeException('Telegram login was not completed.');
    }

    $needle = strtolower((string) $TELEGRAM_BOT_MATCH);
    $peer = null;
    $dialogs = telegramRetryAfterCancellation(fn() => $api->getDialogIds());
    foreach ($dialogs as $dialog) {
        try {
            $info = telegramRetryAfterCancellation(fn() => $api->getInfo($dialog));
            $user = $info['User'] ?? $info['Chat'] ?? [];
            $name = implode(' ', [
                $user['first_name'] ?? '',
                $user['last_name'] ?? '',
                $user['title'] ?? '',
                $user['username'] ?? '',
            ]);
            if (str_contains(strtolower($name), $needle)) {
                $peer = $dialog;
                break;
            }
        } catch (Throwable $ignored) {
        }
    }
    if ($peer === null) {
        throw new RuntimeException("No Telegram chat matching '{$TELEGRAM_BOT_MATCH}' was found.");
    }

    $server = stream_socket_server('tcp://127.0.0.1:8091', $errorNumber, $errorMessage);
    if ($server === false) {
        throw new RuntimeException("Could not start Telegram worker: {$errorMessage}");
    }
    echo PHP_EOL . "Telegram connected. Worker ready on 127.0.0.1:8091." . PHP_EOL;
    echo "Keep this window open." . PHP_EOL;

    while ($connection = @stream_socket_accept($server, -1)) {
        $line = fgets($connection);
        $request = is_string($line) ? json_decode($line, true) : null;
        try {
            if (!is_array($request)) {
                throw new RuntimeException('Invalid worker request.');
            }
            if (($request['action'] ?? '') === 'status') {
                $response = ['ok' => true];
            } elseif (($request['action'] ?? '') === 'search') {
                $query = trim((string) ($request['query'] ?? ''));
                if ($query === '') throw new RuntimeException('Search query is required.');

                $history = $api->messages->getHistory(peer: $peer, limit: 1);
                $beforeId = (int) ($history['messages'][0]['id'] ?? 0);
                $api->messages->sendMessage(peer: $peer, message: $query);

                $pattern = '/telephone\s*:|email\s*:|aadhaar(?:\s+number)?\s*:|(?:address|adres)\s*:|full\s*name\s*:|name\s+of\s+the\s+father\s*:/i';
                $deadline = microtime(true) + 20;
                $results = [];
                $seen = [];
                $quietSince = null;
                do {
                    $history = $api->messages->getHistory(peer: $peer, min_id: $beforeId, limit: 30);
                    foreach ($history['messages'] ?? [] as $message) {
                        $id = (int) ($message['id'] ?? 0);
                        $text = trim((string) ($message['message'] ?? ''));
                        if (!$id || isset($seen[$id]) || !empty($message['out']) || !preg_match($pattern, $text)) continue;
                        $seen[$id] = true;
                        $results[] = [
                            'id' => $id,
                            'text' => cleanTelegramResult($text),
                            'hasMedia' => isset($message['media']),
                        ];
                        $quietSince = microtime(true);
                    }
                    if ($results && $quietSince !== null && microtime(true) - $quietSince >= 2) break;
                    usleep(500000);
                } while (microtime(true) < $deadline);

                usort($results, fn(array $a, array $b): int => $a['id'] <=> $b['id']);
                $response = ['ok' => true, 'results' => $results];
            } else {
                throw new RuntimeException('Unknown worker action.');
            }
        } catch (Throwable $error) {
            $response = ['ok' => false, 'error' => $error->getMessage()];
        }
        fwrite($connection, json_encode($response, JSON_UNESCAPED_SLASHES) . "\n");
        fclose($connection);
    }
} catch (Throwable $error) {
    fwrite(STDERR, PHP_EOL . '[ERROR] ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Press Enter to close.' . PHP_EOL);
    fgets(STDIN);
    exit(1);
}
