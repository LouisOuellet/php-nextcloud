<?php

//Declaring namespace
namespace LaswitchTech\phpNextcloud;

//Import phpConfigurator class into the global namespace
use LaswitchTech\phpConfigurator\phpConfigurator;

//Import phpLogger class into the global namespace
use LaswitchTech\phpLogger\phpLogger;

//Import Object classes into the global namespace
use LaswitchTech\phpNextcloud\Objects\File;
use LaswitchTech\phpNextcloud\Objects\Calendar;
use LaswitchTech\phpNextcloud\Objects\Contact;

//Import Exception class into the global namespace
use \Exception;

//Import SimpleXMLElement class into the global namespace
use \SimpleXMLElement;

class phpNextcloud {

	// Nextcloud Configurations
	private $URL = null; // Server URL
	private $Username = null; // Server Username
	private $Password = null; // Server Password
	private $Path = null; // Server Default Upload Directory

	// Objects
	public $File = null;
	public $Calendar = null;
	public $Contact = null;

	// Cache
	private $Cache = null;

	// Logger
	private $Logger = null;

	// Configurator
	private $Configurator = null;

	/**
	 * Create a new phpNextcloud instance.
	 *
	 * @param  string|null  $field
	 * @return void
	 * @throws Exception
	 */
	public function __construct($url = null, $username = null, $password = null, $path = null){

		// Initialize Configurator
		$this->Configurator = new phpConfigurator('nextcloud');
		$this->Configurator->add('logger');

		// Initiate phpLogger
		$this->Logger = new phpLogger('nextcloud');

		// Retrieve Nextcloud Settings
		$this->URL = $this->Configurator->get('nextcloud', 'url') ?: $this->URL;
		$this->Username = $this->Configurator->get('nextcloud', 'username') ?: $this->Username;
		$this->Password = $this->Configurator->get('nextcloud', 'password') ?: $this->Password;
		$this->Path = $this->Configurator->get('nextcloud', 'path') ?: $this->Path;

		// Override Nextcloud Settings
		$this->URL = $url ?: $this->URL;
		$this->Username = $username ?: $this->Username;
		$this->Password = $password ?: $this->Password;
		$this->Path = $path ?: $this->Path;

		// Validate Nextcloud Settings
		if(!$this->URL){
			throw new Exception("Nextcloud URL is not set.");
		}
		if(!$this->Username){
			throw new Exception("Nextcloud Username is not set.");
		}
		if(!$this->Password){
			throw new Exception("Nextcloud Password is not set.");
		}

		// Initialize Object Classes
		$this->File = new File($this->URL, $this->Username, $this->Password, $this->Path);
		$this->Calendar = new Calendar($this->URL, $this->Username, $this->Password);
		$this->Contact = new Contact($this->URL, $this->Username, $this->Password);
	}

	/**
	 * Configure Library.
	 *
	 * @param  string  $option
	 * @param  bool|int  $value
	 * @return void
	 * @throws Exception
	 */
	public function config($option, $value){
		try {
			if(is_string($option)){
				switch($option){
					case"url":
					case"username":
					case"password":
						if(is_string($value)){

							// Save to Configurator
							$this->Configurator->set('nextcloud',$option, $value);
						} else{
							throw new Exception("2nd argument must be a string.");
						}
						break;
					case"level":
						if(is_int($value)){

							// Save to Configurator
							$this->Configurator->set('logger',$option, $value);
						} else{
							throw new Exception("2nd argument must be an integer.");
						}
						break;
					default:
						throw new Exception("unable to configure $option.");
						break;
				}
			} else{
				throw new Exception("1st argument must be as string.");
			}
		} catch (Exception $e) {
			$this->Logger->error('Error: '.$e->getMessage());
		}

		return $this;
	}

