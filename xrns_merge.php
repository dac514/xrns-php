<?php

/*

Requires: PHP5 and Info-Zip (http://www.info-zip.org/)
Usage: `php xrns_merge.php /path/to/file1.xrns /path/to/file2.xrns file3.xrns`
Will output merged file to current working directory

Public Domain. Coded by Dac Chartrand of http://www.trotch.com/

Special thanks for collaborative efforts to:
Taktik / Bantai of http://www.renoise.com/
Beatslaughter of http://www.beatslaughter.de/

*/

// ----------------------------------------------------------------------------
// Variables, requires, helper functions
// ----------------------------------------------------------------------------

$tmp_dir = '/tmp';

require_once(__DIR__ . '/functions/xrns_functions.php');

function abort($msg, $folders) {

    echo $msg;
    if (!is_array($folders)) $folders = array($folders);
    foreach ($folders as $folder) {
        obliterate_directory($folder);
    }
    die();
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

if ($argc != 4) {
    echo "Error: $argv[0] expects 3 parameters.\n";
    echo "Usage: `php $argv[0] /path/to/file1.xrns /path/to/file2.xrns file3.xrns`\n";
    echo "$argv[0] will output merged file (file3.xrns) to current working directory.\n";
    die();
}

if (!file_exists($argv[1])) die("Error: The file $argv[1] was not found.\n");
if (!file_exists($argv[2])) die("Error: The file $argv[2] was not found.\n");
if (!(preg_match('/(\.zip$|\.xrns$)/i', $argv[3]))) {
    die("Error: The filename $argv[3] is invalid, use .xrns (or .zip)\n");
}

$song1 = $argv[1];
$song2 = $argv[2];
$song3 = $argv[3];

// ----------------------------------------------------------------------------
// Unpack
// ----------------------------------------------------------------------------

echo "---------------------------------------\n";
echo "$argv[0] is working...\n";
echo date("D M j G:i:s T Y\n");
echo "---------------------------------------\n";

echo "Using temporary directory: $tmp_dir\n";

// Create a unique directory
$unzip1 = $tmp_dir . '/xrns_merge_' . md5(uniqid(mt_rand(), true)) . '_Track01/';
$unzip2 = $tmp_dir . '/xrns_merge_' . md5(uniqid(mt_rand(), true)) . '_Track02/';

// Unzip song1
$result = UnzipAllFiles($song1, $unzip1);

if($result === FALSE) {
    echo "Error: There was a problem unzipping the first file.\n";
    die();
}

// Unzip song2
$result = UnzipAllFiles($song2, $unzip2);
if($result === FALSE) {
    echo "Error: There was a problem unzipping the second file.\n";
    die();
}

// Load XML
$sx1 = simplexml_load_file($unzip1 . 'Song.xml');
$sx2 = simplexml_load_file($unzip2 . 'Song.xml');

// ----------------------------------------------------------------------------
// Check doc_version
// ----------------------------------------------------------------------------

if ((string) $sx1['doc_version'] != (string) $sx2['doc_version']) {

    $err = "Error: Unsupported, cannot merge different versions.\n";
    $err .= "Save your songs with the same version of Renoise and try again.\n";
    abort($err, array($unzip1, $unzip2));
}

if ((string) $sx1['doc_version'] < 21) {

    $err = "Error: Unsupported version.\n";
    $err .= "Save your songs with a newer version of Renoise and try again.\n";
    abort($err, array($unzip1, $unzip2));
}

// ----------------------------------------------------------------------------
// Check if resulting merge would break maximum FF instruments limitation
// ----------------------------------------------------------------------------

if ((count($sx1->Instruments->Instrument) + count($sx2->Instruments->Instrument)) > 255) {

    abort("Error: Unsupported, too many instruments.\n", array($unzip1, $unzip2));
}

/* ----------------------------------------------------------------------------
To merge two songs, we start by appending the total tracks and sends in song2
as empty offsets to song1. We must also prepend the total tracks and sends in
song1 as empty offsets to song2.
---------------------------------------------------------------------------- */

// Start with sequencer tracks (eg. to the left of the master track)
$offset = count($sx1->Tracks->SequencerTrack) + count($sx1->Tracks->SequencerGroupTrack);
$offset2 = count($sx2->Tracks->SequencerTrack) + count($sx2->Tracks->SequencerGroupTrack);

// Use xpath to preserve order of SequencerTrack & SequencerGroupTrack

$track_types_1 = array();
$nodes = $sx1->xpath('/RenoiseSong/PatternPool/Patterns/Pattern/Tracks/*');
foreach($nodes as $x) {
    $attributes = $x->attributes();
    if ($attributes['type'] == 'PatternMasterTrack') {
        break;
    }
    else {
        $track_types_1[] = (string) $attributes['type'];
    }
}

$track_types_2 = array();
$nodes = $sx2->xpath('/RenoiseSong/PatternPool/Patterns/Pattern/Tracks/*');
foreach($nodes as $x) {
    $attributes = $x->attributes();
    if ($attributes['type'] == 'PatternMasterTrack') {
        break;
    }
    else {
        $track_types_2[] = (string) $attributes['type'];
    }
}

for ($i = 0; $i < $offset; ++$i) {
    foreach ($sx2->PatternPool->Patterns->Pattern as $x) {

        $track_type = $track_types_1[$i];
        simplexml_insert_before(
            $x->Tracks,
            simplexml_load_string("<$track_type type='$track_type' />"),
            $x->Tracks->PatternTrack[0]
            );
    }
}

for ($i = 0; $i < $offset2; ++$i) {
    foreach ($sx1->PatternPool->Patterns->Pattern as $x) {

        $track_type = $track_types_2[$i];
        simplexml_insert_before(
            $x->Tracks,
            simplexml_load_string("<$track_type type='$track_type' />"),
            $x->Tracks->PatternMasterTrack
            );
    }
}

// VSTi AssignedTrack (or AutoAssignedTrack, depending on Renoise XSD)
foreach ($sx2->Instruments->Instrument as $x) {
    if ($x->PluginProperties) {
        if ($x->PluginProperties->AssignedTrack && $x->PluginProperties->AssignedTrack >= 0) {
            $x->PluginProperties->AssignedTrack += $offset;
        }
    }
}

// Output routings
if ($sx2->OutputRoutings->OutputRouting) {
    foreach ($sx2->OutputRoutings->OutputRouting as $x) {
        if ($x->AssignedTrack != -1) {
            $x->AssignedTrack += $offset;
        }
    }
}

// Now do the send tracks (eg. to the right of the master track)
$offset = count($sx1->Tracks->SequencerSendTrack);
$offset2 = count($sx2->Tracks->SequencerSendTrack);

for ($i = 0; $i < $offset; ++$i) {
    foreach ($sx2->PatternPool->Patterns->Pattern as $x) {
        if (count($x->Tracks->PatternSendTrack)){

            simplexml_insert_before(
                $x->Tracks,
                simplexml_load_string('<PatternSendTrack type="PatternSendTrack"/>'),
                $x->Tracks->PatternSendTrack[0]
                );
        }
        else {

            simplexml_append(
                $x->Tracks,
                simplexml_load_string('<PatternSendTrack type="PatternSendTrack"/>')
                );
        }
    }
}

for ($i = 0; $i < $offset2; ++$i) {
    foreach ($sx1->PatternPool->Patterns->Pattern as $x) {

        simplexml_append(
            $x->Tracks,
            simplexml_load_string('<PatternSendTrack type="PatternSendTrack"/>')
            );
    }
}

// ----------------------------------------------------------------------------
// Patterns in song2 are shifted the total patterns in song1, adjust the
// PatternSequence pointer offsets accordingly.
// ----------------------------------------------------------------------------

$offset = count($sx1->PatternPool->Patterns->Pattern);
$offset2 = count($sx1->Tracks->SequencerTrack) + count($sx1->Tracks->SequencerGroupTrack);

for ($i = 0; $i < count($sx2->PatternSequence->SequenceEntries->SequenceEntry); ++$i) {
    for ($j = 0; $j < count($sx2->PatternSequence->SequenceEntries->SequenceEntry[$i]->Pattern); ++$j) {
        $sx2->PatternSequence->SequenceEntries->SequenceEntry[$i]->Pattern[$j] += $offset;
        if ($sx2->PatternSequence->SequenceEntries->SequenceEntry[$i]->MutedTracks->MutedTrack) {
            foreach ($sx2->PatternSequence->SequenceEntries->SequenceEntry[$i]->MutedTracks->MutedTrack as $x) {
                $x[0] += $offset2;
            }
        }
    }
}

// ----------------------------------------------------------------------------
// Instruments, VstiAutomationDevices, VSTi AliasInstrumentIndex and
// MidiCCDevice in song2 are shifted the total instruments in song1, adjust
// the pointer offsets accordingly.Copy the instruments with these same offsets
// ----------------------------------------------------------------------------

$offset = count($sx1->Instruments->Instrument);
$offset2 = count($sx1->PatternPool->Patterns->Pattern);

// Instruments, type="xs:string"

foreach ($sx2->PatternPool->Patterns->Pattern as $p) {
    foreach ($p->Tracks->PatternTrack as $x) {
        if ($x->AliasPatternIndex && $x->AliasPatternIndex > -1) {
            $x->AliasPatternIndex += $offset2;
        }
        if ($x->Lines->Line) {
            foreach ($x->Lines->Line as $y) {
                if ($y->NoteColumns->NoteColumn) {
                    foreach ($y->NoteColumns->NoteColumn as $z) {
                        if ($z->Instrument && '..' != $z->Instrument) {
                            list($instr) = sscanf($z->Instrument, '%x');
                            $z->Instrument = sprintf("%02X", $instr + $offset);
                        }
                    }
                }
            }
        }
    }
}

// VstiAutomationDevices
foreach (array('SequencerTrack', 'SequencerGroupTrack') as $track)
{
    foreach ($sx2->Tracks->$track as $x) {
        if ($x->FilterDevices->Devices->VstiAutomationDevice) {
            foreach ($x->FilterDevices->Devices->VstiAutomationDevice as $y) {
                $y->LinkedInstrument += $offset;
            }
        }
    }
}

// VSTi AliasInstrumentIndex
foreach ($sx2->Instruments->Instrument as $x) {
    if ($x->PluginProperties && $x->PluginProperties->AliasInstrumentIndex >= 0) {
        $x->PluginProperties->AliasInstrumentIndex += $offset;
    }
}

// MidiCCDevice, type="xs:string"
foreach (array('SequencerTrack', 'SequencerGroupTrack') as $track)
{
    foreach ($sx2->Tracks->$track as $x) {
        if ($x->FilterDevices->Devices->MidiCCDevice) {
            foreach ($x->FilterDevices->Devices->MidiCCDevice as $y) {
                $y->LinkedInstrument += $offset;
            }
        }
    }
}

// SampleData directory
if (is_dir($unzip2 . '/SampleData/')) {
    foreach(new DirectoryIterator($unzip2 . '/SampleData/') as $file) {
        if ($file == '.' || $file == '..') continue; // Skip these files

        // Source
        $source = $unzip2 . '/SampleData/' . $file;

        // Destination
        preg_match('/\d+/', $file, $matches); // returns $matches[] array
        $shift = str_pad(($matches[0]  + $offset), 2, '0', STR_PAD_LEFT);
        $dest = preg_replace('/(^\D+)(\d+)/', '$1@_REPLACE_@', $file);
        $dest = str_replace('@_REPLACE_@', $shift, $dest);
        $dest = $unzip1 . '/SampleData/' . $dest;

        // Copy
        dircopy($source, $dest);

    }
}

// ----------------------------------------------------------------------------
// Send devices
// ----------------------------------------------------------------------------

$offset = count($sx1->Tracks->SequencerSendTrack);

foreach (array('SequencerTrack', 'SequencerGroupTrack', 'SequencerSendTrack') as $track)
{
    foreach ($sx2->Tracks->$track as $x) {
        if ($x->FilterDevices->Devices->SendDevice) {
            foreach ($x->FilterDevices->Devices->SendDevice as $y) {
                $y->DestSendTrack->Value += $offset;
            }
        }
    }
}

// ----------------------------------------------------------------------------
// Out*DestSendTrack devices
// ----------------------------------------------------------------------------

$devices = array(
    'CrossoverDevice' => 3,
    );

$offset = count($sx1->Tracks->SequencerSendTrack);

foreach (array('SequencerTrack', 'SequencerGroupTrack', 'SequencerSendTrack') as $track)
{
    foreach ($devices as $device => $max)
    {
        foreach ($sx2->Tracks->$track as $x) {
            if ($x->FilterDevices->Devices->$device) {
                foreach ($x->FilterDevices->Devices->$device as $y) {
                    for ($k = 1; $k <= $max; ++$k) {
                        $tmp = 'Out' . $k . 'DestSendTrack';
                        $y->$tmp->Value += $offset;
                    }
                }
            }
        }
    }
}


/* ----------------------------------------------------------------------------
Note to self and future others:

+ Before: [TRACKS|MASTER|SENDS] (from 0 to X)
+ After: [TRACKS|TRACKS2|MASTER|SENDS|SENDS2] (still from 0 to X, but bigger)

`DestTrack->Value` counts differently than `DestSendTrack->Value`

Adjust offsets accordingly
---------------------------------------------------------------------------- */


// ----------------------------------------------------------------------------
// DestTrack devices
// ----------------------------------------------------------------------------

$devices = array(
    'FormulaMetaDevice',
    'KeyTrackingDevice',
    'LfoDevice',
    'MetaMixerDevice',
    'SignalFollowerDevice',
    'VelocityDevice',
    );

$offset = count($sx1->Tracks->SequencerTrack) + count($sx1->Tracks->SequencerGroupTrack);
$offset2 = count($sx2->Tracks->SequencerTrack) + count($sx2->Tracks->SequencerGroupTrack);
$offset3 = count($sx1->Tracks->SequencerSendTrack);

$track_types = array('SequencerTrack', 'SequencerGroupTrack', 'SequencerSendTrack');

// Tracks, use xpath to preserve order of SequencerTrack & SequencerGroupTrack

$nodes = $sx1->xpath('/RenoiseSong/Tracks/*');
foreach($nodes as $x)
{
    $attributes = $x->attributes();
    if (in_array($attributes['type'], $track_types))
    {
        foreach ($devices as $device)
        {
            if ($x->FilterDevices->Devices->$device) {
                foreach ($x->FilterDevices->Devices->$device as $y) {
                    if ($y->DestTrack->Value == -1) {
                        // Self
                        continue;
                    }
                    elseif ($y->DestTrack->Value >= $offset) {
                        // Offset tracks from sx2
                        $y->DestTrack->Value += $offset2;
                    }
                }
            }

        }
    }
}

$nodes = $sx2->xpath('/RenoiseSong/Tracks/*');
foreach($nodes as $x)
{
    $attributes = $x->attributes();
    if (in_array($attributes['type'], $track_types))
    {
        foreach ($devices as $device)
        {
            if ($x->FilterDevices->Devices->$device) {
                foreach ($x->FilterDevices->Devices->$device as $y) {
                    if ($y->DestTrack->Value == -1) {
                        // Self
                        continue;
                    }
                    elseif ($y->DestTrack->Value > $offset2) {
                        // Offset tracks from sx1 + sends from sx1
                        $y->DestTrack->Value += ($offset + $offset3);
                    }
                    else {
                        // Offset tracks from sx1
                        $y->DestTrack->Value += $offset;
                    }
                }
            }

        }
    }
}


// ----------------------------------------------------------------------------
// Out*DestTrack devices
// ----------------------------------------------------------------------------

$devices = array(
    'HydraDevice' => 9,
    'XYPadDevice' => 2,
    );

$offset = count($sx1->Tracks->SequencerTrack) + count($sx1->Tracks->SequencerGroupTrack);
$offset2 = count($sx2->Tracks->SequencerTrack) + count($sx2->Tracks->SequencerGroupTrack);
$offset3 = count($sx1->Tracks->SequencerSendTrack);

$track_types = array('SequencerTrack', 'SequencerGroupTrack', 'SequencerSendTrack');

// Tracks, use xpath to preserve order of SequencerTrack & SequencerGroupTrack

$nodes = $sx1->xpath('/RenoiseSong/Tracks/*');
foreach($nodes as $x)
{
    $attributes = $x->attributes();
    if (in_array($attributes['type'], $track_types))
    {
        foreach ($devices as $device => $max)
        {
            if ($x->FilterDevices->Devices->$device) {
                foreach ($x->FilterDevices->Devices->$device as $y)
                {
                    for ($k = 1; $k <= $max; ++$k)
                    {
                        $tmp = 'Out' . $k . 'DestTrack';
                        if ($y->$tmp->Value == -1) {
                            // Self
                            continue;
                        }
                        elseif ($y->$tmp->Value >= $offset) {
                            // Offset tracks from sx2
                            $y->$tmp->Value += $offset2;
                        }
                    }
                }
            }

        }
    }
}

$nodes = $sx2->xpath('/RenoiseSong/Tracks/*');
foreach($nodes as $x)
{
    $attributes = $x->attributes();
    if (in_array($attributes['type'], $track_types))
    {
        foreach ($devices as $device => $max)
        {
            if ($x->FilterDevices->Devices->$device) {
                foreach ($x->FilterDevices->Devices->$device as $y)
                {
                    for ($k = 1; $k <= $max; ++$k)
                    {
                        $tmp = 'Out' . $k . 'DestTrack';
                        if ($y->$tmp->Value == -1) {
                            // Self
                            continue;
                        }
                        elseif ($y->$tmp->Value > $offset2) {
                            // Offset tracks from sx1 + sends from sx1
                            $y->$tmp->Value += ($offset + $offset3);
                        }
                        else {
                            // Offset tracks from sx1
                            $y->$tmp->Value += $offset;
                        }
                    }
                }
            }

        }
    }
}

// ----------------------------------------------------------------------------
// Append all the instruments, tracks, sends, patterns, and sequences from
// song2 into song1.
// ----------------------------------------------------------------------------

// Instruments
foreach ($sx2->Instruments->Instrument as $x) {
    simplexml_append($sx1->Instruments, $x);
}

// Tracks, use xpath to preserve order of SequencerTrack & SequencerGroupTrack
$nodes = $sx2->xpath('/RenoiseSong/Tracks/*');
foreach($nodes as $x) {
    $attributes = $x->attributes();
    if ($attributes['type'] == 'SequencerMasterTrack') {
        break;
    }
    else {
        simplexml_insert_before($sx1->Tracks, $x, $sx1->Tracks->SequencerMasterTrack);
    }
}

// SendTracks
foreach ($sx2->Tracks->SequencerSendTrack as $x) {
    simplexml_append($sx1->Tracks, $x);
}

// Patterns
foreach ($sx2->PatternPool->Patterns->Pattern as $x) {
    simplexml_append($sx1->PatternPool->Patterns, $x);
}

// PatternSequence
foreach ($sx2->PatternSequence->SequenceEntries->SequenceEntry as $x) {
    simplexml_append($sx1->PatternSequence->SequenceEntries, $x);
}

// Prevent Renoise from crashing ...
$sx1->SelectedTrackIndex = 0;

// Try to free memory
unset($sx2);

// ----------------------------------------------------------------------------
// Validate
// ----------------------------------------------------------------------------

if (!xrns_xsd_check($sx1, (int)$sx1['doc_version'])) {

    abort("Error: XML is invalid!\n", array($unzip1, $unzip2));
}

// ----------------------------------------------------------------------------
// Replace Song.xml
// ----------------------------------------------------------------------------

unlink($unzip1 . 'Song.xml') or die("Error: There was a problem deleting a file.\n");

file_put_contents($unzip1 . 'Song.xml', $sx1->asXML());

// Zip song
$result = ZipAllFiles($song3, $unzip1);
if($result === FALSE) {
    echo "Error: There was a problem zipping the final file.\n";
    die();
}

// ----------------------------------------------------------------------------
// Remove temp directories
// ----------------------------------------------------------------------------

obliterate_directory($unzip1);
obliterate_directory($unzip2);

echo "---------------------------------------\n";
echo "$argv[0] is done!\n";
echo date("D M j G:i:s T Y\n");
echo "---------------------------------------\n";

?>
