<?php

/********************
 *
 * DynamicMinify - Complete and Minimally-Invasive CSS and JS Minifier
 *
 * 2016 by Dave Buchanan - http://joesvolcano.net/
 *
 * GitHub: https://github.com/unlox775/DynamicMinify-php
 *
 ********************/

class DynamicMinify {
	protected $document_root = null;
	protected $__config = array(
		'encapsulation_mode' => 'concatenate', // 'minify'
		'asset_cache_flush' => false,
		'cache_hook' => null,
		'minified_store_path' => '../../../files/dynamic-minify-cache',
		);

	public function __construct($document_root, $config = false) {
		$this->document_root = $document_root;

		if ( $config !== false ) {
			$this->__config = $config;
		}
		$this->__config = (object) $config;
	}
	public function config() { return $this->__config; }

	public function serveCollection($requested_file) {
		if ( preg_match('/^(.+?)\.map$/',$requested_file, $m) ) { return $this->serveSourceMap($m[1]); }

		$collection_json = $this->document_root .'/'. ltrim($requested_file,'/') .'.json';
		$file_type = preg_match('/\.(js)$/',$requested_file) ? 'application/javascript' : 'text/css';
		list($etag,$last_mod) = $this->getCollectionETag($collection_json);

		$input_headers = getallheaders();
        if ( isset($input_headers['If-Modified-Since']) && $input_headers['If-Modified-Since'] == $last_mod 
        	&& isset($input_headers['If-None-Match']) && $input_headers['If-None-Match'] == trim($etag,'"')
        	) {
            header( "HTTP/1.1 304 Not Modified" );
            header("ETag: ". $etag);
            exit;
        }

        if ( $this->minifiedFileExists($requested_file) ) {
        	$file_content = file_get_contents($this->__minifiedFilePath($requested_file));
        }
        else if ( ! empty($this->config()->cache_hook) ) {
			$file_content = call_user_func_array($this->config()->cache_hook, array(
				array($this,'compileCollectionIntoFileContent'), // function to call
				array($collection_json, $file_type),             // parameters
				$requested_file .'-'. trim($etag,'"'),                     // unique key (combined from all file mtimes and lengths)
			));
		}
		else { $file_content = $this->compileCollectionIntoFileContent($collection_json, $file_type); }

        $source_map_suffix = $file_type == 'application/javascript' ? "\n//# sourceMappingURL=". basename($requested_file) .".map" : "/*# sourceMappingURL=". basename($requested_file) .".map */";
        $file_content .= $source_map_suffix;

		///  Serve File
        header('Content-Type: '. $file_type);
        header('Content-Length: '. strlen($file_content));
        header("Last-Modified: " . $last_mod);
        header("ETag: ". $etag);
        header("Accept-Ranges: bytes");
        // header("SourceMap: ". $requested_file .".map"); # ?r=". rand(100000,999999)
        // header("X-SourceMap: ". $requested_file .".map"); # ?r=". rand(100000,999999)
        // header("Cache-Control:no-cache");
        // header("Pragma:no-cache");

        echo $file_content;
        exit;
	}

	public function compileCollectionIntoFileContent($collection_json, $file_type) {
		$files = $this->getCombinedFiles($collection_json, true);

		$combined = '';
		foreach ( $files as $file ) {
			$file = $this->document_root.$file;
			if ( ! file_exists($file) ) { continue; }

			$prefix = '';
			$suffix = '';
			$combined .= $prefix . file_get_contents($file) . $suffix;

			///  SourceMap simple-logic below relies on newline at end of each file
			if ( substr($combined, -1) != "\n" ) { $combined .= "\n"; }
		}

		///  TO-DO: Minify here
		if ( $this->config()->encapsulation_mode == 'minify' ) {
			// ...
		}

		///  TO-DO: Asset Cache Flush

		return $combined;
	}

	public function minifiedFileExists($requested_file) {
		$minified_file = $this->__minifiedFilePath($requested_file);

		return file_exists($minified_file);
	}

	public function __minifiedStorePath() {
		return ( $this->config()->minified_store_path[0] == '/'
			? rtrim($this->config()->minified_store_path,'/')
			: rtrim($this->document_root,'/') .'/'. trim($this->config()->minified_store_path,'/')
			);
	}

