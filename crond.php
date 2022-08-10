<?php

declare(strict_types=1);
declare(ticks = 1);

error_reporting(E_ALL);

ini_set('max_execution_time',   0);
ini_set('memory_limit',         -1);
ini_set('error_log',            '/dev/stderr');
ini_set('date.timezone',        'Etc/UTC');

$d = new cronDaemon(realpath($argv[1]));
$d->run();

/**
 * class representing a cron daemon.
 * unlike the system's crond(8), this daemon only uses a single crontab file, and only uses UTC.
 */
class cronDaemon {
    private string $file;
    private array $vars;
    private array $jobs;
    private array $procs;

    /**
     * @param string $file the crontab file - see man crond(5) for more information
     * @see https://man7.org/linux/man-pages/man5/crontab.5.html
     */
    function __construct(string $file='/etc/crontab') {
        error_log('running');
        $this->file     = $file;
        $this->jobs     = array();
        $this->procs    = array();
        $this->load();
    }

    /**
     * run the daemon.
     * this method installs a SIGCHLD signal handler (for dealing with child processes which had finished),
     * and a SIGHUP handler so it can reload the crontab file.
    */
    function run() {
        pcntl_signal(SIGCHLD, function($signo) {
            $this->reapProcesses();
        });

        pcntl_signal(SIGHUP, function($signo) {
            $this->load();
        });

        error_log('entering main loop');
        while (true) {
            $this->sleepToStartOfNextMinute();
            $this->runJobs();
        }
    }

    /**
     * run any jobs due to run at this particular moment in time
     */
    private function runJobs() {
        $time = time();
        foreach ($this->jobs as $job) {
            if ($job->matches($time)) {
                // spawn a new process
                $proc = $job->run();

                // store the process in the $procs array
                $this->procs[$proc->pid()] = $proc;
            }
        }
    }

    /**
     * reap any child processes that have finished
     * this method is called by the SIGCHLD signal handler
    */
    private function reapProcesses() {
        foreach ($this->procs as $pid => $proc) {
            if (!$proc->running()) {
                // get the process's output
                $output = preg_split('/\n/', trim(stream_get_contents($proc->stdout())), -1, PREG_SPLIT_NO_EMPTY);

                // stream the process's STDERR to our STDERR
                $fh = $proc->stderr();
                while (true) {
                    if (feof($fh)) {
                        break;

                    } else {
                        $line = trim((string)fgets($fh));
                        if (strlen($line) < 1) {
                            continue;

                        } else {
                            error_log($line);

                        }
                    }
                }

                // close the process and remove it from the process list
                $result = $proc->close();
                unset($this->procs[$pid]);

                error_log(sprintf('pid #%u (%s) exited with code %d', $pid, $proc->command(), $result));
            }
        }
    }

    /**
     * load the crontab file
     */
    private function load() {
        error_log(sprintf('loading crontab from %s', $this->file));

        $fh = fopen($this->file, 'r');
        if (!is_resource($fh)) {
            throw new cronException("unable to read {$this->file}");

        } else {
            $this->jobs = array();
            $this->vars = array();

            while (true) {
                if (feof($fh)) {
                    break;

                } else {
                    $line = fgets($fh);

                    if (false === $line || strlen($line) < 1) {
                        continue;

                    } else {
                        $this->parseLine(trim($line));

                    }
                }
            }

            fclose($fh);
        }
    }

    /**
     * parse a line in the crontab file.
     * the format of the line is described in man crontab(5)
     * @see https://man7.org/linux/man-pages/man5/crontab.5.html
     */
    private function parseLine(string $line) {
        if ('#' === substr($line, 0, 1) || 1 == preg_match('/^[ \t]*$/', $line)) {
            // do nothing

        } elseif (1 == preg_match('/^[a-z_]+[ \t]*=[ \t]*.+$/i', $line)) {
            $this->parseVariableDeclaration($line);

        } else {
            $this->parseJob($line);

        }
    }

    /**
     * parse a variable declaration. the variable will be stored in the $vars property and will then be passed to any subsequent jobs found in the file.
     * @see https://man7.org/linux/man-pages/man5/crontab.5.html
     */
    private function parseVariableDeclaration(string $line) {
        list($k, $v) = preg_split('/[ \t]*=[ \t]*/', $line, 2);
        if (in_array(substr($v, 0, 1), array('"', "'")) && substr($v, 0, 1) == substr($v, -1, 1)) $v = substr($v, 1, -1);
        $this->vars[$k] = $v;
    }

    /**
     * parse a job specification and create a new job object which will be stored in the $jobs array
     */
    private function parseJob(string $line) {
        list($minute, $hour, $mday, $month, $wday, $command) = preg_split('/[ \t]+/', $line, 6);
        $this->jobs[] = new cronJob($minute, $hour, $mday, $month, $wday, $command, $this->vars);
    }

