<?php

$verbose = in_array('-q',$argv) ? false : true;

$syntax = "Syntax: php regenerate-minified-content.php [CONFIG-FILE-JSON]";
$config_file = $argv[1];
if ( ! file_exists($config_file) ) { die("$syntax"); }

$config_json = json_decode(file_get_contents($config_file));
if ( empty($config_json) ) { die("Error: Bad JS Content in file: ". json_error() ."\n\n$syntax"); }
if ( empty($config_json->config) ) { die("Error: JSON requires a 'config' key\n\n$syntax"); }

require(dirname(__DIR__) .'/DynamicMinify.php');

if ( empty($config_json->scan_paths) ) { die("Error: JSON requires a 'scan_paths' key, e.g.: [ \"/dyn-css\", \"/dyn-js\" ]\n\n$syntax"); }
if ( empty($config_json->platforms) ) { die("Error: JSON requires a 'platforms' key, e.g.: { ENV-NAME: DOCROOT_PATH }\n\n$syntax"); }

///  Loop and minify
$purged = array();
foreach ( (array) $config_json->platforms as $env_name => $docroot ) {
	$docroot = rtrim($docroot,'/');
	$dyn_minify = new DynamicMinify($docroot, $config_json->config);

	///  Cheat-ey hack (purge old files in store dir, but only during 3am hour)
	$purge_dir = realpath($dyn_minify->__minifiedStorePath());
	if ( $purge_dir && date('H') == '02' && is_dir($purge_dir) && ! in_array($purge_dir, $purged) ) {
		$purge_cmd = 'find '. escapeshellarg($purge_dir) .' -mtime +14 \\( -iname \'*.min\' -or -iname \'*.min.map\' -or -iname \'*.min.gen-output\' \\) -exec rm {} \;';
		if ( $verbose ) { echo "\n\nPurging files older than 2 weeks inside: $purge_dir ...\t"; }
		shell_exec($purge_cmd);
		if ( $verbose ) { echo "[DONE]\n\n"; }
		$purged[] = $purge_dir;
	}

	///  Scan and Minify ...
	foreach ( $config_json->scan_paths as $scan_path ) {
		$files = shell_exec("cd ". escapeshellarg($docroot) ." 2>/dev/null; find ". escapeshellarg(ltrim($scan_path,'/')) ." -iname '*.json' 2>/dev/null");
		// $files = shell_exec("find ". escapeshellarg($docroot .'/'. ltrim($scan_path,'/')) ." -iname '*.json' 2>/dev/null");
		foreach ( explode("\n",$files) as $collection_json ) {
			if ( empty( $collection_json ) ) { continue; }
			$requested_file = '/'. substr($collection_json,0,-5); // chop off the ".json"

			///  Check if the cache is current
			if ( $dyn_minify->minifiedFileExists($requested_file) ) { continue; }

			if ( $verbose ) { echo "Minifying ". $collection_json." ...\t"; }
			$dyn_minify->generateMinifiedContentFiles($requested_file);
			if ( $verbose ) { echo "[GENERATED]\n"; }
		}
	}
}

///  Helper function
function json_error() {

	switch (json_last_error()) {
        case JSON_ERROR_NONE:
            return 'No errors';
        break;
        case JSON_ERROR_DEPTH:
            return 'Maximum stack depth exceeded';
        break;
        case JSON_ERROR_STATE_MISMATCH:
            return 'Underflow or the modes mismatch';
        break;
        case JSON_ERROR_CTRL_CHAR:
            return 'Unexpected control character found';
        break;
        case JSON_ERROR_SYNTAX:
            return 'Syntax error, malformed JSON';
        break;
        case JSON_ERROR_UTF8:
            return 'Malformed UTF-8 characters, possibly incorrectly encoded';
        break;
        default:
            return 'Unknown error';
        break;
    }
}