	public function __minifiedFilePath($requested_file) {
		$collection_json = $this->document_root .'/'. ltrim($requested_file,'/') .'.json';
		list($etag,$x,$last_mod) = $this->getCollectionETag($collection_json);

		return $this->__minifiedStorePath() .'/'. date('Y-m-d', $last_mod) .'_'. str_replace('/','-',$requested_file) .'-'. trim($etag,'"') .'.min';
	}

	public function generateMinifiedContentFiles($requested_file) {
		$collection_json = $this->document_root .'/'. ltrim($requested_file,'/') .'.json';
		$file_type = preg_match('/\.(js)$/',$requested_file) ? 'application/javascript' : 'text/css';
		list($etag,$last_mod) = $this->getCollectionETag($collection_json);

		$minified_file = $this->__minifiedFilePath($requested_file);
		$minified_file_dir = dirname($minified_file);
        if ( ! is_dir($minified_file_dir) ) { mkdir($minified_file_dir,0775,true); }
        if ( ! is_dir($minified_file_dir) ) { print_r([$minified_file]); throw new Exception("Could not create dynamic-minify cache directory: ". dirname($minified_file_dir)); }

		$files = $this->getCombinedFiles($collection_json, true);

		///  JavaScript Minifier
		if ( $file_type == 'application/javascript' ) {
			$js_args = array();
			$map_args = array();
			foreach ( $files as $file ) {
				$file_path = rtrim($this->document_root,'/').'/'.ltrim($file,'/');
				if ( ! file_exists($file_path) ) { continue; }

				$js_args[] = "--js ". escapeshellarg($file_path);
				$map_args[] = "--source_map_location_mapping ". escapeshellarg($file_path.'|'.'/'.ltrim($file,'/'));
			}

			$cmd = ("java -jar ". escapeshellarg(__DIR__ .'/lib/google-closure-compiler-v2016-07-13/closure-compiler-v20160713.jar')
				. " --js_output_file ". escapeshellarg($minified_file)
				. " --create_source_map ". escapeshellarg($minified_file .".map")
				. " ". join(' ', $js_args)
				. " ". join(' ', $map_args)
				. " 2>&1 | cat - > ". escapeshellarg($minified_file .".gen-output")
				);
			echo shell_exec($cmd);

			///  Post-Process .map file
			$map_content = json_decode(file_get_contents($minified_file .".map"));
			if ( empty($map_content) ) { 
				unlink($minified_file, $minified_file .".map"); // so they don't get served in a bad-state
				throw new Exception("Error generating JS minigfied files.  Check the output file: ". $minified_file .".gen-output");
			}
			$map_content->file = basename($requested_file);

			///  Re-path sources
			$map_content->sourceRoot = '/';
			foreach ( $map_content->sources as $i => $x ) {
				$map_content->sources[$i] = ltrim($map_content->sources[$i],'/');
			}
			file_put_contents($minified_file .".map", json_encode($map_content));
		}
		///  CSS Minifier
		else if ( $file_type == 'text/css' ) {
			file_put_contents($minified_file,         $this->compileCollectionIntoFileContent(    $collection_json, $file_type                 ) );
			file_put_contents($minified_file .".map", $this->compileCollectionIntoIndexSourceMap( $collection_json, $file_type, $requested_file) );
		}
		else { }
	}

