<?php

namespace cron;

/**
 * class representing a cron daemon.
 * unlike the system's crond(8), this daemon only uses a single crontab file, and only uses UTC.
 */
class daemon {
    private string      $file;
    private array       $vars;
    private array       $jobs;
    private array       $procs;
    private static int  $debug;

    /**
     * @param string $file the crontab file - see man crond(5) for more information
     * @see https://man7.org/linux/man-pages/man5/crontab.5.html
     */
    function __construct(string $file='/etc/crontab', bool $debug=false) {
        self::$debug = $debug;

        $this->file     = $file;
        $this->jobs     = [];
        $this->procs    = [];
        $this->load();
        log::debug('initialised');
    }

    /**
     * run the daemon.
    */
    function run() {
        log::debug('running');

        log::debug('entering main loop');
        while (true) {
            $this->sleepToStartOfNextMinute();
            $this->runJobs(time());

            log::debug(sprintf('current memory usage: %.1fKB, peak: %.1fKB', memory_get_usage() / 1000, memory_get_peak_usage() / 1000));
        }
    }

    /**
     * run once, for the commands that would be run at the specified time
     * @return array an array of process objects representing the running processes. No signal handlers will be installed, so you must handle terminations yourself.
     */
    function runFor(int $time) : array {
        $this->runJobs($time);
        return $this->jobs;
    }

    /**
     * run any jobs due to run at this particular moment in time
     */
    private function runJobs(int $time) {
        log::debug('running jobs for %s', gmdate('r', $time));
        foreach ($this->jobs as $job) {
            if (!$job->matches($time)) {
                log::debug('no match for %s', $job->timeSpecToString());

            } else {
                log::debug("time spec %s matches, executing '%s'", $job->timeSpecToString(), $job->command());

                try {
                    // spawn a new process
                    $proc = $job->run();

                    log::debug(sprintf('spawned process %u', $proc->pid()));

                    // store the process in the $procs array
                    $this->procs[$proc->pid()] = $proc;

                } catch (exception $e) {
                    log::warn($e->getMessage());

                }
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
                log::debug(sprintf('pid #%u has finished', $pid));

                // get the process's output
                $output = preg_split('/\n/', trim(stream_get_contents($proc->stdout())), -1);
                log::debug(sprintf('pid #%u: %u output lines', count($output)));

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
                            log::debug($line);

                        }
                    }
                }

                // close the process and remove it from the process list
                $result = $proc->close();
                unset($this->procs[$pid]);

                log::debug(sprintf('pid #%u (%s) exited with code %d', $pid, $proc->command(), $result));
            }
        }
        log::debug(sprintf('%u processes still running', count($this->procs)));
    }

    /**
     * load the crontab file
     */
    private function load() {
        log::debug(sprintf('loading crontab from %s', $this->file));

        $fh = fopen($this->file, 'r');
        if (!is_resource($fh)) {
            log::error("unable to read {$this->file}");

        } else {
            $this->jobs = [];
            $this->vars = [];

            $ln = 0;

            while (true) {
                if (feof($fh)) {
                    break;

                } else {
                    $ln++;

                    $line = fgets($fh);

                    if (false === $line || strlen($line) < 1) {
                        continue;

                    } else {
                        try {
                            $this->parseLine(ltrim($line));

                        } catch (exception $e) {
                            log::warn(sprintf('error parsing %s at line %u (%s)', $this->file, $ln, $e->getMessage()));

                        }
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
        list($minute, $hour, $mday, $month, $wday, $command) = preg_split('/[ \t]+/', rtrim($line), 6);
        $this->jobs[] = new job($minute, $hour, $mday, $month, $wday, $command, $this->vars);
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

        $dt = $to - $from;

        log::debug(sprintf('sleeping %.03fs until %s', $dt, gmdate('r', $to)));

        $secs   = intval(floor($dt));
        $usecs  = intval(1000000 * ($dt - $secs));

        if (sleep($secs) > 0) {
            $this->readProcesses();
            // return value is non-zero, meaning our sleep was interrupted by a signal, so restart from here
            $this->sleepToStartOfNextMinute();

        } else {
            usleep($usecs);

        }
    }
}
