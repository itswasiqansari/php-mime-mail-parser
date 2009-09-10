<?php

/**
 * Fast Mime Mail parser Class using PHP's MailParse Extension
 * @author gabe@fijiwebdesign.com, alknetso@gmail.com
 * @url http://www.fijiwebdesign.com/
 * @license http://creativecommons.org/licenses/by-sa/3.0/us/
 * @version $Id$
 */
class MimeMailParser {
	
	/**
	 * PHP MimeParser Resource ID
	 */
	public $resource;
	
	/**
	 * Stream pointer to original email message
	 */
	public $stream;
	
	/**
	 * String storing original email message
	 */
	public $data;

	/**
	 * Name of the temporal file storing the catched message
	 */
	public $cacheFilename;

	/**
	 * Id of the main text message
	 */
	public $mainTextId;
	
	/**
	 * Array storing the part_id list.
	 */
	private $part_index;

	/**
	 * Inialize some stuff
	 * @return 
	 */
	public function __construct() {
		$this->resource = mailparse_msg_create();
	}
	
	/**
	 * Free the held resouces
	 * @return void
	 */
	public function __destruct() {
		// clear the email file resource
		if (is_resource($this->stream)) {
			fclose($this->stream);
		}
		// clear the MailParse resource
		if (is_resource($this->resource)) {
			mailparse_msg_free($this->resource);
		}
		//delete the temporal file used, if any
		if( $this->cacheFilename ) {
			unlink($this->cacheFilename);
		}

	}

	/**
	 * Identify the type of the source where we read the original message from
	 * @author alknetso@gmail.com
	 * @return String
	 * @param $origin a sting containing a path to file, or the whole message, or a file descritor of a stream
	 */
	private function identifySourceType($origin) {
		if( is_string($origin) ) {
			if( is_file($origin) ) {  //TODO: add restriction about string lenght?
				return "path";
			} else {
				return "string";
			}
		} else if( get_resource_type($origin) == 'stream' ) {
			return "stream";
		} else {
			throw new Exception('Could not identify the source type. It must be a valid path to a file, a already opened file pointer or a string containgin the whole message.');
		}
		return false;
	}

	/**
	 * Caches the source Stream to a temporal file
	 * @return String containing the temporal filename
	 * @param $origin a file descritor of the source stream
	 */
	private function streamToTmpFile($origin) {
		if( get_resource_type($origin) != 'stream' ) {
			throw new Exception('Attempting to cache a stream to a temporal file, but the origin is not a stream');
			return false;
		}
		//TODO: Possibly set custom temp dir path instead of sys_get_temp_dir() ?
		$tmpFilename = tempnam(sys_get_temp_dir(),"MimeMailParser-");
		if ( ! $tmpFilename) {
			throw new Exception('Could not create temporary file to catch stream. Your tmp directory may be unwritable by PHP.');
			return false;
		}
		$tmp_fp = fopen($tmpFilename,"a");
		while(!feof($origin)) {
			fwrite($tmp_fp, fread($origin, 4096));
		}
		fclose($tmp_fp);
		return $tmpFilename;
	}

	/**
	 * Caches the source string to a temporal file
	 * @return String containing the temporal filename
	 * @param $origin a string containing the message
	 */
	private function stringToTmpfile($origin) {
		if( ! is_string($origin) ) {
			throw new Exception('Attempting to cache a string to a temporal file, but the origin is not a string');
			return false;
		}
		$tmpFilename = tempnam(sys_get_temp_dir(),"MimeMailParser-");
		if ( ! $tmpFilename) {
			throw new Exception('Could not create temporary file to catch string. Your tmp directory may be unwritable by PHP.');
			return false;
		}
		$tmp_fp = fopen($tmpFilename,"w");
		fprintf($tmp_fp, "%s", $origin);
		fclose($tmp_fp);
		return $tmpFilename;
	}

