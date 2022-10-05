<?php

error_reporting(E_ALL);

ini_set('max_execution_time',   0);
ini_set('memory_limit',         -1);
ini_set('log_errors',           true);
ini_set('error_log',            '/dev/stderr');
ini_set('date.timezone',        'Etc/UTC');

require(__DIR__.'/lib/cron.php');

\cron\log::setLogLevel(true);

$d = new \cron\daemon(realpath($argv[1]), true);
$d->run();
