<?php

//Declaring namespace
namespace LaswitchTech\phpNextcloud\Objects;

//Import Base class into the global namespace
use LaswitchTech\phpNextcloud\Objects\Base;

//Import Exception class into the global namespace
use \Exception;

class File extends Base {

	protected $Type = 'files'; // Request Type

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
			return null;
		}
	}

	/**
	 * Upload a file.
	 *
	 * @param  string  $fileName
	 * @param  blob  $fileBlob (base64-URI-encoded)
	 * @return void
	 * @throws Exception
	 */
	public function upload($fileName, $fileBlob){
		try {

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' fileName: ' . PHP_EOL . json_encode($fileName, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->Logger->debug(__METHOD__ . ' fileBlob: ' . PHP_EOL . json_encode($fileBlob, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			// Decode the base64-URI to get the binary data
			$fileParts = explode(',', $fileBlob);

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' fileParts: ' . PHP_EOL . json_encode($fileParts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Extract the file data
			if (count($fileParts) < 2) {
				throw new Exception('Failed to extract base64 data from file blob');
			}
			$fileData = base64_decode($fileParts[1]);

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' fileData: ' . PHP_EOL . json_encode($fileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	
			// Create a temporary file to hold the data
			$tempFilePath = tempnam($this->getTempDir(),'FILE_');
			file_put_contents($tempFilePath, $fileData);

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' tempPath: ' . PHP_EOL . json_encode($this->getTempDir(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->Logger->debug(__METHOD__ . ' tempFilePath: ' . PHP_EOL . json_encode($tempFilePath, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	
			if($this->Path){
				$filePath = $this->Path . '/' . $fileName;
			} else {
				$filePath = $fileName;
			}

            $response = $this->request('PUT', $filePath, [ 'body' => ['filePath' => $tempFilePath, 'fileName' => $fileName], 'cache' => false ]);

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' response: ' . PHP_EOL . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	
			if($response['success']) {
                if($response['http_code'] == 204){
                    return ['success' => true, 'message' => 'File updated successfully', 'path' => $filePath];
                }
				return ['success' => true, 'message' => 'File uploaded successfully', 'path' => $filePath];
			} else {
                $error = 'Failed to upload file.';
                if(isset($response['error'])){
                    $error .= ' ' . $response['error'];
                }
				throw new Exception($error);
			}
		} catch (Exception $e) {
			$this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

    /**
     * Share a file.
     *
     * @param  string  $filePath
     * @param  array   $options
     * @return array
     * @throws Exception
     */
    public function share($filePath, $options = []){
        try {
            $endpoint = '/apps/files_sharing/api/v1/shares';

            // Default values
            $defaults = [
                'shareType' => 3, // 3 = Share with link
                'permissions' => 1, // Read-only permission // 1 for read-only, 3 for read & write, and 17 for read & write & reshare
                'password' => null, // No password
                'expireDate' => null, // No expiry date
                'note' => null, // No note
            ];
            
            // Overwrite defaults with any options passed in
            $fields = array_merge($defaults, ['path' => $filePath], $options);

            $result = $this->request('POST', $endpoint, [ 'headers' => ['OCS-APIRequest: true'], 'body' => $fields, 'type' => 'ocs' ]);

            if (!$result['success']) {
                throw new Exception('API Request failed. HTTP Code: ' . $result['http_code']);
            }

            // Parse the XML response
            $parsedData = $this->parse($result['response']);
            
            if (!$parsedData['success']) {
                throw new Exception('Failed to parse XML response: ' . $parsedData['error']);
            }
            
            $response = $parsedData['data'];

            if ((int)$response['meta']['statuscode'] !== 100) {
                throw new Exception('Nextcloud API error: ' . $response['meta']['message']);
            }

            return [
                'success' => true,
                'link' => $response['data']['url'],
                'token' => $response['data']['token'],
            ];
        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get a file's Properties.
     *
     * @param  string $filePath
     * @return void
     * @throws Exception
     */
    public function getFileProperties($filePath) {
        try {

            // Create cache key for this specific method and share token
            $cacheKey = md5('getFileProperties' . $filePath);

            // Attempt to get cached data
            $cachedData = $this->cache($cacheKey);
            if ($cachedData !== false) {
                return $cachedData;
            }

            // Make the request using the optimized method
            $response = $this->request(
                'PROPFIND',
                $filePath,
                [
                    'headers' => [
                        'OCS-APIRequest: true',
                        'Depth: 1',
                        'Content-Type: application/xml; charset=utf-8',
                    ],
                    'body' => "<?xml version=\"1.0\"?>\r\n<d:propfind xmlns:d=\"DAV:\">\r\n\t<d:allprop/>\r\n</d:propfind>"
                ]
            );

            if (!$response['success']) {
                throw new Exception('Failed to fetch file properties: ' . (isset($response['error']) ? $response['error'] : 'Unknown error'));
            }

            $parsedXml = $this->parse($response['response']);
            if (!$parsedXml['success']) {
                throw new Exception('Failed to parse XML response: ' . $parsedXml['error']);
            }

            // Set baseURL
            $baseURL = trim('/remote.php/dav/files/' . urlencode($this->Username) . '/', '/');
            $href = trim($baseURL . '/' . $filePath,'/');

            // Since the XML is successfully parsed, extract the file properties
            if($this->isAssociativeArray($parsedXml['data']['d:response'])){
                foreach($parsedXml['data']['d:response'] as $response){

                    // Since the XML is successfully parsed, extract the file properties
                    if(trim($response['d:href'],'/') == $href){
                        $xmlArray = $response['d:propstat']['d:prop'];
                        break;
                    }
                }
            } else {
                $xmlArray = $parsedXml['data']['d:response']['d:propstat']['d:prop'];
            }

            // Cache and return the parsed data
            $this->cache($cacheKey, $xmlArray);

            // Optionally: you can refine the data further if needed, for now, we return as is.
            return ['success' => true, 'data' => $xmlArray];

        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

	/**
	 * Check if file is a directory.
	 *
	 * @param  string $filePath
	 * @return void
	 * @throws Exception
	 */
	public function isDirectory($filePath){
		try {
			// Retrieve file properties
			$Properties = $this->getFileProperties($filePath);

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' Properties: ' . json_encode($Properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return isset($Properties['d:quota-used-bytes'])||isset($Properties['d:quota-available-bytes']);
		} catch (Exception $e) {
			$this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Check if file/directory exist.
	 *
	 * @param  string $filePath
	 * @return void
	 * @throws Exception
	 */
	public function exist($filePath){
		try {
			// Retrieve file properties
			$Properties = $this->getFileProperties($filePath);

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' Properties: ' . json_encode($Properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Check if file exist
            if(isset($Properties['success'])){
                $return = $Properties['success'];
            } else {
                $return = isset($Properties['d:getlastmodified']);
            }

			return $return;
		} catch (Exception $e) {
			$this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}
    
    /**
     * Get a file or directory Shares.
     *
     * @param  string $filePath
     * @return array
     * @throws Exception
     */
    public function getShares($filePath){
        try {

            // Build the endpoint URL
            $endpoint = '/apps/files_sharing/api/v1/shares';
            $endpoint .= '?path=' . urlencode($filePath);

            if ($this->isDirectory($filePath)) {
                $endpoint .= '&subfiles=true';
            }

            // Define request options
            $options = [
                'headers' => ['OCS-APIRequest: true'],
                'type' => 'ocs'
            ];

            // Make the request
            $data = $this->request('GET', $endpoint, $options);

            // Check if the request was successful
            if (!$data['success']) {
                throw new Exception('Failed to retrieve shares: ' . (isset($data['error']) ? $data['error'] : 'Unknown error'));
            }

            // Parse the XML response
            $parsedData = $this->parse($data['response']);
            if (!$parsedData['success']) {
                throw new Exception('Failed to parse response: ' . $parsedData['error']);
            }

            $responseArray = [];
            $elements = $parsedData['data']['data']['element'] ?? [];

            // If there's only one element, ensure it's wrapped in an array for consistent handling
            if (isset($elements['id'])) {
                $elements = [$elements];
            }

            // Process each share entry
            foreach ($elements as $element) {
                if (isset($element['token'])) {
                    $responseArray[$element['token']] = $element;

                    // Store the share properties in the cache
                    $this->cache(md5('getShareProperties' . $element['token']), $element);
                }
            }

            return $responseArray;
        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: '.$e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch share properties.
     *
     * @param  string $shareToken
     * @return void
     * @throws Exception
     */
    public function getShareProperties($shareToken) {

        // Debug Information
        $this->Logger->debug(__METHOD__ . ' shareToken: ' . json_encode($shareToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Create cache key for this specific method and share token
        $cacheKey = md5('getShareProperties' . $shareToken);

        // Debug Information
        $this->Logger->debug(__METHOD__ . ' cacheKey: ' . json_encode($cacheKey, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Attempt to get cached data
        $cachedData = $this->cache($cacheKey);
        if ($cachedData !== false) {
            return $cachedData;
        }

        // If not cached, set up the request
        $endpoint = 'apps/files_sharing/api/v1/shares?token=' . urlencode($shareToken);
        $options = [
            'headers' => ['OCS-APIRequest: true'],
            'type' => 'ocs',
            'version' => 'v2',
        ];

        // Use the request method to handle the cURL operations
        $response = $this->request('GET', $endpoint, $options);

        if (!$response['success']) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $response['error']);
            return ['success' => false, 'error' => $response['error']];
        }

        // Parse the XML response
        $parsedResponse = $this->parse($response['response']);

        // Check if the XML was successfully parsed
        if (!$parsedResponse['success']) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $parsedResponse['error']);
            return ['success' => false, 'error' => $parsedResponse['error']];
        }

        // Check if the share was found
        if (!isset($parsedResponse['data']['data']['element'])){
            $this->Logger->error(__METHOD__ . ' Error: ' . 'Share not found');
            return ['success' => false, 'error' => 'Share not found'];
        }

        // Set Element(s)
        $elements = $parsedResponse['data']['data']['element'];

        // Debug Information
        $this->Logger->debug(__METHOD__ . ' elements: ' . json_encode($elements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->Logger->debug(__METHOD__ . ' isAssociativeArray element: ' . json_encode($this->isAssociativeArray($elements), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Since the XML is successfully parsed, extract the share properties
        if($this->isAssociativeArray($elements)){
            foreach($elements as $element){

                // Debug Information
                $this->Logger->debug(__METHOD__ . ' element: ' . json_encode($element, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                // Since the XML is successfully parsed, extract the share properties
                if($element['token'] == $shareToken){
                    $shareProperties = $element;
                    break;
                }
            }
        } else {
            $shareProperties = $elements;
        }

        // Debug Information
        $this->Logger->debug(__METHOD__ . ' Properties: ' . json_encode($shareProperties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Cache and return the parsed data
        $this->cache($cacheKey, $shareProperties);
        return $shareProperties;
    }

    /**
     * Fetch the file content based on the file path.
     *
     * @param  string $filePath
     * @param  bool $encoded
     * @return void
     * @throws Exception
     */
    public function getFileContentByPath($filePath, $encoded = false) {
        try {

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' filePath: ' . json_encode($filePath, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->Logger->debug(__METHOD__ . ' encoded: ' . json_encode($encoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
            // Create cache key for this specific method and share token
            $cacheKey = md5('getShagetFileContentByPathreProperties' . $filePath . $encoded);
    
            // Debug Information
            $this->Logger->debug(__METHOD__ . ' cacheKey: ' . json_encode($cacheKey, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
            // Attempt to get cached data
            $cachedData = $this->cache($cacheKey);
            if ($cachedData !== false) {
                return $cachedData;
            }

            // Correctly encode each segment of the file path
            $filePathSegments = explode('/', $filePath);
            $encodedPathSegments = array_map('urlencode', $filePathSegments);
            $encodedFilePath = implode('/', $encodedPathSegments);

            // Use the request method to get the file content
            $headers = ['OCS-APIRequest: true'];
            $response = $this->request('GET', $encodedFilePath, [
                'headers' => $headers,
                'type' => 'dav',  // Since we're working with files, the type is 'dav'
            ]);

            // Check if the request was successful
            if (!$response['success'] || strpos($response['response'], 'Sabre\DAV\Exception\NotFound') !== false) {
                throw new Exception('File not found or request was unsuccessful.');
            }

            $response = $response['response'];

            if ($encoded) {
                // Get the MIME type
                $pathProperties = $this->getFileProperties($filePath);
                if (isset($pathProperties['d:getcontenttype'])) {
                    $mimeType = $pathProperties['d:getcontenttype'];
                } else {
                    throw new Exception('Could not retrieve MIME type');
                }

                $response = 'data:' . $mimeType . ';base64,' . base64_encode($response);
            }

            // Update the cache with the newly fetched content
            $this->cache($cacheKey, $response);

            return $response;
        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch the file content based on the share token.
     *
     * @param  string $shareToken
     * @param  bool $encoded
     * @return mixed
     * @throws Exception
     */
    public function getFileContentByShareToken($shareToken, $encoded = false) {
        try {
            // Check if file content is in Cache
            $cacheKey = md5('getFileContentByShareToken' . $shareToken . $encoded);
            $cachedData = $this->cache($cacheKey);
            if ($cachedData !== false) {
                return $cachedData;
            }

            // Setup the URL to fetch the file content using the share token
            $endpoint = urlencode($shareToken) . '/download';
            $options = [
                'type' => 'share',
            ];
            
            $data = $this->request('GET', $endpoint, $options);

            if (!$data['success']) {
                throw new Exception(isset($data['error']) ? $data['error'] : 'Unknown error occurred.');
            }

            $response = $data['response'];

            if ($encoded) {
                // Get the MIME type
                $shareProperties = $this->getShareProperties($shareToken);
                if (isset($shareProperties['mimetype'])) {
                    $mimeType = $shareProperties['mimetype'];
                } else {
                    throw new Exception('Could not retrieve MIME type');
                }

                $response = 'data:' . $mimeType . ';base64,' . base64_encode($response);
            }

            // Update the cache with the newly fetched content
            $this->cache($cacheKey, $response);

            return $response;
        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a Directory.
     *
     * @param  string $directoryPath
     * @return array
     * @throws Exception
     */
    public function makeDirectory($directoryPath){
        try {

            // Use the request method to make the MKCOL request
            $response = $this->request('MKCOL', $directoryPath, [
                'headers' => [
                    'OCS-APIRequest: true',
                ],
                'type' => 'dav',
                'cache' => false
            ]);

            // Parse the XML response
            $response['response'] = $this->parse($response['response']);

            // Check the response
            if ($response['success']) {
                return $response;
            } else {
                // If the response code is anything other than 201, throw an exception with the HTTP status code
                throw new Exception($response['response']['data']['s:message']);
            }
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
    public function getFiles($filePath) {
        try {

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' filePath: ' . json_encode($filePath, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Check if file content is in Cache
            $cacheKey = md5('getFiles' . $filePath);
            $cachedData = $this->cache($cacheKey);
            if ($cachedData !== false) {
                return $cachedData;
            }

            // Check if $filePath is a directory
            if (!$this->isDirectory($filePath)) {
                throw new Exception('Path is not a directory');
            }

            // Set headers for the PROPFIND request
            $headers = [
                'Depth: 1',
                'Content-Type: application/xml',
                'OCS-APIRequest: true'
            ];

            // Use the new request method to fetch files
            $response = $this->request('PROPFIND', $filePath, [
                'headers' => $headers,
                'type' => 'dav'
            ]);

            if (!$response['success']) {
                throw new Exception('HTTP error ' . $response['http_code']);
            }

            // Use the new parse method to parse the XML response
            $parsedData = $this->parse($response['response']);
            if (!$parsedData['success']) {
                throw new Exception('Failed to parse XML response');
            }

            $xmlArray = $parsedData['data'];

            $responseArray = [];
            foreach ($xmlArray['d:response'] as $entry) {
                $tempArray = [];

                $tempArray['href'] = $entry['d:href'];

                // Extract filename from the href
                $pathParts = explode('/', $tempArray['href']);
                $tempArray['filename'] = urldecode(array_pop($pathParts));

                // Add the file path
                if($tempArray['filename']){
                    $tempArray['path'] = trim($filePath . '/' . $tempArray['filename'], '/');
                } else {
                    $tempArray['path'] = trim(str_replace('/remote.php/dav/files/' . urlencode($this->Username) . '/', '', $tempArray['href']),'/');
                }

                // Skip the current file path
                if ($filePath == $tempArray['path']) {
                    continue;
                }

                // Extract the file properties
                foreach ($entry['d:propstat']['d:prop'] as $propName => $propValue) {
                    $tempArray[$propName] = $propValue;
                }

                $responseArray[] = $tempArray;
            }

            // Update the cache with the newly fetched content
            $this->cache($cacheKey, $responseArray);

            return $responseArray;
        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}