	/**
	 * Initialize the MimeMailParser Instance, reading and parcing the mail message source
	 * @return Object MimeMailParser Instance
	 * @param $origin is the source of the message (string, path or stream), $sourceType indicates specificly what type of source is this (will be indentified automatically by default)
	 */
	public function parseSource($origin, $sourceType = "") {
		if( !$sourceType) {
			$sourceType = $this->identifySourceType($origin);
			if( !$sourceType ) {
				throw new Exception('Could not parse the source, unable to identify source type!');
				return false;
			}
		}
		switch ($sourceType) {
			case "path":
				$this->resource = mailparse_msg_parse_file($origin);
				$this->stream = fopen($origin, 'r');
				break;
			case "stream":
				$this->cacheFilename = $this->streamToTmpFile($origin);
				$this->parseSource($this->cacheFilename, "path");
				//TODO: reference cacje file by file handler instead of filename?
				break;
			case "string":
				// $this->cacheFilename = $this->stringToTmpfile($origin); //do so if caching is forced
				// $this->parseSource($cacheFile, "path"); //do so if caching is forced
				mailparse_msg_parse($this->resource, $origin);
				$this->data = $origin;
				break;
			default:
				throw new Exception('Could not parse the source, type \"'.$sourceType.'\" is not supported');
				return false;
		}
		$this->part_index = mailparse_msg_get_structure($this->resource);
		$this->parts = array();
		foreach($this->part_index as $part_id) {
			$part = mailparse_msg_get_part($this->resource, $part_id);
			$this->parts[$part_id] = mailparse_msg_get_part_data($part);
		}
		return $this;
		//TODO: throw exception in case the parsing were not successful
	}

	/**
	 * Retrieve the index of part_id's
	 * @return Array part_index
	 * @param void
	 */
	public function getIndex() {
		return $this->part_index;
		//TODO: throw exception?
	}

	/**
	 * Retrieve the part_id specified by it's linear index
	 * @return String part_id
	 * @param int index of the part
	 */
	public function getPartId($index) {
		return $this->part_index[$index];
		//TODO: throw exception?
	}

	/**
	 * Retrieve the multidimentional associative array describing all the parts, indexed by part_id
	 * @return Array parts
	 * @param void
	 */
	public function getParts() {
		return $this->parts;
		//TODO: throw exception?
	}

	/**
	 * Retrieve the associative array describing a specific part of the message
	 * @return Array part
	 * @param $part_id String part_id (defaults to the main message)
	 */
	public function getPart($part_id = "1") {
		if ( ! isset($this->parts[$part_id]) ) {
			throw new Exception('MimeMailParser::parseSource() must be called before retrieving any e-mail part.');
			return false;
		}
		return $this->parts[$part_id];
	}

	/**
	 * Retrieve raw content of a given message part.
	 * @return String part
	 * @param $part_id String part_id (defaults to the main message)
	 */
	public function getPartRaw($part_id = "1") {
		if ( ! isset($this->parts[$part_id]) ) {
			throw new Exception('MimeMailParser::parseSource() must be called before retrieving any e-mail part.');
			return false;
		}
		$start = $this->parts[$part_id]['starting-pos'];
		$end = $this->parts[$part_id]['ending-pos'];
		if( isset($this->stream) ) {
			fseek($this->stream, $start, SEEK_SET);
			return fread($this->stream, $end-$start);
		} else if( isset($this->data) ) {
			return substr($this->data, $start, $end-$start);
		} else {
			throw new Exception('Can not access original message storage.');
			return false;
		}
	}

	/**
	 * Retrieve all the headers of a part as a multidimentional associative array
	 * @return Array headers
	 * @param $part_id String (defaults to the main message)
	 */
	public function getHeaders($part_id = "1") {
		if ( ! isset($this->parts[$part_id]) ) {
			throw new Exception('MimeMailParser::parseSource() must be called before retrieving any e-mail part.');
			return false;
		}
		return $this->parts[$part_id]['headers'];
	}

	/**
	 * Retrieve all the headers of a part as a raw string
	 * @return String containing raw headers
	 * @param $part_id String part_id (defaults to the main message)
	 */
	public function getHeadersRaw($part_id = "1") {
		if ( ! isset($this->parts[$part_id]) ) {
			throw new Exception('MimeMailParser::parseSource() must be called before retrieving any e-mail part.');
			return false;
		}
		$start = $this->parts[$part_id]['starting-pos'];
		$end = $this->parts[$part_id]['starting-pos-body'];
		if( isset($this->stream) ) {
			fseek($this->stream, $start, SEEK_SET);
			return fread($this->stream, $end-$start);
		} else if( isset($this->data) ) {
			return substr($this->data, $start, $end-$start);
		} else {
			throw new Exception('Can not access original message storage.');
			return false;
		}
	}
	
