<?php

namespace XrnsPhp;

class Merge
{

    /**
     * @var File
     */
    protected $file1;

    /**
     * @var File
     */
    protected $file2;

    /**
     * @var \SimpleXMLElement
     */
    protected $sx1;

    /**
     * @var \SimpleXMLElement
     */
    protected $sx2;


    /**
     * @param File $file1
     * @param File $file2
     */
    function __construct(File $file1, File $file2)
    {
        $this->file1 = $file1;
        $this->file2 = $file2;

        $this->sx1 = simplexml_load_file($file1->getTmpDir() . '/Song.xml');
        $this->sx2 = simplexml_load_file($file2->getTmpDir() . '/Song.xml');

        $this->sanityCheck();
    }


    /**
     * Note to self and future others:
     *
     *   + Before merge: [TRACKS1|MASTER1|SENDS] (from 0 to X)
     *   + After merge: [TRACKS1|TRACKS2|MASTER1|SENDS1|SENDS2] (still from 0 to X, but a bigger range)
     *
     * `DestTrack->Value` counts differently than `DestSendTrack->Value`
     *
     * Adjust offsets accordingly
     */
    function merge()
    {
        $this->makeTracksPlaceholers();
        $this->makePatternPlaceholders();

        $this->fixInstrumentOffsets();
        $this->fixSendDevicesOffets();
        $this->fixDestSendDevicesOffsets();
        $this->fixDestTrackDevicesOffsets();
        $this->fixOutDestTrackDevicesOffsets();

        $this->copySampleDataFromSong2IntoSong1();
        $this->copyXmlDataFromSong2IntoSong1();

        // Try to free memory
        unset($this->sx2);

        if (!$this->validate()) {
            throw new Exception\FileOperation("XML is invalid.");
        }

        $this->replaceSong1XmlFile();
    }


    /**
     * @throws Exception\FileOperation
     */
    protected function sanityCheck()
    {
        // Did we load anything?

        if (!$this->sx1 || !$this->sx2) {
            throw new Exception\FileOperation("Cannot load Song.xml, file is corrupted?");
        }

        // Check doc_version

        if ((string)$this->sx1['doc_version'] != (string)$this->sx2['doc_version']) {
            $err = "Error: Unsupported, cannot merge different versions.\n";
            $err .= "Save your songs with the same version of Renoise and try again.\n";
            throw new Exception\FileOperation($err);
        }

        if ((string)$this->sx1['doc_version'] < 21) {
            $err = "Error: Unsupported version.\n";
            $err .= "Save your songs with a newer version of Renoise and try again.\n";
            throw new Exception\FileOperation($err);
        }

        // Check if resulting merge would break maximum FF instruments limitation

        if ((count($this->sx1->Instruments->Instrument) + count($this->sx2->Instruments->Instrument)) > 255) {
            $err = ("Error: Unsupported, too many instruments.\n");
            throw new Exception\FileOperation($err);
        }

    }


