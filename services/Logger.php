<?php
/**
 * Logging service — writes to an absolute path based on __DIR__.
 */
class Logger {
    private static $logDir = null;

    private static function getLogDir(): string {
        if (self::$logDir === null) {
            self::$logDir = dirname(__DIR__) . '/logs';
        }
        return self::$logDir;
    }

    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context);
    }

    public static function info($message, $context = []) {
        self::log('INFO', $message, $context);
    }

    public static function warning($message, $context = []) {
        self::log('WARNING', $message, $context);
    }

    private static function log($level, $message, $context = []) {
        $logDir = self::getLogDir();
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/error.log';

        // Simple log rotation: if file exceeds 5MB, rotate
        if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
            $rotated = $logDir . '/error.' . date('Y-m-d_His') . '.log';
            @rename($logFile, $rotated);
        }

        $date = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? 'CLI';

        $contextStr = empty($context) ? '' : ' | Context: ' . json_encode($context);
        $logEntry = "[$date] [$level] [IP: $ip] [URI: $uri] $message$contextStr" . PHP_EOL;

        error_log($logEntry, 3, $logFile);
    }
}
