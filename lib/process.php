<?php

namespace cron;

/**
 * class representing a running job
 */
class process {
    private string  $command;
    private array   $vars;
    private         $proc;
    private array   $pipes;

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

        log::debug(sprintf('executing "%s"', $this->command));

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

        if (false === $this->proc) throw new exception(sprintf("unable to execute '%s'", $shell));
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
