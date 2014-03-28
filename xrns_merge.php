<?php

require(__DIR__ . '/lib/functions.php');

// --------------------------------------------------------------------------------------------------------------------
// Sanity check command prompt input
// --------------------------------------------------------------------------------------------------------------------

$scriptName = basename($argv[0]);

if ($argc != 4) {
    echo "Error: $scriptName expects 3 parameters.\n";
    echo "Usage: `php $scriptName /path/to/file1.xrns /path/to/file2.xrns file3.xrns`\n";
    echo "$scriptName will output merged file (file3.xrns) to current working directory.\n";
    die();
}

$inputFileName1 = $argv[1];
$inputFileName2 = $argv[2];
$outputFileName = $argv[3];

if (!file_exists($inputFileName1))
    die("Error: The file $inputFileName1 was not found.\n");

if (!file_exists($inputFileName2))
    die("Error: The file $inputFileName2 was not found.\n");

if (!(preg_match('/(\.zip$|\.xrns$)/i', $outputFileName)))
    die("Error: The filename $outputFileName  is invalid, use .xrns (or .zip)\n");


// --------------------------------------------------------------------------------------------------------------------
// Merge Two XRNS Files
// --------------------------------------------------------------------------------------------------------------------

use XrnsPhp\Logger;
use XrnsPhp\File;
use XrnsPhp\Merge;

try {
    $logger = new Logger();
    $logger->log($logger->startMessage());

    $file1 = new File($inputFileName1);
    $file2 = new File($inputFileName2);

    $merger = new Merge($file1, $file2);
    $merger->merge();

    $file1->zip($outputFileName);

    $logger->log($logger->doneMessage());
}
catch (Exception $e) {

    $logger->log($logger->beautifyException($e));
}
