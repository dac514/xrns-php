<?php

namespace XrnsPhp;

class File
{
    /**
     * Fullpath to temporary directory
     *
     * @var string
     */
    protected $tmpDir = '/tmp';

    /**
     * Fullpath to $this file as string
     *
     * @var string
     */
    protected $file;

    /**
     * Directories to delete on __destruct
     *
     * @var array
     */
    protected $dirsToCleanup = array();


    /**
     * @param string $file
     */
    function __construct($file)
    {
        $this->createTmpDir();
        $this->setFileAndUnzip($file);
    }


    /**
     *
     */
    function __destruct()
    {
        foreach ($this->dirsToCleanup as $dir) {
            $this->obliterateDirectory($dir);
        }
    }


    /**
     * @return string
     */
    function __toString()
    {
        return $this->file;
    }


    /**
     * @return string
     */
    function getDirname()
    {
        return $filename = pathinfo($this->file, PATHINFO_DIRNAME);
    }


    /**
     * @return string
     */
    function getBasename()
    {
        return $filename = pathinfo($this->file, PATHINFO_BASENAME);
    }


    /**
     * @return string
     */
    function getExtension()
    {
        return pathinfo($this->file, PATHINFO_EXTENSION);
    }


    /**
     * @return string
     */
    function getTmpDir()
    {
        return $this->tmpDir;
    }


    /**
     * Zip $this
     *
     * @param string $zipFileName
     * @return bool
     * @throws Exception\ExecutableNotFound
     * @throws Exception\FileOperation
     */
    function zip($zipFileName)
    {
        $path_to_zip = 'zip';
        $unusedArrayResult = array();

        if (!command_exists($path_to_zip)) {
            throw new Exception\ExecutableNotFound('Cannot find info-zip executable: zip');
        }

        $zipCmd = $path_to_zip . ' -r "@_DST_@" .';
        $zipCmd = str_replace('@_DST_@', basename($zipFileName), $zipCmd);

        $dirToZip = $this->getTmpDir() . '/';

        // Change dir to get relative path for info-zip
        $cwd = getcwd();
        chdir($dirToZip);

        $res = -1; // any nonzero value
        exec($zipCmd, $UnusedArrayResult, $res);

        if ($res != 0)
            trigger_error("Zip command returned error code $res", E_USER_WARNING);

        // Back to previous working directory
        chdir($cwd);

        // Absolute or relative path?
        // Look for (/, \, x:/ or x:\) at the beginning of the string
        $cwd .= '/';
        if (
            preg_match('#^/#', $zipFileName) ||
            preg_match("/^\\\/", $zipFileName) ||
            preg_match("#^\w:/#", $zipFileName) ||
            preg_match("/^\w:\\\/", $zipFileName)
        ) {
            $cwd = '';
        }

        // Does the file already exist?
        if (is_file($cwd . $zipFileName)) {
            throw new Exception\FileOperation("Error: {$cwd}{$zipFileName} already exists.\n");
        }

        // Copy
        if (!copy($dirToZip . basename($zipFileName), $cwd . $zipFileName)) {
            throw new Exception\FileOperation("Error: Failed to copy {$cwd}{$zipFileName}.\n");
        }

        // http://www.info-zip.org/FAQ.html#error-codes
        if ($res != 0) {
            throw new Exception\FileOperation("There was a problem zipping the file {$zipFileName} [ Error code: $res ]");
        }
    }


    /**
     * @throws Exception\FileOperation
     */
    protected function createTmpDir()
    {
        $tempfile = tempnam(sys_get_temp_dir(), '');
        if (file_exists($tempfile)) {
            unlink($tempfile);
        }
        mkdir($tempfile);
        if (is_dir($tempfile)) {
            $this->tmpDir = $tempfile;
            $this->dirsToCleanup[] = $tempfile;
        }
        else {
            throw new Exception\FileOperation("Could not create temporary directory");
        }

    }


    /**
     * @param string $file
     * @throws Exception\FileOperation
     */
    protected function setFileAndUnzip($file)
    {
        if (!file_exists($file)) {
            throw new Exception\FileOperation("Error: The file '$file'' was not found.");
        }

        $this->file = realpath($file);
        $this->unzip($this->file, $this->tmpDir);
    }


    /**
     * @param string $zipFile
     * @param string $dstDir
     * @return $this
     * @throws Exception\ExecutableNotFound
     * @throws Exception\FileOperation
     */
    protected function unzip($zipFile, $dstDir)
    {
        $path_to_unzip = 'unzip';
        $unusedArrayResult = array();

        if (!command_exists($path_to_unzip)) {
            throw new Exception\ExecutableNotFound('Cannot find info-zip executable: unzip');
        }

        $unzip_xrns = $path_to_unzip . ' "@_SRC_@" -d "@_DST_@"';
        $unzipCmd = $unzip_xrns;
        $unzipCmd = str_replace('@_SRC_@', $zipFile, $unzipCmd);
        $unzipCmd = str_replace('@_DST_@', $dstDir, $unzipCmd);
        $res = -1; // any nonzero value
        exec($unzipCmd, $unusedArrayResult, $res);

        if ($res != 0)
            trigger_error("UnzipAllFiles() return_val was $res", E_USER_WARNING);

        // http://www.info-zip.org/FAQ.html#error-codes
        if ($res == 0 || $res == 1) {
            return $this;
        }
        else {
            throw new Exception\FileOperation("Could not unzip the file: $zipFile into: $dstDir");
        }
    }


    /**
     * @param $dirname
     * @param bool $only_empty
     * @return bool
     */
    private function obliterateDirectory($dirname, $only_empty = false)
    {
        if (!is_dir($dirname))
            return false;

        if (isset($_ENV['OS']) && strripos($_ENV['OS'], "windows", 0) !== FALSE) {
            // Windows patch for buggy perimssions on some machines
            $command = 'cmd /C "rmdir /S /Q "' . str_replace('//', '\\', $dirname) . '\\""';
            $wsh = new \COM("WScript.Shell");
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
                    while ($f = readdir($d)) {
                        if ($f == '.' || $f == '..') continue;
                        $f = $dcur . '/' . $f;
                        if (is_dir($f)) $dscan[] = $f;
                        else unlink($f);
                    }
                    closedir($d);
                }
            }
            $i_until = ($only_empty) ? 1 : 0;
            for ($i = count($darr) - 1; $i >= $i_until; $i--) {
                if (!rmdir($darr[$i]))
                    trigger_error("There was a problem deleting a temporary file in $dirname", E_USER_WARNING);
            }

            return (($only_empty) ? (count(scandir($dirname)) <= 2) : (!is_dir($dirname)));
        }
    }


}