    /**
     * To merge two songs, we start by appending the total tracks and sends in song2
     * as empty offsets to song1. We must also prepend the total tracks and sends in
     * song1 as empty offsets to song2.
     */
    protected function makeTracksPlaceholers()
    {
        // Start with sequencer tracks (eg. to the left of the master track)
        $offset = count($this->sx1->Tracks->SequencerTrack) + count($this->sx1->Tracks->SequencerGroupTrack);
        $offset2 = count($this->sx2->Tracks->SequencerTrack) + count($this->sx2->Tracks->SequencerGroupTrack);

        // Use Xpath to preserve order of SequencerTrack & SequencerGroupTrack

        $track_types_1 = array();
        $nodes = $this->sx1->xpath('/RenoiseSong/PatternPool/Patterns/Pattern/Tracks/*');
        foreach ($nodes as $x) {
            $attributes = $x->attributes();
            if ($attributes['type'] == 'PatternMasterTrack') {
                break;
            }
            else {
                $track_types_1[] = (string)$attributes['type'];
            }
        }

        $track_types_2 = array();
        $nodes = $this->sx2->xpath('/RenoiseSong/PatternPool/Patterns/Pattern/Tracks/*');
        foreach ($nodes as $x) {
            $attributes = $x->attributes();
            if ($attributes['type'] == 'PatternMasterTrack') {
                break;
            }
            else {
                $track_types_2[] = (string)$attributes['type'];
            }
        }

        for ($i = 0; $i < $offset; ++$i) {
            foreach ($this->sx2->PatternPool->Patterns->Pattern as $x) {

                $track_type = $track_types_1[$i];
                simplexml_insert_before(
                    $x->Tracks,
                    simplexml_load_string("<$track_type type='$track_type' />"),
                    $x->Tracks->PatternTrack[0]
                );
            }
        }

        for ($i = 0; $i < $offset2; ++$i) {
            foreach ($this->sx1->PatternPool->Patterns->Pattern as $x) {

                $track_type = $track_types_2[$i];
                simplexml_insert_before(
                    $x->Tracks,
                    simplexml_load_string("<$track_type type='$track_type' />"),
                    $x->Tracks->PatternMasterTrack
                );
            }
        }

        // AssignedTrack routings
        foreach ($this->sx2->Instruments->Instrument as $x) {
            if ($x->PluginProperties->OutputRoutings->OutputRouting) {
                foreach ($x->PluginProperties->OutputRoutings->OutputRouting as $y) {
                    if ($y->AssignedTrack != -1) {
                        $y->AssignedTrack += $offset;
                    }
                }
            }
            if ($x->MidiInputProperties->AssignedTrack != -1) {
                $x->MidiInputProperties->AssignedTrack += $offset;
            }
        }

        // Now do the send tracks (eg. to the right of the master track)
        $offset = count($this->sx1->Tracks->SequencerSendTrack);
        $offset2 = count($this->sx2->Tracks->SequencerSendTrack);

        for ($i = 0; $i < $offset; ++$i) {
            foreach ($this->sx2->PatternPool->Patterns->Pattern as $x) {
                if (count($x->Tracks->PatternSendTrack)) {

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
            foreach ($this->sx1->PatternPool->Patterns->Pattern as $x) {

                simplexml_append(
                    $x->Tracks,
                    simplexml_load_string('<PatternSendTrack type="PatternSendTrack"/>')
                );
            }
        }

    }


    /**
     * Patterns in song2 are shifted the total patterns in song1, adjust the
     * PatternSequence pointer offsets accordingly.
     */
    protected function makePatternPlaceholders()
    {
        $offset = count($this->sx1->PatternPool->Patterns->Pattern);
        $offset2 = count($this->sx1->Tracks->SequencerTrack) + count($this->sx1->Tracks->SequencerGroupTrack);

        for ($i = 0; $i < count($this->sx2->PatternSequence->SequenceEntries->SequenceEntry); ++$i) {
            for ($j = 0; $j < count($this->sx2->PatternSequence->SequenceEntries->SequenceEntry[$i]->Pattern); ++$j) {
                $this->sx2->PatternSequence->SequenceEntries->SequenceEntry[$i]->Pattern[$j] += $offset;
                if ($this->sx2->PatternSequence->SequenceEntries->SequenceEntry[$i]->MutedTracks->MutedTrack) {
                    foreach ($this->sx2->PatternSequence->SequenceEntries->SequenceEntry[$i]->MutedTracks->MutedTrack as $x) {
                        $x[0] += $offset2;
                    }
                }
            }
        }

    }


    /**
     * Instruments, VstiAutomationDevices, VSTi AliasInstrumentIndex and
     * MidiCCDevice in song2 are shifted the total instruments in song1, adjust
     * the pointer offsets accordingly. Copy the instruments with these same offsets
     */
    protected function fixInstrumentOffsets()
    {
        $offset = count($this->sx1->Instruments->Instrument);
        $offset2 = count($this->sx1->PatternPool->Patterns->Pattern);

        // Instruments, type="xs:string"
        foreach ($this->sx2->PatternPool->Patterns->Pattern as $p) {
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
        foreach (array('SequencerTrack', 'SequencerGroupTrack') as $track) {
            foreach ($this->sx2->Tracks->$track as $x) {
                if ($x->FilterDevices->Devices->VstiAutomationDevice) {
                    foreach ($x->FilterDevices->Devices->VstiAutomationDevice as $y) {
                        $y->LinkedInstrument += $offset;
                    }
                }
            }
        }

        // VSTi AliasInstrumentIndex
        foreach ($this->sx2->Instruments->Instrument as $x) {
            if ($x->PluginProperties && $x->PluginProperties->AliasInstrumentIndex >= 0) {
                $x->PluginProperties->AliasInstrumentIndex += $offset;
            }
        }

        // MidiCCDevice, type="xs:string"
        foreach (array('SequencerTrack', 'SequencerGroupTrack') as $track) {
            foreach ($this->sx2->Tracks->$track as $x) {
                if ($x->FilterDevices->Devices->MidiCCDevice) {
                    foreach ($x->FilterDevices->Devices->MidiCCDevice as $y) {
                        $y->LinkedInstrument += $offset;
                    }
                }
            }
        }


    }


    /**
     * Copy and rename SampleData directories from Song2 into Song1
     */
    protected function copySampleDataFromSong2IntoSong1()
    {
        $offset = count($this->sx1->Instruments->Instrument);
        $unzip1 = $this->file1->getTmpDir();
        $unzip2 = $this->file2->getTmpDir();

        // SampleData directory
        if (is_dir($unzip2 . '/SampleData/')) {

            if (!is_dir($unzip1 . '/SampleData/')) {
                $ret = mkdir($unzip1 . '/SampleData/');
            }

            foreach (new \DirectoryIterator($unzip2 . '/SampleData/') as $file) {

                if (false == $file->isDir() || '.' == $file || '..' == $file)
                    continue; // Skip these files

                // Source
                $source = $unzip2 . '/SampleData/' . $file;

                // Destination
                preg_match('/\d+/', $file, $matches); // returns $matches[] array
                $shift = str_pad(($matches[0] + $offset), 2, '0', STR_PAD_LEFT);
                $dest = preg_replace('/(^\D+)(\d+)/', '$1@_REPLACE_@', $file);
                $dest = str_replace('@_REPLACE_@', $shift, $dest);
                $dest = $unzip1 . '/SampleData/' . $dest;

                // Copy
                $this->dircopy($source, $dest);
            }
        }

    }


    /**
     * Send devices
     */
    protected function fixSendDevicesOffets()
    {
        $offset = count($this->sx1->Tracks->SequencerSendTrack);

        foreach (array('SequencerTrack', 'SequencerGroupTrack', 'SequencerSendTrack') as $track) {
            foreach ($this->sx2->Tracks->$track as $x) {
                if ($x->FilterDevices->Devices->SendDevice) {
                    foreach ($x->FilterDevices->Devices->SendDevice as $y) {
                        $y->DestSendTrack->Value += $offset;
                    }
                }
            }
        }

    }


    /**
     * Out*DestSendTrack devices
     */
    protected function fixDestSendDevicesOffsets()
    {
        $devices = array(
            'CrossoverDevice' => 3,
        );

        $offset = count($this->sx1->Tracks->SequencerSendTrack);

        foreach (array('SequencerTrack', 'SequencerGroupTrack', 'SequencerSendTrack') as $track) {
            foreach ($devices as $device => $max) {
                foreach ($this->sx2->Tracks->$track as $x) {
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

    }


    /**
     * DestTrack devices
     */
    protected function fixDestTrackDevicesOffsets()
    {
        $devices = array(
            'FormulaMetaDevice',
            'KeyTrackingDevice',
            'LfoDevice',
            'MetaMixerDevice',
            'SignalFollowerDevice',
            'VelocityDevice',
        );

        $offset = count($this->sx1->Tracks->SequencerTrack) + count($this->sx1->Tracks->SequencerGroupTrack);
        $offset2 = count($this->sx2->Tracks->SequencerTrack) + count($this->sx2->Tracks->SequencerGroupTrack);
        $offset3 = count($this->sx1->Tracks->SequencerSendTrack);

        $track_types = array('SequencerTrack', 'SequencerGroupTrack', 'SequencerSendTrack');

        // Tracks, use xpath to preserve order of SequencerTrack & SequencerGroupTrack

        $nodes = $this->sx1->xpath('/RenoiseSong/Tracks/*');
        foreach ($nodes as $x) {
            $attributes = $x->attributes();
            if (in_array($attributes['type'], $track_types)) {
                foreach ($devices as $device) {
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

        $nodes = $this->sx2->xpath('/RenoiseSong/Tracks/*');
        foreach ($nodes as $x) {
            $attributes = $x->attributes();
            if (in_array($attributes['type'], $track_types)) {
                foreach ($devices as $device) {
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

    }


    /**
     * Out*DestTrack devices
     */
    protected function fixOutDestTrackDevicesOffsets()
    {
        $devices = array(
            'HydraDevice' => 9,
            'XYPadDevice' => 2,
        );

        $offset = count($this->sx1->Tracks->SequencerTrack) + count($this->sx1->Tracks->SequencerGroupTrack);
        $offset2 = count($this->sx2->Tracks->SequencerTrack) + count($this->sx2->Tracks->SequencerGroupTrack);
        $offset3 = count($this->sx1->Tracks->SequencerSendTrack);

        $track_types = array('SequencerTrack', 'SequencerGroupTrack', 'SequencerSendTrack');

        // Tracks, use xpath to preserve order of SequencerTrack & SequencerGroupTrack

        $nodes = $this->sx1->xpath('/RenoiseSong/Tracks/*');
        foreach ($nodes as $x) {
            $attributes = $x->attributes();
            if (in_array($attributes['type'], $track_types)) {
                foreach ($devices as $device => $max) {
                    if ($x->FilterDevices->Devices->$device) {
                        foreach ($x->FilterDevices->Devices->$device as $y) {
                            for ($k = 1; $k <= $max; ++$k) {
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

        $nodes = $this->sx2->xpath('/RenoiseSong/Tracks/*');
        foreach ($nodes as $x) {
            $attributes = $x->attributes();
            if (in_array($attributes['type'], $track_types)) {
                foreach ($devices as $device => $max) {
                    if ($x->FilterDevices->Devices->$device) {
                        foreach ($x->FilterDevices->Devices->$device as $y) {
                            for ($k = 1; $k <= $max; ++$k) {
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

    }


    /**
     * Append all the instruments, tracks, sends, patterns, and sequences from
     * song2 into song1.
     */
    protected function copyXmlDataFromSong2IntoSong1()
    {

        // Instruments
        foreach ($this->sx2->Instruments->Instrument as $x) {
            simplexml_append($this->sx1->Instruments, $x);
        }

        // Tracks, use xpath to preserve order of SequencerTrack & SequencerGroupTrack
        $nodes = $this->sx2->xpath('/RenoiseSong/Tracks/*');
        foreach ($nodes as $x) {
            $attributes = $x->attributes();
            if ($attributes['type'] == 'SequencerMasterTrack') {
                break;
            }
            else {
                simplexml_insert_before($this->sx1->Tracks, $x, $this->sx1->Tracks->SequencerMasterTrack);
            }
        }

        // SendTracks
        foreach ($this->sx2->Tracks->SequencerSendTrack as $x) {
            simplexml_append($this->sx1->Tracks, $x);
        }

        // Patterns
        foreach ($this->sx2->PatternPool->Patterns->Pattern as $x) {
            simplexml_append($this->sx1->PatternPool->Patterns, $x);
        }

        // PatternSequence
        foreach ($this->sx2->PatternSequence->SequenceEntries->SequenceEntry as $x) {
            simplexml_append($this->sx1->PatternSequence->SequenceEntries, $x);
        }

        // Prevent Renoise from crashing ...
        $this->sx1->SelectedTrackIndex = 0;
    }


    /**
     * @return bool
     */
    protected function validate()
    {
        $xsd = 'RenoiseSong' . (int)$this->sx1['doc_version'] . '.xsd';
        $schema = realpath(__DIR__ . "/../schemas/$xsd");

        if (!$schema) {
            trigger_error("Warning: $xsd not found, skipping XML validation.", E_USER_WARNING);

            return true;
        }

        $dd = new \DOMDocument;
        $dd->loadXML($this->sx1->asXML());
        if ($dd->schemaValidate($schema)) {
            return true;
        }

        return false;
    }


    /**
     * Replace Song.xml
     */
    protected function replaceSong1XmlFile()
    {
        $unzipDir = $this->file1->getTmpDir();

        if (!unlink($unzipDir . '/Song.xml')) {
            throw new Exception\FileOperation("There was a problem deleting Song.xml");
        }

        file_put_contents($unzipDir . '/Song.xml', $this->sx1->asXML());
    }


    /**
     * @param string $srcdir
     * @param string $dstdir
     * @param bool $verbose
     * @return int
     * @throws Exception\FileOperation
     */
    protected function dircopy($srcdir, $dstdir, $verbose = false)
    {
        $num = 0;
        if (!is_dir($dstdir)) mkdir($dstdir);
        if ($curdir = opendir($srcdir)) {
            while ($file = readdir($curdir)) {
                if ($file != '.' && $file != '..') {
                    $srcfile = $srcdir . '/' . $file;
                    $dstfile = $dstdir . '/' . $file;
                    if (is_file($srcfile)) {
                        if (is_file($dstfile)) $ow = filemtime($srcfile) - filemtime($dstfile);
                        else $ow = 1;
                        if ($ow > 0) {
                            if ($verbose) echo "Copying '$srcfile' to '$dstfile'...";
                            if (copy($srcfile, $dstfile)) {
                                touch($dstfile, filemtime($srcfile));
                                $num++;
                                if ($verbose) echo "OK\n";
                            }
                            else throw new Exception\FileOperation("Error: File '$srcfile' could not be copied.\n");
                        }
                    }
                    else if (is_dir($srcfile)) {
                        $num += $this->dircopy($srcfile, $dstfile, $verbose);
                    }
                }
            }
            closedir($curdir);
        }

        return $num;
    }


}
