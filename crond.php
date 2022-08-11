<?php

declare(strict_types=1);
declare(ticks=1);

error_reporting(E_ALL);

ini_set('max_execution_time',   0);
ini_set('memory_limit',         -1);
ini_set('log_errors',           true);
ini_set('error_log',            'syslog');
ini_set('date.timezone',        'Etc/UTC');

define('LIBDIR', __DIR__.'/lib');

require LIBDIR.'/cronDaemon.php';
require LIBDIR.'/cronJob.php';
require LIBDIR.'/cronProcess.php';
require LIBDIR.'/cronException.php';

$d = new cronDaemon(realpath($argv[1]));
$d->run();

exit;