	public function serveSourceMap($requested_file) {
		$collection_json = $this->document_root .'/'. ltrim($requested_file,'/') .'.json';
		$file_type = preg_match('/\.(js)$/',$requested_file) ? 'application/javascript' : 'text/css';
		list($etag,$last_mod) = $this->getCollectionETag($collection_json);
		$etag = 'ff'. $etag; // Quick Kludge : Prefix souce map inode to separate json file from source map

		$input_headers = getallheaders();
        if ( isset($input_headers['If-Modified-Since']) && $input_headers['If-Modified-Since'] == $last_mod 
        	&& isset($input_headers['If-None-Match']) && $input_headers['If-None-Match'] == trim($etag,'"')
        	) {
            header( "HTTP/1.1 304 Not Modified" );
            header("ETag: ". $etag);
            exit;
        }

        if ( $this->minifiedFileExists($requested_file) ) {
        	$file_content = file_get_contents($this->__minifiedFilePath($requested_file) .'.map');
        }
        else if ( ! empty($this->config()->cache_hook) ) {
			$file_content = call_user_func_array($this->config()->cache_hook, array(
				array($this,'compileCollectionIntoIndexSourceMap'),   // function to call
				array($collection_json, $file_type, $requested_file), // parameters
				$requested_file .'-'. trim($etag,'"') .'-min',        // unique key (combined from all file mtimes and lengths)
			));
		}
		else { $file_content = $this->compileCollectionIntoIndexSourceMap($collection_json, $file_type, $requested_file); }

		///  Serve File
        header('Content-Type: application/json');
        header('Content-Length: '. strlen($file_content));
        header("Last-Modified: " . $last_mod);
        header("ETag: ". $etag);
        header("Accept-Ranges: bytes");

        echo $file_content;
        exit;
	}


	public function compileCollectionIntoIndexSourceMap($collection_json, $file_type, $requested_file) {
		$files = $this->getCombinedFiles($collection_json);

		$_files = array();
		foreach ( $files as $f ) { $_files[] = ltrim($f,'/'); }

		require_once(__DIR__ .'/lib/phpsourcemaps-v2016-07-13/src/SourceMap.php');
		$map = new SourceMap(basename($requested_file), $_files, '/');

		$source_map = (object) array(
			"version" => 3,
			"file" => $requested_file, //  The JS file, not the ".map" file...
			"sections" => array(),
			);

		$dest_line_i = 0;
		foreach ( $files as $file_i => $file ) {
			$file = $this->document_root.$file;
			if ( ! file_exists($file) ) { continue; }

			///  Count lines in file
			$linecount = 0;
			$handle = fopen($file, "r");
			while(fgets($handle)){ $linecount++; }
			fclose($handle);

			foreach ( range(1,$linecount) as $src_line_i ) {
				$dest_line_i++;

				$map->mappings[] = array(
					'dest_line' => $dest_line_i - 1, // Line in the compiled file (0-based)
					'dest_col'  => 0,                // Column in the compiled file
					'src_index' => $file_i,          // Index of the source file
					'src_line'  => $src_line_i - 1,  // Line in the source file (0-based)
					'src_col'   => 0,                // Column in the source file
					);
			}
		}

		return $map->generateJSON();
	}

	private $getCollectionETag__cache = array();
	public function getCollectionETag($collection_json) {
		if ( ! isset($this->getCollectionETag__cache[$collection_json]) ) {
			$files = $this->getCombinedFiles($collection_json);
			$files[] = $collection_json;

			$last_mod = 0;
			$file_len_sum = 0;
			foreach ( $files as $file ) {
				$file = $this->document_root.$file;
				if ( ! file_exists($file) ) { continue; }
				$mod = filemtime($file);
				if ( $mod > $last_mod ) { $last_mod = $mod; }
				$file_len_sum += filesize($file);
			}

			$this->getCollectionETag__cache[$collection_json] = array(
				sprintf('"%x-%x-%s"', fileinode($collection_json), $file_len_sum, base_convert(str_pad($last_mod,16,"0"),10,16)),
				gmdate("D, d M Y H:i:s",$last_mod) . " GMT",
				$last_mod
				);
		}
		return $this->getCollectionETag__cache[$collection_json];
	}

	private $__parseCollectionSettings__cache = array();
	public function __parseCollectionSettings($collection_json) {
		if ( ! isset($this->__parseCollectionSettings__cache[$collection_json]) ) {
			$this->__parseCollectionSettings__cache[$collection_json] = json_decode(file_get_contents($collection_json));
		}
		return $this->__parseCollectionSettings__cache[$collection_json];
	}
	public function getCombinedFiles($collection_json) {
		$collection = $this->__parseCollectionSettings($collection_json);
		return $collection->combined_files; // requiring all files are absolute to docroot

		// $files = array();
		// foreach ( $collection->combined_files as $file ) {
		// 	$files[] = $file[0] == '/' ? $this->document_root.$file : dirname($collection_json) .'/'. $file;
		// }
		// return $files;	
	}
}
