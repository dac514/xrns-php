xrns-php
========

My PHP Scripts For [Renoise](http://renoise.com).

 * __xrns_merge__: merges 2 XRNS modules
 * __xrns_ogg__: compresses XRNS and XRNI with OGG VORBIS

These scripts were last tested with, and should be compatible with, Renoise 2.8. 

Pull requests welcome.


Usage
-----

`$ php -n <script_name>.php <args>`

If you don't know the arguments to pass, just omit them to print some help. Or, open the PHP file in a text editor and read the comments for more info.


Third Party
-----------

These scripts depend on the following third party open-source utilities:

 * [Info-Zip](http://www.info-zip.org/)
 * [Oggenc](http://www.rarewares.org/ogg-oggenc.php)
 * [FLAC](http://flac.sourceforge.net/)

Ensure you have the aforementioned binaries in your path before running these scripts.


License
-------

Public domain. 

This software is provided "as is," without warranty of any kind, express or implied. All use is at your own risk.
