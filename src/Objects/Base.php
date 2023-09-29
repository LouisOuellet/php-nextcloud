<?php

//Declaring namespace
namespace LaswitchTech\phpNextcloud\Objects;

//Import phpConfigurator class into the global namespace
use LaswitchTech\phpConfigurator\phpConfigurator;

//Import phpLogger class into the global namespace
use LaswitchTech\phpLogger\phpLogger;

//Import Exception class into the global namespace
use \Exception;

//Import SimpleXMLElement class into the global namespace
use \SimpleXMLElement;

class Base {

	// Nextcloud Configurations
	protected $URL = null; // Server URL
	protected $Username = null; // Server Username
	protected $Password = null; // Server Password
	protected $Path = null; // Server Default Upload Directory
	protected $Type = null; // Request Type

	// Cache
	protected $Cache = null;

	// Configurator
	private $Configurator = null;

	// Logger
	protected $Logger = null;
    protected $Level = 4;

	/**
	 * Create a new phpNextcloud instance.
	 *
	 * @param  string|null  $field
	 * @return void
	 * @throws Exception
	 */
	public function __construct($url, $username, $password, $path = null){

		// Initialize Configurator
		$this->Configurator = new phpConfigurator('nextcloud');
		$this->Configurator->add('logger');

		// Initiate phpLogger
		$this->Logger = new phpLogger('nextcloud');

		// Retrieve Nextcloud Settings
		$this->URL = $url ?: $this->URL;
		$this->Username = $username ?: $this->Username;
		$this->Password = $password ?: $this->Password;
		$this->Path = $path ?: $this->Path;
	}

	/**
	 * Handling Cache.
	 *
	 * @param  string  $key
	 * @param  mixed  $data
	 * @return void
	 * @throws Exception
	 */
    protected function cache($key, $data = null) {
		try {
			
            // Check if data is provided to determine if we are setting or getting
            if($data){

                // Set Tim to Live (TTL)
                $ttl = 3600;  // 1 hour

                // Set Cache Key
                $this->Cache[$key] = [
                    'data' => $data,
                    'expires' => time() + $ttl
                ];
            }

            // Check if key exists and is not expired
            if (isset($this->Cache[$key]) && $this->Cache[$key]['expires'] > time()) {
                return $this->Cache[$key]['data'];
            }

            return false;
		} catch (Exception $e) {
    
            // return false;
			$this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
			return false;
		}
    }