    /**
     * during the main loop, once the jobs for the current minute have been spawned, we need to sleep
     * until the start of the next minute. since the sleep can be interrupted by signals, this method
     * will call itself recursively if the sleep() call returns a positive integer.
     * this function has microsecond precision.
     */
    private function sleepToStartOfNextMinute() {
        $from = microtime(true);

        $to = gmmktime(
            intval(gmdate('H', intval($from))),
            intval(gmdate('i', intval($from)))+1,
            0,
            intval(gmdate('n', intval($from))),
            intval(gmdate('j', intval($from))),
            intval(gmdate('Y', intval($from))),
        );

        $dt     = $to - $from;
        $secs   = intval(floor($dt));
        $usecs  = intval(1000000 * ($dt - $secs));

        if (sleep($secs) > 0) {
            // return value is non-zero, meaning our sleep was interrupted by a signal, so restart from here
            $this->sleepToStartOfNextMinute();

        } else {
            usleep($usecs);

        }
    }
}

/**
 * class representing a cron job
 */
class cronJob {
    private string $minute;
    private string $hour;
    private string $mday;
    private string $month;
    private string $wday;
    private string $command;
    private array $vars;

    function __construct(string $minute, string $hour, string $mday, string $month, string $wday, string $command, array $vars) {
        $this->minute   = $minute;
        $this->hour     = $hour;
        $this->mday     = $mday;
        $this->month    = $month;
        $this->wday     = $wday;
        $this->command  = $command;
        $this->vars     = $vars;
    }

    /**
     * run the command specified in this job
     */
    function run() : cronProcess {
        return new cronProcess($this->command, $this->vars);
    }

    /**
     * does the time specification for this job match the given timestamp?
     * @return bool true when the 'minute', 'hour', and 'month of the year' fields match the current time, and at least one of the two 'day' fields ('day of month', or 'day of week') match the current time.
     */
    function matches(int $time) : bool {
        return (
            $this->minuteMatches($time) &&
            $this->hourMatches($time)   &&
            $this->monthMatches($time)  &&
            ($this->monthDayMatches($time) || $this->weekDayMatches($time))
        );
    }

    /**
     * does the minute specification for this job match the given timestamp?
     */
    private function minuteMatches(int $time) : bool {
        if ($this->containsList($this->minute)) {
            foreach ($this->listToValues($this->minute) as $value) {
                if ($this->matchMinuteValue($time, $value)) return true;
            }

            return false;

        } else {
            return $this->matchMinuteValue($time, $this->minute);

        }
    }

    private function matchMinuteValue(int $time, string $value) : bool {
        if ('*' == $value) {
            return true;

        } else {
            $minute = intval(gmdate('i', $time));

            if ($this->isRange($value)) {
                list($min, $max) = array_map('intval', $this->rangeToValues($value));
                return ($min >= $minute && $minute <= $max);

            } else {
                return $minute == intval($value);

            }
        }
    }

    /**
     * does the hour specification for this job match the given timestamp?
     */
    private function hourMatches(int $time) : bool {
    }

    /**
     * does the month day specification for this job match the given timestamp?
     */
    private function monthDayMatches(int $time) : bool {
    }

    /**
     * does the month specification for this job match the given timestamp?
     */
    private function monthMatches(int $time) : bool {
    }

    /**
     * does the week day specification for this job match the given timestamp?
     */
    private function weekDayMatches(int $time) : bool {
    }

    /**
     * check whether the given value contains a list
     */
    private function containsList(string $value) : bool {
        return (false !== strpos($value, ','));
    }

    /**
     * convert a comma-separated list of values to an array of values
     */
    private function listToValues(string $value) : array {
        return preg_split('/\,/', $value, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * check whether the given value contains a range
     */
    private function isRange(string $value) {
        return (false !== strpos($value, '-'));
    }

    private function rangeToValues(string $value) : array {
        return preg_split('/-/', $value, 2, PREG_SPLIT_NO_EMPTY);
    }
}

/**
 * class representing a running job
 */
class cronProcess {
    private string $command;
    private array $vars;
    private $proc;
    private array $pipes;

    /**
     * run the specified command with the given environment variables.
     * this spawns a shell process (either $SHELL or /bin/sh).
     * @param string $command the command to be run
     * @param array $vars associate array of environment variables
     */
    function __construct(string $command, array $vars) {
        $this->command  = $command;
        $this->vars     = $vars;
        $this->pipes    = array();

        if (isset($this->vars['SHELL'])) {
            $shell = $this->vars['SHELL'];

        } elseif (false === ($shell = getenv('SHELL'))) {
            $shell = '/bin/sh';

        }

        $this->proc = proc_open(
            sprintf('%s -c %s', $shell, escapeShellArg($this->command)),
            array(
                0 => array('file', '/dev/null', 'r'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w')
            ),
            $this->pipes,
            sys_get_temp_dir(),
            $this->vars
        );

        if (false === $this->proc) throw new cronException(sprintf("unable to execute '%s'", $command));
    }

    /**
     * get the process ID
     */
    function pid() : int {
        $s = proc_get_status($this->proc);
        return intval($s['pid']);
    }

    /**
     * is the process still running?
     */
    function running() : bool {
        $s = proc_get_status($this->proc);
        return $s['running'];
    }

    /**
     * close the process
     */
    function close() : int {
        fclose($this->stdout());
        fclose($this->stderr());
        return proc_close($this->proc);
    }

    /**
     * get the command used
     */
    function command() : string {
        return $this->command;
    }

    /**
     * get the process's STDOUT
     * @return resource
     */
    function stdout() {
        return $this->pipes[1];
    }

    /**
     * get the process's STDERR
     * @return resource
     */
    function stderr() {
        return $this->pipes[2];
    }
}

class cronException extends Exception {
}
