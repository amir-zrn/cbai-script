<?php

namespace App\Services;

class FileLogger
{
    private const LOG_DIR = 'storage/api_logs';

    public function __construct()
    {
        $this->initializeLogDirectory();
    }

    private function initializeLogDirectory() : void
    {
        $dir = base_path(self::LOG_DIR);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/.htaccess', 'Deny from all');
        }
    }

    public function log(array $data): void
    {
        $userId = $data['wp_user_id'];
        $logFile = base_path(self::LOG_DIR . "/{$userId}.jsonl");

        $logEntry = json_encode([
                'timestamp_utc' => gmdate('Y-m-d H:i:s'),
                'api_key_id' => $data['api_key_id'],
                'wp_user_id' => $data['wp_user_id'],
                'endpoint' => $data['endpoint'],
                'method' => $data['method'],
                'tokens_used' => $data['tokens_used'],
                'ip_address' => $data['ip_address'],
                'request_data' => $data['request_data'],
                'response_data' => $data['response_data'],
                'response_status' => $data['response_status']
            ]) . "\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function getUserStats(int $userId): array
    {
        $logFile = base_path(self::LOG_DIR . "/{$userId}.jsonl");

        if (!file_exists($logFile)) {
            return [
                'total_tokens' => 0,
                'request_count' => 0,
                'last_request' => null
            ];
        }

        $stats = [
            'total_tokens' => 0,
            'request_count' => 0,
            'last_request' => null
        ];

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            $stats['total_tokens'] += $entry['tokens_used'];
            $stats['request_count']++;
            $stats['last_request'] = $entry['timestamp_utc'];
        }

        return $stats;
    }
}
