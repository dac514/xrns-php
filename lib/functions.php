<?php

namespace XrnsPhp;

// --------------------------------------------------------------------------------------------------------------------
// Init
// --------------------------------------------------------------------------------------------------------------------

ini_set('display_errors', 1);
error_reporting(E_ALL | E_STRICT);
ini_set('memory_limit', '-1');
date_default_timezone_set(@date_default_timezone_get());
spl_autoload_register(__NAMESPACE__ . '\autoloader');

// --------------------------------------------------------------------------------------------------------------------
// Helper functions, namespaced
// --------------------------------------------------------------------------------------------------------------------

/**
 * XrnsPhp Autoloader
 *
 * @param $className
 */
function autoloader($className)
{
    if (strpos($className, 'XrnsPhp\\') !== 0) {
        return; // Ignore classes not in our namespace
    }
    else {
        $parts = explode('\\', $className);
        $_ = array_shift($parts);
    }

    $path = __DIR__ . '/' . implode('/', $parts) . '.php';
    if (is_file($path)) {
        require($path);
    }

}


/**
 * Determines if a command exists on the current environment
 *
 * @param string $command The command to check
 * @return bool True if the command has been found ; otherwise, false.
 */
function command_exists($command)
{
    $which = (PHP_OS == 'WINNT') ? 'where' : 'which';
    $command = escapeshellarg($command);
    $returnVal = shell_exec("$which $command");

    return (empty($returnVal) ? false : true);
}


/**
 * @param \SimpleXMLElement $parent
 * @param \SimpleXMLElement $new_child
 */
function simplexml_append(\SimpleXMLElement $parent, \SimpleXMLElement $new_child)
{
    $node1 = dom_import_simplexml($parent);
    $dom_sxe = dom_import_simplexml($new_child);
    $node2 = $node1->ownerDocument->importNode($dom_sxe, true);
    $node1->appendChild($node2);
}


/**
 * @param \SimpleXMLElement $parent
 * @param \SimpleXMLElement $new_child
 * @param \SimpleXMLElement $before
 */
function simplexml_insert_before(\SimpleXMLElement $parent, \SimpleXMLElement $new_child, \SimpleXMLElement $before)
{
    $node1 = dom_import_simplexml($parent);
    $dom_sxe = dom_import_simplexml($new_child);
    $node2 = $node1->ownerDocument->importNode($dom_sxe, true);
    $node1->insertBefore($node2, dom_import_simplexml($before));
}