	/**
	 * Fetch the file content based on the file path.
	 *
	 * @param  string $filePath
	 * @return void
	 * @throws Exception
	 */
	public function getFileContentByPath($filePath, $encoded = false){
		try {

			// Check if file content is in Cache
			if(isset($this->Cache['content']['path'][$filePath])){
				if($encoded){
					// Get the MIME type
					$pathProperties = $this->getFileProperties($filePath);
					if(isset($pathProperties['getcontenttype'])){
						$mimeType = $pathProperties['getcontenttype'];
					} else {
						throw new Exception('Could not retrieve MIME type');
					}
		
					return 'data:' . $mimeType . ';base64,' . base64_encode($this->Cache['content']['path'][$filePath]);
				}

				return $this->Cache['content']['path'][$filePath];
			}

			// Correctly encode each segment of the file path
			$filePathSegments = explode('/', $filePath);
			$encodedPathSegments = array_map('urlencode', $filePathSegments);
			$encodedFilePath = implode('/', $encodedPathSegments);
	
			// Setup the URL to fetch the file content using the file path
			$url = rtrim($this->URL, '/') . '/remote.php/dav/files/' . urlencode($this->Username) . '/' . ltrim($encodedFilePath, '/');

			$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_USERPWD, $this->Username . ':' . $this->Password);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$headers = [
				'OCS-APIRequest: true',
			];
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			$response = curl_exec($ch);

			if (curl_errno($ch)) {
				throw new Exception('cURL error: ' . curl_error($ch));
			}

			curl_close($ch);
			
			if (strpos($response, 'Sabre\DAV\Exception\NotFound') !== false) {
				throw new Exception('File not found');
			}

			// Update the cache with the newly fetched content
			$this->Cache['content']['path'][$filePath] = $response;

			if($encoded){
				// Get the MIME type
				$pathProperties = $this->getFileProperties($filePath);
				if(isset($pathProperties['getcontenttype'])){
					$mimeType = $pathProperties['getcontenttype'];
				} else {
					throw new Exception('Could not retrieve MIME type');
				}
				
				return 'data:' . $mimeType . ';base64,' . base64_encode($response);
			}
	
			return $response;
		} catch (Exception $e) {
			$this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Get the list of files.
	 *
	 * @param  string $filePath
	 * @return void
	 * @throws Exception
	 */
	public function getFiles($filePath){
		try {

			// Check if $filePath is a directory
			if(!$this->isDirectory($filePath)){
				throw new Exception('Path is not a directory');
			}

			// Check if file list is in Cache
			if(isset($this->Cache['list'][$filePath])){
				return $this->Cache['list'][$filePath];
			}

			// Fetch the list of files from the server if not found in the cache
			$url = rtrim($this->URL, '/') . '/remote.php/dav/files/' . urlencode($this->Username) . '/' . ltrim(urlencode($filePath), '/');

			$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_USERPWD, $this->Username . ':' . $this->Password);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Depth: 1', 'Content-Type: application/xml']);
			$headers = [
				'OCS-APIRequest: true',
			];
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			if ($httpCode >= 400) {
				throw new Exception('HTTP error ' . $httpCode);
			}

			if (curl_errno($ch)) {
				throw new Exception('cURL error: ' . curl_error($ch));
			}

			curl_close($ch);

			// Parse the XML response

			$xml = simplexml_load_string($response);

			if ($xml === false) {
				throw new Exception('Failed to parse XML response');
			}
	
			$xml->registerXPathNamespace('d', 'DAV:');
	
			$responseArray = [];
			foreach ($xml->xpath('//d:response') as $response) {
				$tempArray = [];
				
				$href = $response->xpath('d:href')[0];
				if ($href) {
					$tempArray['href'] = (string)$href;
					
					// Extract filename from the href
					$pathParts = explode('/', $tempArray['href']);
					$tempArray['filename'] = urldecode(array_pop($pathParts));

					// Add the file path
					$tempArray['path'] = trim($filePath . '/' . $tempArray['filename'], '/');
				}
				
				foreach ($response->xpath('d:propstat/d:prop/*') as $prop) {
					$tempArray[$prop->getName()] = (string)$prop;
				}

				if($filePath == $tempArray['path']){
					continue;
				}
				
				$responseArray[] = $tempArray;

				// Store in Cache
				$this->Cache['properties']['files'][$tempArray['path']] = $tempArray;
			}

			// Store List in Cache
			$this->Cache['list'][$filePath] = $responseArray;

			return $responseArray;
		} catch (Exception $e) {
			$this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Create a Directory.
	 *
	 * @param  string $directoryPath
	 * @return void
	 * @throws Exception
	 */
	public function makeDirectory($directoryPath){
		try {

			// Construct the URL for the MKCOL request
			$pathComponents = explode('/', $directoryPath);
			$encodedPathComponents = array_map('urlencode', $pathComponents);
			$encodedDirectoryPath = implode('/', $encodedPathComponents);
			
			$url = rtrim($this->URL, '/') . '/remote.php/dav/files/' . urlencode($this->Username) . '/' . ltrim($encodedDirectoryPath, '/');

			// Initialize the cURL session
			$ch = curl_init();
	
			// Set the necessary cURL options
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_USERPWD, $this->Username . ':' . $this->Password);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'MKCOL');
			$headers = [
				'OCS-APIRequest: true',
			];
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	
			// Execute the MKCOL request
			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	
			// Close the cURL session
			curl_close($ch);
	
			// Check the HTTP response code - 201 means the directory was created successfully
			if ($httpCode == 201) {
				return ['success' => true];
			} else {
				// If the response code is anything other than 201, throw an exception with the HTTP status code
				throw new Exception('HTTP error ' . $httpCode);
			}
		} catch (Exception $e) {
			$this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Get Cache.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function getCache(){
		try {
			return $this->Cache;
		} catch (Exception $e) {
			$this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}
}
