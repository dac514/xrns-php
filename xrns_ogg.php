<?php

/*

Requires: PHP5, Info-Zip (http://www.info-zip.org/),
oggenc (http://www.rarewares.org/ogg-oggenc.php),
and optionally flac (http://flac.sourceforge.net/)

Usage: `php xrns_ogg.php /path/to/file1.xrns file2.xrns [1|2|3|...|10]`
Will output compressed file to current working directory
Also works with .xrni files

Public Domain, Coded by Dac Chartrand of http://www.trotch.com/

Special thanks for collaborative efforts to:
Taktik / Bantai of http://www.renoise.com/
Beatslaughter of http://www.beatslaughter.de/
Vv of http://www.vincentvoois.com/

*/

// ----------------------------------------------------------------------------
// Variables
// ----------------------------------------------------------------------------

$tmp_dir = '/tmp';

// ----------------------------------------------------------------------------
// Requires
// ----------------------------------------------------------------------------

require_once(__DIR__ . '/functions/xrns_functions.php');

// ----------------------------------------------------------------------------
// Functions
// ----------------------------------------------------------------------------

function ogg_files($source, $quality = 3) {

    $path_to_oggenc = 'oggenc'; // oggenc assumed to be in path
    $path_to_flac = 'flac';     // flac assumed to be in path
    $array = scandir($source);

    foreach ($array as $file) {

        $fullpath = $source . '/' . $file;

        if (filesize($fullpath) <= 4096) continue; // Skip files smaller than 4096 bytes

        switch (strtolower(end(explode('.', $file)))) {

        case ('flac') :

            $command = $path_to_flac . ' -d --delete-input-file "'.$fullpath.'"';
            $res = -1; // any nonzero value
            $UnusedArrayResult = array();
            $UnusedStringResult = exec($command, $UnusedArrayResult, $res);
            if ($res != 0) echo "Warning: flac return_val was $res, there was a problem decompressing flac $file\n";
            else $fullpath = str_replace(".flac", ".wav", $fullpath);

        case ('wav'):
        case ('aif'):
        case ('aiff'):

            $command = $path_to_oggenc . " --quality $quality \"$fullpath\" ";
            $res = -1; // any nonzero value
            $UnusedArrayResult = array();
            $UnusedStringResult = exec($command, $UnusedArrayResult, $res);
            if ($res != 0) echo "Warning: oggenc return_val was $res, there was a problem compressing $file\n";
            else unlink($fullpath);

        }
    }
}

// ----------------------------------------------------------------------------
// Check Variables
// ----------------------------------------------------------------------------

// get filename component of path

$argv[0] = basename($argv[0]);
if (!is_dir($tmp_dir)) {
    $tmp_dir = get_temp_dir();
    if (!$tmp_dir) die("Error: Please set \$tmp_dir in $argv[0] to an existing directory.\n");
}

// ----------------------------------------------------------------------------
// Check User Input
// ----------------------------------------------------------------------------

if ($argc < 3) {
    echo "Error: $argv[0] expects at least 2 parameters.\n";
    echo "Usage: `php $argv[0] /path/to/file1.xrns file2.xrns [1|2|3|...|10]`\n";
    echo "$argv[0] will output ogg compresed file (file2.xrns) to current working directory.\n";
    die();
}

if (!file_exists($argv[1])) die("Error: The file $argv[1] was not found.\n");

if (!(preg_match('/(\.zip$|\.xrns$|\.xrni$)/i', $argv[2]))) {
    die("Error: The filename $argv[2] is invalid, use .xrns / .xrni (or .zip)\n");
}

// Input and Output files
$song1 = $argv[1];
$song2 = $argv[2];

// Quality
$qval = explode(chr(160), @$argv[3], 2);
$qval = $qval[0];

if(isset($qval) && ctype_digit($qval) && $qval >= 0 && $qval <= 10) $quality = $qval;
else $quality = 3; // default

// ----------------------------------------------------------------------------
// Unpack
// ----------------------------------------------------------------------------

echo "---------------------------------------\n";
echo "$argv[0] is working...\n";
echo "Vorbis quality level [$quality]\n";
echo date("D M j G:i:s T Y\n");
echo "---------------------------------------\n";

echo "Using temporary directory: $tmp_dir\n";

// Create a unique directory
if ((preg_match('/(\.xrni$)/i', $song1))) {
    $unzip1 = $tmp_dir . '/xrns_ogg_' . md5(uniqid(mt_rand(), true)) . '_Ins01/';
}
else {
    $unzip1 = $tmp_dir . '/xrns_ogg_' . md5(uniqid(mt_rand(), true)) . '_Track01/';
}

// Unzip song1
$result = UnzipAllFiles($song1, $unzip1);
if($result === FALSE) {
    echo "Error: There was a problem unzipping the first file.\n";
    echo "Error code: $result\n";
    die();
}

// ----------------------------------------------------------------------------
// Convert samples to .ogg
// ----------------------------------------------------------------------------

// SampleData directory
if (is_dir($unzip1 . 'SampleData/')) {
    // Xrni or Xrns?
    if (preg_match('/(\.xrni$)/i',$song1)) {

        $source = $unzip1 . 'SampleData/';
        ogg_files($source, $quality);

    }
    else foreach(new DirectoryIterator($unzip1 . 'SampleData/') as $file) {

        if ($file == '.' || $file == '..') continue; // Skip these files

        $source = $unzip1 . 'SampleData/' . $file;
        if (is_dir($source)) ogg_files($source, $quality);

    }
}

// ----------------------------------------------------------------------------
// Zip song
// ----------------------------------------------------------------------------

// Zip song
$result = ZipAllFiles($song2, $unzip1);
if($result === FALSE) {
    echo "Error: There was a problem zipping the final file.\n";
    echo "Error code: $result\n";
    die();
}

// ----------------------------------------------------------------------------
// Remove temp directories
// ----------------------------------------------------------------------------

obliterate_directory($unzip1);

echo "---------------------------------------\n";
echo "$argv[0] is done!\n";
echo date("D M j G:i:s T Y\n");
echo "---------------------------------------\n";

?>
