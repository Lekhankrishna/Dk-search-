<?php

const TELEGRAM_WORKER_ADDRESS = 'tcp://127.0.0.1:8091';

function telegramWorkerRequest(array $request, int $timeout = 30): array
{
    $errorNumber = 0;
    $errorMessage = '';
    $socket = @stream_socket_client(
        TELEGRAM_WORKER_ADDRESS,
        $errorNumber,
        $errorMessage,
        2,
        STREAM_CLIENT_CONNECT
    );
    if ($socket === false) {
        throw new RuntimeException(
            'Telegram connection is not running. Stop the current server and start it with run_crm_server.bat.'
        );
    }

    stream_set_timeout($socket, $timeout);
    fwrite($socket, json_encode($request, JSON_UNESCAPED_SLASHES) . "\n");
    $response = fgets($socket);
    $metadata = stream_get_meta_data($socket);
    fclose($socket);

    if ($response === false || !empty($metadata['timed_out'])) {
        throw new RuntimeException('Telegram worker did not respond in time.');
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Telegram worker returned an invalid response.');
    }
    return $decoded;
}
