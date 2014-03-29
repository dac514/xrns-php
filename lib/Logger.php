<?php

namespace XrnsPhp;

class Logger
{

    /**
     * @var bool
     */
    protected $useColors = true;

    /**
     * Color escapes for bash output
     *
     * @var array
     */
    protected $foreground = array(
        'black' => '0;30',
        'dark_gray' => '1;30',
        'red' => '0;31',
        'bold_red' => '1;31',
        'green' => '0;32',
        'bold_green' => '1;32',
        'brown' => '0;33',
        'yellow' => '1;33',
        'blue' => '0;34',
        'bold_blue' => '1;34',
        'purple' => '0;35',
        'bold_purple' => '1;35',
        'cyan' => '0;36',
        'bold_cyan' => '1;36',
        'white' => '1;37',
        'bold_gray' => '0;37',
    );

    /**
     * Color escapes for bash output
     *
     * @var array
     */
    protected $background = array(
        'black' => '40',
        'red' => '41',
        'magenta' => '45',
        'yellow' => '43',
        'green' => '42',
        'blue' => '44',
        'cyan' => '46',
        'light_gray' => '47',
    );


    /**
     *
     */
    function __construct()
    {
        $this->useColors = $this->isColorCompatible();
    }


    /**
     * Echo a log message
     *
     * @param $msg
     */
    function log($msg)
    {
        echo $msg;
    }


    /**
     * @return string
     */
    function startMessage()
    {
        global $argv;

        $s = '';
        $s .= "---------------------------------------\n";
        $s .= "$argv[0] is working...\n";
        $s .= date("D M j G:i:s T Y\n");
        $s .= "---------------------------------------\n";

        return $this->color($s, 'yellow');
    }


    /**
     * @return string
     */
    function doneMessage()
    {
        global $argv;

        $s = '';
        $s .= "---------------------------------------\n";
        $s .= "$argv[0] is done!\n";
        $s .= date("D M j G:i:s T Y\n");
        $s .= "---------------------------------------\n";

        return $this->color($s, 'green');
    }


    /**
     * Colorize a string
     *
     * @param string $string
     * @param string $color
     * @param string string $bgColor
     * @return string
     * @throws \Exception
     */
    function color($string, $color, $bgColor = '')
    {
        if (false == $this->useColors) {
            // Do nothing
            return $string;
        }

        if (!isset($this->foreground[$color])) {
            throw new \Exception('Foreground color is not defined');
        }

        if (!empty($bgColor)) {
            if (!isset($this->background[$bgColor])) {
                throw new \Exception('Background color is not defined');
            }
        }

        $s = "\033[";
        $s .= $this->foreground[$color];
        if ($bgColor) $s .= ';' . $this->background[$bgColor];
        $s .= 'm' . $string . "\033[0m";

        return $s;
    }


    /**
     * Beautify our Exceptions
     *
     * @param \Exception $e
     * @return string
     */
    function beautifyException($e)
    {
        $parts = explode('\\', get_class($e));

        $type = array_pop($parts);
        $type = preg_replace('/(?!^)[A-Z]{2,}(?=[A-Z][a-z])|[A-Z][a-z]/', ' $0', $type); // Parse CamelCase into human readable string
        $type = trim($type);

        $s = "\n";
        $s .= "$type Error ! \n";
        $s .= $e->getMessage();
        $s = $this->color($s, 'white', 'red');

        $d = "\n";
        $d .= "File: " . $e->getFile() . "\n";
        $d .= "Line: " . $e->getLine();
        $d = $this->color($d, 'dark_gray');

        return "{$s}{$d}\n";
    }


    /**
     * Check if BASH ANSI color compatible.
     * True if the platform is *not* Windows, otherwise look for ansicon
     *
     * @see https://github.com/adoxa/ansicon
     *
     * @return bool
     */
    protected function isColorCompatible()
    {
        if (PHP_OS != 'WINNT') {
            return true;
        }

        if (getenv('ANSICON_VER')) {
            return true;
        }

        if (command_exists('ansicon')) {
            return true;
        }

        return false;
    }


}
