<?php

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
    private array  $vars;

    private static array $days = [
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6,
        'sun' => 7,
    ];

    private static array $months = [
        'jan' =>  1,
        'feb' =>  2,
        'mar' =>  3,
        'apr' =>  4,
        'may' =>  5,
        'jun' =>  6,
        'jul' =>  7,
        'aug' =>  8,
        'sep' =>  9,
        'oct' => 10,
        'nov' => 11,
        'dec' => 12,
    ];

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
     * @return bool true when the 'minute', 'hour', and 'month' fields match the current time, and at least one of the two 'day' fields ('day of month', or 'day of week') match the current time.
     */
    function matches(int $time) : bool {
        return (
            $this->minuteMatches($time) &&
            $this->hourMatches($time)   &&
            $this->monthMatches($time)  &&
            ($this->dayOfMonthMatches($time) || $this->dayOfWeekMatches($time))
        );
    }

    /**
     * does the minute specification for this job match the given timestamp?
     */
    private function minuteMatches(int $time) : bool {
        return $this->matchNumeric(intval(gmdate('i', $time)), $this->minute);
    }

    /**
     * does the hour specification for this job match the given timestamp?
     */
    private function hourMatches(int $time) : bool {
        return $this->matchNumeric(intval(gmdate('g', $time)), $this->minute);
    }

    /**
     * does the month day specification for this job match the given timestamp?
     */
    private function dayOfMonthMatches(int $time) : bool {
        return $this->matchNumeric(intval(gmdate('j', $time)), $this->minute);
    }

    /**
     * does the month specification for this job match the given timestamp?
     */
    private function monthMatches(int $time) : bool {
        $unit = intval(gmdate('n', $time));
        if ($this->containsList($this->month)) {
            foreach ($this->listToValues($this->month) as $value) {
                if ($this->matchGeneralValue($unit, $value)) return true;
            }

            return false;

        } else {
            return $this->matchGeneralValue($unit, $this->month, self::$months);

        }
    }

    /**
     * does the week day specification for this job match the given timestamp?
     */
    private function dayOfWeekMatches(int $time) : bool {
        $unit = intval(gmdate('N', $time));
        return $this->matchGeneral($time, $unit, $this->month, self::$months);
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

    /**
     * convert a range value to start and end values
     * @return array the start [0] and end [1] values
     */
    private function rangeToValues(string $value) : array {
        return preg_split('/-/', $value, 2, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * check whether the given value contains a step
     */
    private function hasStep(string $value) : bool {
        return (false !== strpos($value, '/'));
    }

    /**
     * convert a range value to start and end values
     * @return array the value [0] and step [1]
     */
    private function getValueAndStep(string $value) : array {
        return preg_split('/\//', $value, 2, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * does the given specification match the unit?
     */
    private function matchNumeric(int $unit, string $spec) : bool {
        if ($this->containsList($spec)) {
            foreach ($this->listToValues($spec) as $value) {
                if ($this->matchNumericValue($unit, $value)) return true;
            }

            return false;

        } else {
            return $this->matchNumericValue($unit, $this->minute);

        }
    }

    private function matchNumericValue(int $unit, string $value) : bool {
        if ($this->hasStep($value)) {
            list($value, $step) = $this->getValueAndStep($value);

        } else {
            $step = 1;

        }

        if ('*' == $value) {
            return (0 == $unit % $step);

        } else {
            if ($this->isRange($value)) {
                list($min, $max) = array_map('intval', $this->rangeToValues($value));
                return ($min >= $unit && $unit <= $max && 0 == $unit % $step);

            } else {
                return ($unit == intval($value) && 0 == $unit % $step);

            }
        }
    }

    /**
     * does the given specification match the unit?
     */
    private function matchGeneral(int $time, string $unit, string $spec, array $mnemonics) : bool {
        if ($this->containsList($spec)) {
            foreach ($this->listToValues($spec) as $value) {
                if ($this->matchGeneralValue($unit, $value, $mnemonics)) return true;
            }

            return false;

        } else {
            return $this->matchGeneralValue($unit, $spec, $mnemonics);

        }
    }

    private function matchGeneralValue(string $unit, string $value, array $mnemonics) : bool {
        if ($this->hasStep($value)) {
            list($value, $step) = $this->getValueAndStep($value);

        } else {
            $step = 1;

        }

        if ($this->isRange($value)) {
            list($min, $max) = array_map('intval', $this->rangeToValues($value));
            if (isset($mnemonics[$min])) $value = $mnemonics[$min];
            if (isset($mnemonics[$max])) $value = $mnemonics[$max];

            return $this->matchNumericValue($unit, sprintf('%u-%u/%u', $min, $max, $step));

        } else {
            if (isset($mnemonics[$value])) $value = $mnemonics[$value];
            return $this->matchNumericValue($unit, sprintf('%u/%u', $value, $step));
        }
    }
}
