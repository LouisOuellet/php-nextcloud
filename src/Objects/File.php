<?php

//Declaring namespace
namespace LaswitchTech\phpNextcloud\Objects;

//Import Base class into the global namespace
use LaswitchTech\phpNextcloud\Objects\Base;

//Import Exception class into the global namespace
use \Exception;

class File extends Base {

    // Request Type
	protected $Type = 'files';

    public function permission($level){

        // Set level in caps
        $level = strtoupper($level);

        // Debug Information
        $this->Logger->debug(__METHOD__ . ' level: ' . json_encode($level, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Permissions
        $permissions = [
            'READ' => 1,
            'UPDATE' => 2,
            'CREATE' => 4,
            'DELETE' => 8,
            'SHARE' => 16,
            'ALL' => 31
        ];
        
        // Return permission
        return $permissions[$level];
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
                'shareType' => 3, // 0 = Share with user, 1 = Share with group, 3 = Share with link, 4 = email, 6 = Federated cloud share, 7 = circle, 10 = Talk chat
                'permissions' => $this->permission('READ'), // Read-only permission // 1 for read-only, 3 for read & write, and 17 for read & write & reshare
                'password' => null, // No password
                'expireDate' => null, // No expiry date
                'shareWith' => null, // No user or group
                'note' => null, // No note
                'publicUpload' => false, // No public upload
                'hideDownload' => false, // Show download by default
                'path' => $filePath, // Path to the file
                'note' => null, // No note
                'attributes' => [], // JSON empty array
            ];
            
            // Overwrite defaults with any options passed in
            $fields = array_merge($defaults, $options);

            // Remove any fields that are not required
            foreach ($fields as $key => $value) {
                if ($value === null) {
                    unset($fields[$key]);
                }
            }

            // Set attributes
            if($fields['hideDownload']){
                $fields['attributes'][] = [
                    'scope' => 'permissions',
                    'key' => 'download',
                    'enabled' => false,
                ];
            }

            // Convert attributes to json
            $fields['attributes'] = json_encode($fields['attributes'], JSON_UNESCAPED_SLASHES);

            // Debug Information
            $this->Logger->debug(__METHOD__ . ' fields: ' . PHP_EOL . json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Send the request
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
                    'body' => "<?xml version=\"1.0\"?>\r\n<d:propfind xmlns:d=\"DAV:\" xmlns:oc=\"http://owncloud.org/ns\" xmlns:nc=\"http://nextcloud.org/ns\">\r\n<d:prop>\r\n<d:getlastmodified />\r\n<d:getetag />\r\n<d:getcontenttype />\r\n<d:resourcetype />\r\n<oc:fileid />\r\n<oc:permissions />\r\n<oc:size />\r\n<d:getcontentlength />\r\n<nc:has-preview />\r\n<oc:favorite />\r\n<oc:comments-unread />\r\n<oc:owner-display-name />\r\n<oc:share-types />\r\n<nc:contained-folder-count />\r\n<nc:contained-file-count />\r\n</d:prop>\r\n</d:propfind>"
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
    
            $xmlArray = [];
            if($this->isAssociativeArray($parsedXml['data']['d:response'])){
                foreach($parsedXml['data']['d:response'] as $response){
                    if(trim($response['d:href'],'/') == $href){
                        if($this->isAssociativeArray($response['d:propstat'])){
                            $xmlArray = $response['d:propstat'][0]['d:prop'];
                        } else {
                            $xmlArray = $response['d:propstat']['d:prop'];
                        }
                        break;
                    }
                }
            } else {
                if($this->isAssociativeArray($parsedXml['data']['d:response']['d:propstat'])){
                    $xmlArray = $parsedXml['data']['d:response']['d:propstat'][0]['d:prop'];
                } else {
                    $xmlArray = $parsedXml['data']['d:response']['d:propstat']['d:prop'];
                }
            }
    
            // Extracting filename and path
            $pathInfo = pathinfo($filePath);
            $filename = $pathInfo['basename'];
            $path = $pathInfo['dirname'] === '.' ? '' : $pathInfo['dirname'];
    
            $xmlArray['href'] = $href;
            $xmlArray['filename'] = $filename;
            $xmlArray['path'] = $path . '/' . $filename;

            // Cache and return the parsed data
            $this->cache($cacheKey, $xmlArray);

            // Optionally: you can refine the data further if needed, for now, we return as is.
            return $xmlArray;

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

			return isset($Properties['nc:contained-folder-count'])||isset($Properties['nc:contained-file-count']);
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

    /**
     * Delete a file/path.
     *
     * @param  string  $filePath  The path to the file on Nextcloud.
     * @return array
     */
    public function delete($filePath) {
        try {
            // Send a DELETE request
            $response = $this->request('DELETE', $filePath);

            // Check the response
            if (!$response['success']) {
                throw new Exception("Failed to delete the file. Response: " . $response['response']);
            }

            return [
                'success' => true,
                'message' => 'File deleted successfully.',
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
     * Unshare (delete) a shared item based on its token.
     *
     * @param  string  $shareToken
     * @return array
     * @throws Exception
     */
    public function unshare($shareToken) {
        try {
            if (empty($shareToken)) {
                throw new Exception("Share token is required.");
            }

            // Get the share properties
            $properties = $this->getShareProperties($shareToken);

            // The endpoint for public shares using the OCS API
            // Assuming that $shareToken is the unique token for the shared item.
            $endpoint = "apps/files_sharing/api/v1/shares/" . $properties['id'];

            // Perform the DELETE request
            $response = $this->request('DELETE', $endpoint, [
                'type' => 'ocs',
                'version' => 'v1',
                'cache' => false,
                'headers' => ['OCS-APIRequest: true']
            ]);

            if (!$response['success']) {
                throw new Exception("Failed to unshare. Response: {$response['response']}");
            }

            return [
                'success' => true,
                'message' => "Successfully unshared the item."
            ];
        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Copy a file or directory from one path to another.
     *
     * @param string $sourcePath The path of the source file or directory.
     * @param string $destinationPath The path to copy the source to.
     * @return array The result of the operation.
     */
    public function copy($sourcePath, $destinationPath){
        try {
            // Construct the destination URL
            $destinationUrl = rtrim($this->URL, '/') . '/remote.php/dav/files/' . urlencode($this->Username) . '/' . ltrim($destinationPath, '/');

            // Use the 'request' method to send a COPY request
            $options = [
                'headers' => [
                    'Destination: ' . $destinationUrl
                ]
            ];
            $response = $this->request('COPY', $sourcePath, $options);

            // Check for a successful response
            if ($response['success']) {
                return [
                    'success' => true,
                    'message' => 'Resource copied successfully.'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to copy resource. HTTP Code: ' . $response['http_code']
                ];
            }
        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Move a file or folder from one path to another.
     *
     * @param  string  $sourcePath      The path of the file/folder you want to move.
     * @param  string  $destinationPath The path where you want to move the file/folder to.
     * @return array                    Response array indicating success or error.
     */
    public function move($sourcePath, $destinationPath) {
        try {
            // Construct the destination URL
            $destinationUrl = rtrim($this->URL, '/') . '/remote.php/dav/files/' . urlencode($this->Username) . '/' . ltrim($destinationPath, '/');

            // Define headers
            $headers = [
                'Destination: ' . $destinationUrl
            ];

            // Use the request method to send the MOVE request
            $response = $this->request('MOVE', $sourcePath, [
                'headers' => $headers,
                'type' => 'dav'  // assuming we are working with WebDAV
            ]);

            // Check for a successful response
            if ($response['success']) {
                return [
                    'success' => true,
                    'message' => 'Resource moved successfully.'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to move resource. HTTP Code: ' . $response['http_code']
                ];
            }
        } catch (Exception $e) {
            $this->Logger->error(__METHOD__ . ' Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}