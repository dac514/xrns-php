<?php

namespace XrnsPhp;

class Ogg
{

    /**
     * @var File
     */
    protected $file;


    /**
     * @param File $file
     */
    function __construct(File $file)
    {
        $this->file = $file;
    }


    /**
     * @param int $quality (optional)
     * @throws Exception\FileOperation
     */
    function compress($quality = 3)
    {
        $sampleDataPath = $this->file->getTmpDir() . '/SampleData/';

        if (is_dir($sampleDataPath)) {
            $this->compressDir($sampleDataPath, $quality);
        }
        else {
            trigger_error("Cannot find SampleData/ directory, nothing to compress.", E_USER_WARNING);
        }

    }


    /**
     * @param string $directory
     * @param int $quality (optional)
     */
    protected function compressDir($directory, $quality = 3)
    {
        $directoryIterator = new \RecursiveDirectoryIterator($directory);
        $recursiveIterator = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::CHILD_FIRST);

        try {
            foreach ($recursiveIterator as $fullFileName => $fileObj) {
                if ($fileObj->isFile()) {
                    $this->compressFile($fullFileName, $quality);
                }
            }
        }
        catch (\UnexpectedValueException $e) {
            trigger_error("Directory [%s] contained a directory we can not recurse into $directory", E_USER_WARNING);
        }

    }


    /**
     * @param string $fullpath
     * @param int $quality
     * @throws Exception\ExecutableNotFound
     */
    protected function compressFile($fullpath, $quality = 3)
    {
        $path_to_oggenc = 'oggenc';
        $path_to_flac = 'flac';
        $unusedArrayResult = array();

        if (!command_exists($path_to_oggenc)) {
            throw new Exception\ExecutableNotFound('Cannot find Ogg Vobis executable: oggenc');
        }

        if (!command_exists($path_to_flac)) {
            throw new Exception\ExecutableNotFound('Cannot find Flac executable: flac');
        }

        // Skip files smaller than 4096 bytes
        if (filesize($fullpath) <= 4096) {
            return;
        }

        $filename = pathinfo($fullpath, PATHINFO_BASENAME);

        switch (strtolower(pathinfo($fullpath, PATHINFO_EXTENSION))) {

            case ('flac') :

                $command = $path_to_flac . ' -d --delete-input-file ' . escapeshellarg($fullpath);
                $res = -1; // any nonzero value
                exec($command, $unusedArrayResult, $res);
                if ($res != 0) trigger_error("Warning: flac return_val was $res, there was a problem decompressing flac $filename", E_USER_WARNING);
                else $fullpath = str_replace(".flac", ".wav", $fullpath);

            case ('wav'):
            case ('aif'):
            case ('aiff'):

                $command = $path_to_oggenc . " --quality $quality \"$fullpath\" ";
                $res = -1; // any nonzero value
                exec($command, $unusedArrayResult, $res);
                if ($res != 0) trigger_error("Warning: oggenc return_val was $res, there was a problem compressing $filename", E_USER_WARNING);
                else unlink($fullpath);

        }

    }

}
