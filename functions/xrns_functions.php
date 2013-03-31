<?php

// Use maximum memory
ini_set('memory_limit', '-1');

// Supress warnings in PHP 5.4 + XRNS-SF
date_default_timezone_set(@date_default_timezone_get());

// ----------------------------------------------------------------------------
// Functions
// ----------------------------------------------------------------------------

function simplexml_append(SimpleXMLElement $parent, SimpleXMLElement $new_child){
    $node1 = dom_import_simplexml($parent);
    $dom_sxe = dom_import_simplexml($new_child);
    $node2 = $node1->ownerDocument->importNode($dom_sxe, true);
    $node1->appendChild($node2);
}

function simplexml_insert_before(SimpleXMLElement $parent, SimpleXMLElement $new_child, SimpleXMLElement $before){
    $node1 = dom_import_simplexml($parent);
    $dom_sxe = dom_import_simplexml($new_child);
    $node2 = $node1->ownerDocument->importNode($dom_sxe, true);
    $node1->insertBefore($node2, dom_import_simplexml($before));
}

function UnzipAllFiles($zipFile, $zipDir) {

    $unzip_xrns = 'unzip "@_SRC_@" -d "@_DST_@"'; // info-zip assumed to be in path
    $unzipCmd = $unzip_xrns;
    $unzipCmd = str_replace('@_SRC_@', $zipFile, $unzipCmd);
    $unzipCmd = str_replace('@_DST_@', $zipDir, $unzipCmd);
    $res = -1; // any nonzero value
    $UnusedArrayResult = array();
    $UnusedStringResult = exec($unzipCmd, $UnusedArrayResult, $res);

    if ($res != 0) echo "Warning: UnzipAllFiles() return_val was $res\n";

    return ($res == 0 || $res == 1); // http://www.info-zip.org/FAQ.html#error-codes
}

function ZipAllFiles($zipFile, $zipDir) {

    $zipCmd = 'zip -r "@_DST_@" .'; // info-zip assumed to be in path
    $zipCmd = str_replace('@_DST_@', basename($zipFile), $zipCmd);

    // Change dir to get relative path for info-zip
    $cwd = getcwd();
    chdir($zipDir);

    $res = -1; // any nonzero value
    $UnusedArrayResult = array();
    $UnusedStringResult = exec($zipCmd, $UnusedArrayResult, $res);

    if ($res != 0) echo "Warning: ZipAllFiles() return_val was $res\n";

    // Back to previous working directory
    chdir($cwd);

    // Absolute or relative path?
    // Look for (/, \, x:/ or x:\) at the beginning of the string
    $cwd .= '/';
    if (
        preg_match('#^/#', $zipFile) ||
        preg_match("/^\\\/", $zipFile) ||
        preg_match("#^\w:/#", $zipFile) ||
        preg_match("/^\w:\\\/", $zipFile)
        )
    {
        $cwd = '';
    }

    // Does the file already exist?
    if (is_file($cwd . $zipFile)) {
        die("Error: {$cwd}{$zipFile} already exists...\n");
    }

    // Copy
    if (!copy($zipDir . basename($zipFile), $cwd . $zipFile)) {
        die("Error: Failed to copy {$cwd}{$zipFile}...\n");
    }

    return ($res == 0); // http://www.info-zip.org/FAQ.html#error-codes
}

function dircopy($srcdir, $dstdir, $verbose = false) {
    $num = 0;
    if(!is_dir($dstdir)) mkdir($dstdir);
    if($curdir = opendir($srcdir)) {
        while($file = readdir($curdir)) {
            if($file != '.' && $file != '..') {
                $srcfile = $srcdir . '/' . $file;
                $dstfile = $dstdir . '/' . $file;
                if(is_file($srcfile)) {
                    if(is_file($dstfile)) $ow = filemtime($srcfile) - filemtime($dstfile); else $ow = 1;
                    if($ow > 0) {
                        if($verbose) echo "Copying '$srcfile' to '$dstfile'...";
                        if(copy($srcfile, $dstfile)) {
                            touch($dstfile, filemtime($srcfile)); $num++;
                            if($verbose) echo "OK\n";
                        }
                        else die("Error: File '$srcfile' could not be copied.\n");
                    }
                }
                else if(is_dir($srcfile)) {
                    $num += dircopy($srcfile, $dstfile, $verbose);
                }
            }
        }
        closedir($curdir);
    }
    return $num;
}

function obliterate_directory($dirname, $only_empty = false) {
    if (!is_dir($dirname)) return false;
    if (isset($_ENV['OS']) && strripos($_ENV['OS'], "windows", 0) !== FALSE) {
        // Windows patch for buggy perimssions on some machines
        $command = 'cmd /C "rmdir /S /Q "'.str_replace('//', '\\', $dirname).'\\""';
        $wsh = new COM("WScript.Shell");
        $wsh->Run($command, 7, false);
        $wsh = null;
        sleep(1); // Hack, give windows some time to finish...
        return true;
    }
    else {
        $dscan = array(realpath($dirname));
        $darr = array();
        while (!empty($dscan)) {
            $dcur = array_pop($dscan);
            $darr[] = $dcur;
            if ($d = opendir($dcur)) {
                while ($f=readdir($d)) {
                    if ($f == '.' || $f == '..') continue;
                    $f=$dcur.'/'.$f;
                    if (is_dir($f)) $dscan[] = $f;
                    else unlink($f);
                }
                closedir($d);
            }
        }
        $i_until = ($only_empty)? 1 : 0;
        for ($i=count($darr)-1; $i>=$i_until; $i--) {
            if (!rmdir($darr[$i])) echo ("Warning: There was a problem deleting a temporary file in $dirname\n");
        }
        return (($only_empty)? (count(scandir($dirname))<=2) : (!is_dir($dirname)));
    }
}

function get_temp_dir() {
    // Try to get from environment variable
    if (!empty($_ENV['TMP'])) {
        return realpath($_ENV['TMP']);
    }
    else if (!empty($_ENV['TMPDIR'])) {
        return realpath($_ENV['TMPDIR']);
    }
    else if (!empty($_ENV['TEMP'])) {
        return realpath($_ENV['TEMP']);
    }
    else {
        // Detect by creating a temporary file
        $temp_file = tempnam(md5(uniqid(mt_rand(), true)), '');
        if ($temp_file) {
            $temp_dir = realpath(dirname($temp_file));
            unlink($temp_file);
            return $temp_dir;
        }
        else {
            return false;
        }
    }
}


function xrns_xsd_check($sx, $doc_version) {

    global $argv;

    $xsd = 'RenoiseSong' . (int)$doc_version . '.xsd';
    $schema = str_replace($argv[0], __DIR__ . "/../schemas/$xsd", $_SERVER['SCRIPT_NAME']);

    if (file_exists($schema)) {
        $dd = new DOMDocument;
        $dd->loadXML($sx->asXML());
        if ($dd->schemaValidate($schema)) return true;
    }
    else {
        echo "Warning: $schema not found, skipping XML validation.\n";
        return true;
    }

    return false;

}


?>