	/**
	 * Retrieve the decoded body of a message part, the format depend on body's content-type
	 * @return String containing the body
	 * @param $part_id String (defaults to the main message), $mode ( "p" for parsed or "r" for raw, deafults to parsed)
	*/
	public function getBody($part_id = "") {
		$data = $this->getBodyRaw($part_id);
		if( !$part_id) {
			$part_id = $this->mainTextId;
		}
		if ( $this->parts[$part_id]['transfer-encoding'] == "base64" ) {
			$data = base64_decode($data);
		}

		// recode ISO-8859-1 to utf8?
		// if html and there are images or css inserted, rewrite "src" to corresponding filenames?
		
		return $data;
	}

	/**
	 * Retrieve the body of a message part as raw string
	 * @return String containing the body, giving preference to text over html
	 * @param $part_id String (defaults to the main message)
	*/
	public function getBodyRaw($part_id = "") {
		if( !$part_id) {
			$part_id = $this->identifyMainBody("text");
		}
		if( !$part_id ) {
			$part_id = $this->identifyMainBody("html");
		}
		if( !$part_id ) {
			throw new Exception('Could not identify main message body!');
			return false;
		}
		if ( ! isset($this->parts[$part_id])) {
			throw new Exception('MimeMailParser::parseSource() must be called before retrieving any e-mail part.');
			return false;
		}
		$start = $this->parts[$part_id]['starting-pos-body'];
		$end = $this->parts[$part_id]['ending-pos-body'];

		if( isset($this->stream) ) {
			fseek($this->stream, $start, SEEK_SET);
			return fread($this->stream, $end-$start);
		} else if( isset($this->data) ) {
			return substr($this->data, $start, $end-$start);
		} else {
			throw new Exception('Can not access original message storage.');
			return false;
		}
	}

	/**
	 * Guess and retrieve the part_id of the main message text
	 * @return String containing the part_id of part containing main text of the message
	 * @param String $type specifying the preferred format (html or text)
	*/
	public function identifyMainBody($type = "text") {
		if ( ! isset($this->parts['1'])) {
			throw new Exception('MimeMailParser::parseSource() must be called before retrieving any e-mail part.');
			return false;
		}
		foreach ($this->part_index as $part_id) {
			$currentContentType = $this->parts[$part_id]['content-type'];
			if ( strpos($currentContentType, "multipart/") !== false ) {
				continue; //Multipart, skip
			}
			if ( strpos($currentContentType, "text/") !== false ) {
				if ( strcasecmp($currentContentType, "text/plain") == 0 && $type == "text") {
					$this->mainTextId = $part_id;
					return $part_id;
					break;
				}
				if ( strcasecmp($currentContentType, "text/html")  == 0 && $type == "html") {
					$this->mainTextId = $part_id;
					return $part_id;
					break;
				}
			}
		}
		return false;
	}

	/**
	 * Retrieve a specific message header
	 * @return String
	 * @param $name String Header name
	 */
	public function getHeader($name, $part_id = "1") {
		if ( ! isset($this->parts[$part_id]) ) {
			throw new Exception('MimeMailParser::parseSource() must be called before retrieving email headers.');
			return false;
		}
		$headers = $this->getHeaders($part_id);
		if (isset($headers[$name])) {
			return $headers[$name];
		}
		return false;
	}

	/**
	 * Attempts to construct a list of all the attachments detected, applying certain filters
	 Returns Array containing part_id of parts detected as attachments
	 * @return Array
	 * @param $filter String { "a" for all | "r" for really not inline }
	 */
	public function getAttachments($mode = "r") {
		$attachments = array();
		$dispositions = array("attachment","inline");
		foreach($this->part_index as $current_part) {
			if( in_array($this->parts[$current_part]['content-disposition'],$dispositions) ) {
				if( isset($this->parts[$current_part]['content-id']) && $mode != "a" ) {
					continue;  //this part is really a "inline" attachment, skip
				}
				$attachments[] = $current_part;
			}
		}
		reset($attachments);
		return $attachments; //no way we return false, an empty array in the worst case
	}
}

?>