	/**
	 * Handle cURL Requests.
	 *
	 * @param  string  $type
	 * @param  string  $method
	 * @param  string  $endpoint
	 * @param  array  $options
	 * @return void
	 * @throws Exception
	 */
    public function request($method, $endpoint, $options = []) {
        try {

            // Set logger level
            $Level = $this->Configurator->get('logger', 'level') ?: $this->Level;
            $Debug = ($Level > 4);

            // Define default options
            $defaultOptions = [
                'headers' => [],
                'body' => null,
                'type' => 'dav',  // Default to 'dav'
                'version' => 'v1',  // Default to 'v1'
                'cache' => true,  // Default to 'false
            ];
            
            // Merge provided options with defaults
            $options = array_merge($defaultOptions, $options);
    
            // Check Cache
            $cacheKey = md5($method . $endpoint . json_encode($options, JSON_UNESCAPED_SLASHES));
            $cachedData = $this->cache($cacheKey);
            if ($cachedData !== false) {
                return $cachedData;
            }

            // Determine the base URL based on type
            switch($options['type']){
                case 'ocs':
                    switch ($options['version']){
                        case 'v2':
                            $baseUrl = rtrim($this->URL, '/') . '/ocs/v2.php';
                            break;
                        case 'v1':
                        default:
                            $baseUrl = rtrim($this->URL, '/') . '/ocs/v1.php';
                            break;
                    }
                    break;
                case 'share':
                    $baseUrl = rtrim($this->URL, '/') . '/index.php/s/';
                    break;
                case 'dav':
                default:
                    switch ($this->Type){
                        case 'contacts':
                            $baseUrl = rtrim($this->URL, '/') . '/remote.php/dav/addressbooks/users/' . urlencode($this->Username) . '/';
                            break;
                        case 'calendar':
                            $baseUrl = rtrim($this->URL, '/') . '/remote.php/dav/calendars/' . urlencode($this->Username) . '/';
                            break;
                        case 'files':
                        default:
                            $baseUrl = rtrim($this->URL, '/') . '/remote.php/dav/files/' . urlencode($this->Username) . '/';
                            break;
                    }
                    break;
            }
    
            // Construct the full URL by appending the endpoint-specific part
            $url = rtrim($baseUrl,'/'). '/' . trim($endpoint, '/');

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' URL: '.$url);
            $this->Logger->debug(__METHOD__ . ' Options: ' . PHP_EOL . json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if(isset($options['body']['filePath'])){
                $this->Logger->debug(__METHOD__ . ' File Size to Upload: ' . filesize($options['body']['filePath']));
            }

            // Initialize the cURL session
            $ch = curl_init();

            // Set the necessary cURL options
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERPWD, $this->Username . ':' . $this->Password);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

            // Check if Debug is enabled
            if($Debug){
                curl_setopt($ch, CURLOPT_VERBOSE, true);
            }

            if (!empty($options['headers']) && is_array($options['headers'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
            }

            if ($options['body'] !== null) {
                if ($method === 'PUT' && is_array($options['body']) && isset($options['body']['filePath'])) {
                    curl_setopt($ch, CURLOPT_PUT, 1);
                    curl_setopt($ch, CURLOPT_INFILE, fopen($options['body']['filePath'], 'r'));
                    curl_setopt($ch, CURLOPT_INFILESIZE, filesize($options['body']['filePath']));
                } else {
                    if(is_array($options['body'])){
                        $options['body'] = http_build_query($options['body']);
                    }
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
                }
            }

            // Execute the request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' Response: ' . $response . PHP_EOL);
            $this->Logger->debug(__METHOD__ . ' HTTP Code: ' . PHP_EOL . json_encode($httpCode, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Close the cURL session
            curl_close($ch);

            // Set Data
            $data = [
                'success' => $httpCode >= 200 && $httpCode < 300,
                'response' => $response,
                'http_code' => $httpCode,
            ];

            // Store Cache
            if($options['cache'] && $data['success']){
                $this->cache($cacheKey,$data);
            }

            return $data;
        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Convert a SimpleXMLElement into an associative array.
     *
     * @param SimpleXMLElement $xml
     * @return array
     */
    protected function xmlToArray(SimpleXMLElement $xml) {
        $array = [];

        // Check for children with namespaces
        $namespaces = $xml->getNamespaces(true);
        foreach ($namespaces as $prefix => $ns) {
            foreach ($xml->children($ns) as $child) {
                if (isset($array[$prefix . ':' . $child->getName()])) {
                    if (!is_array($array[$prefix . ':' . $child->getName()]) || !isset($array[$prefix . ':' . $child->getName()][0])) {
                        $array[$prefix . ':' . $child->getName()] = [$array[$prefix . ':' . $child->getName()]];
                    }
                    $array[$prefix . ':' . $child->getName()][] = $this->xmlToArray($child);
                } else {
                    $array[$prefix . ':' . $child->getName()] = $this->xmlToArray($child);
                }
            }
        }

        // If no namespaces were found, fallback to regular XML parsing
        if (empty($array)) {
            foreach ($xml->children() as $child) {
                if (isset($array[$child->getName()])) {
                    if (!is_array($array[$child->getName()]) || !isset($array[$child->getName()][0])) {
                        $array[$child->getName()] = [$array[$child->getName()]];
                    }
                    $array[$child->getName()][] = $this->xmlToArray($child);
                } else {
                    $array[$child->getName()] = $this->xmlToArray($child);
                }
            }
        }

        // If still no children, it's a leaf node. Return its string value.
        if (empty($array)) {
            return (string) $xml;
        }

        return $array;
    }

	/**
	 * Parse XML.
	 *
	 * @param  string  $xmlString
	 * @return void
	 * @throws Exception
	 */
	public function parse($xmlString){
		try {

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' String: '.$xmlString);

			// Load the XML string into a SimpleXMLElement object
			$xmlObject = new SimpleXMLElement($xmlString);
        
            // Convert the XML object to an array
            $array = $this->xmlToArray($xmlObject);

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' Array: ' . PHP_EOL . json_encode($array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			
			return [
				'success' => true,
				'data' => $array,
			];
		} catch (Exception $e) {
			$this->Logger->error(__METHOD__ . ' Error: ' . $e->getMessage());
			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * Check if an array is associative.
	 *
	 * @param  array  $array
	 */
    protected function isAssociativeArray($array) {
        if (!is_array($array)) {
            return false;
        }
    
        $keys = array_keys($array);
        return count($keys) !== count(array_filter($keys, 'is_string'));
    }

	/**
	 * Retrieve the temp directory.
	 *
	 * @param  array  $array
	 */
    protected function getTempDir() {

		// Create temporary directory if it does not exist
		$tempDir = rtrim(sys_get_temp_dir(), '/') . '/php-nextcloud/';
		if(!file_exists($tempDir)){
			mkdir($tempDir, 0777, true);
		}

        // Debug Information
        $this->Logger->debug(__METHOD__ . ' Temp Directory: '.$tempDir);

        return $tempDir;
    }
}
