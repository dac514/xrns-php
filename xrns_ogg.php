<?php

require(__DIR__ . '/lib/functions.php');

// --------------------------------------------------------------------------------------------------------------------
// Sanity check command prompt input
// --------------------------------------------------------------------------------------------------------------------

$scriptName = basename($argv[0]);

if ($argc < 3) {
    echo "Error: $scriptName expects at least 2 parameters.\n";
    echo "Usage: `php $scriptName /path/to/file1.xrns file2.xrns [1|2|3|...|10]`\n";
    echo "$scriptName will output ogg compresed file (file2.xrns) to current working directory.\n";
    die();
}

$inputFileName = $argv[1];
$outputFileName = $argv[2];

if (!file_exists($inputFileName))
    die("Error: The file $inputFileName was not found.\n");

if (!(preg_match('/(\.zip$|\.xrns$|\.xrni$)/i', $outputFileName))) {
    die("Error: The filename $outputFileName is invalid, use .xrns / .xrni (or .zip)\n");
}

$quality = 3;
if (isset($argv[3]) && ctype_digit($argv[3]) && $argv[3] >= 0 && $argv[3] <= 10) {
    $quality = $argv[3];
}

// --------------------------------------------------------------------------------------------------------------------
// Compress using Flac and Ogg
// --------------------------------------------------------------------------------------------------------------------

use XrnsPhp\Logger;
use XrnsPhp\File;
use XrnsPhp\Ogg;

try {
    $logger = new Logger();
    $logger->log($logger->startMessage());

    $file1 = new File($inputFileName);

    $ogg = new Ogg($file1);
    $ogg->compress($quality);

    $file1->zip($outputFileName);

    $logger->log($logger->doneMessage());
}
catch (Exception $e) {

    $logger->log($logger->beautifyException($e));
}
