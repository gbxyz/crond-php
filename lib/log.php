<?php

namespace cron;

openlog(__NAMESPACE__, LOG_CONS | LOG_PERROR | LOG_PID, LOG_CRON);

class log {               
     
    static int $level = LOG_NOTICE;

    static function setLogLevel(int $level) {
        self::$level = $level;
    }

    static function log(int $priority, string $message, ...$values) : bool {
        if ($priority < self::$level) {
            return true;

        } else {
            return syslog(
                $priority,
                (empty($values) ? $message : vsprintf($message, $values))
            );
        }
    }

    static function debug() : bool {
        return self::log(LOG_DEBUG, ...func_get_args());
    }

    static function notice() : bool {
        return self::log(LOG_NOTICE, ...func_get_args());
    }

    static function warn() : bool {
        return self::log(LOG_WARNING, ...func_get_args());
    }

    static function error() : bool {
        return self::log(LOG_CRIT, ...func_get_args());
        exit(1);
    }
}
