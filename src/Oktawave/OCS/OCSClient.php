<?php

/*
 * Copyright (C) 2014 Oktawave Sp. z o.o. - oktawave.com
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Client to communication with Oktawave OCS server
 *
 * @author Rafał Lorenz <rlorenz@octivi.com>
 * @author Antoni Orfin <aorfin@octivi.com>
 */
class Oktawave_OCS_OCSClient
{

    /**
     * HTTP methods constants
     */
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_DELETE = 'DELETE';
    const METHOD_HEAD = 'HEAD';

    /**
     * Response formats constants
     */
    const FORMAT_JSON = 'json';
    const FORMAT_TEXT = 'text';

    /**
     * URL address constants
     */
    const DEFAULT_URL = 'https://ocs-pl.oktawave.com/auth/v1.0';

    /**
     * Delimiter constants
     */
    const DEFAULT_DELIMITER = '/';

    const TOKEN_VALIDITY_PERIOD = '20 hours';

    protected $url;
    protected $bucket;
    protected $authToken;
    protected $authTokenExpirationDate;
    protected $storageUrl;
    protected $useragent = 'osc-client';
    protected $verbosity = false;
    protected $username;
    protected $password;

    protected $recursionCounter = 0;

    /**
     * The array of request content types based on the specified response format
     *
     * @var string[]
     */
    protected static $contentType = array(
        self::FORMAT_JSON => 'application/json',
        self::FORMAT_TEXT => 'text/plain',
    );

    /**
     * Constructs OCSClient
     *
     * @param string $bucket Name of the bucket
     * @param string $url OCS Endpoint address
     */
    public function __construct($bucket, $url = self::DEFAULT_URL)
    {
        $this->url = $url;
        $this->bucket = $bucket;
    }

    /**
     * Returns OCS Endpoint url
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Returns URL to your storage (with AUTH_ string)
     *
     * @return string
     */
    public function getStorageUrl()
    {
        return $this->storageUrl;
    }

    /**
     * @return bool
     */
    public function isVerbosity()
    {
        return $this->verbosity;
    }

    /**
     * @param bool $verbosity
     * @return Oktawave_OCS_OCSClient
     */
    public function setVerbosity($verbosity)
    {
        $this->verbosity = $verbosity;
        return $this;
    }

    /**
     * @return string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * @param string $bucket
     * @return Oktawave_OCS_OCSClient
     */
    public function setBucket($bucket)
    {
        $this->bucket = $bucket;
        return $this;
    }

    /**
     * Authenticates OCS Client
     *
     * @param string $username
     * @param string $password
     *
     * @return
     */
    public function authenticate($username, $password)
    {
        $this->username = $username;
        $this->password = $password;

        $customHeaders = array(
            'X-Auth-User' => $username,
            'X-Auth-Key' => $password,
        );

        $ret = $this->createCurl($this->url, null, null, $customHeaders);

        $headers = $ret['headers'];
        $this->authToken = $headers['X-Auth-Token'];
        $this->storageUrl = $headers['X-Storage-Url'];

        $this->authTokenExpirationDate = new \DateTime();
        $this->authTokenExpirationDate->modify(self::TOKEN_VALIDITY_PERIOD);

        return;
    }

    protected function reauthenticate()
    {
        $this->authToken = null;
        $this->authenticate($this->username, $this->password);
    }

    /**
     * Gets objects list as array in format:
     * array(
     *      '0' => array(
     *          'hash' => 'b93a793536321f11297538607f718737',
     *          'last_modified' => '2014-06-24T13:12:11.130680',
     *          'bytes' => 48,
     *          'name' => 'data/nested\nested\test.txt',
     *          'content_type' => 'text/plain',
     *      ),
     *      ...
     * );
     *
     * @param string $path
     * @param string $delimiter
     * @param boolean $fullUrls
     * @param int $limit
     * @param int $marker
     * @return array
     */
    public function listObjects($path = null, $delimiter = null, $fullUrls = false, $limit = null, $marker = null)
    {
        $this->isAuthenticated();

        $endpoint = '';

        $queryParams = array();

        if ($path) {
            $queryParams['prefix'] = $path;
        }

        if ($delimiter) {
            $queryParams['delimiter'] = $delimiter;
        }

        if ($limit) {
            if ($limit > 10000) {
                $limit = 10000;
            }

            $queryParams['limit'] = $limit;
        }

        if ($marker) {
            $queryParams['marker'] = $marker;
        }

        if (!empty($queryParams)) {
            $endpoint .= '?' . http_build_query($queryParams);
        }

        $ret = $this->createCurl($this->bucket . $endpoint, self::METHOD_GET, null, null, true, false, self::FORMAT_JSON);

        return json_decode($ret['body'], true);
    }

    /**
     * Uploads objects from given directory
     *
     * @param string $dir Path to directory
     * @param string $dest Prefix of destination on OCS
     * @param boolean $recursive Follow subdirectories?
     *
     * @return string[] URLs of created objects
     */
    public function createObjectsFromDir($dir, $dest, $recursive = true)
    {
        $this->isAuthenticated();

        $urls = array();
        $files = array();

        $dir = rtrim($dir, "/\\") . '/';

        if ($recursive) {
            $objects = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($objects as $name => $object) {
                if (is_file($object)) {
                    $files[$name]['local'] = $name;
                    $basename = substr($name, strlen($dir), strlen($name));
                    $files[$name]['dest'] = "$dest/$basename";
                }
            }
        } else {
            foreach (scandir($dir) as $object) {
                if (is_file("$dir/$object")) {
                    $files["$dir/$object"]['local'] = "$dir/$object";
                    $files["$dir/$object"]['dest'] = "$dest/$object";
                }
            }
        }

        foreach ($files as $file) {
            $urls[] = $this->createObject($file['local'], $file['dest'], true);
        }

        return $urls;
    }

    /**
     * Uploads objects from their paths
     *
     * @param string[] $paths Full paths to objects in a format path => destination
     *
     * @return string[] URLs of created objects
     */
    public function createObjectsFromPaths(array $paths)
    {
        $this->isAuthenticated();

        $urls = array();
        foreach ($paths as $path => $dest) {
            $urls[] = $this->createObject($path, $dest, true);
        }

        return $urls;
    }

    /**
     * Uploads object for given path
     *
     * @param string $path Full path to file
     * @param string $dstPath Destination path
     * @param boolean $checkIntegrity Check MD5 sum of file?
     *
     * @return string URL Full URL of created object
     */
    public function createObject($path, $dstPath, $checkIntegrity = true)
    {
        $this->isAuthenticated();

        $file = fopen($path, 'r');
        $fsize = filesize($path);

        $files = array('file' => $file, 'filesize' => $fsize, 'etag' => null);

        if ($checkIntegrity) {
            $files['etag'] = md5_file($path);
        }

        $ret = $this->createCurl($this->bucket . '/' . $dstPath, self::METHOD_PUT, $files);

        fclose($file);

        return $this->storageUrl . '/' . $this->bucket . '/' . $dstPath;
    }

    /**
     * Creates empy directory (pseudo-directory) for given path
     *
     * @param string $dstPath Destination path
     *
     * @return string URL Full URL of created directory
     */
    public function createDirectory($dstPath)
    {
        $this->isAuthenticated();

        $customHeaders = array(
            'Content-Type' => 'application/directory',
        );

        $file = tmpfile();
        $files = array('file' => $file, 'filesize' => 0, 'etag' => null);

        $ret = $this->createCurl($this->bucket . '/' . $dstPath, self::METHOD_PUT, $files, $customHeaders);

        fclose($file);

        return $this->storageUrl . '/' . $this->bucket . '/' . $dstPath;
    }

    /**
     * Deletes object
     *
     * @param string $path OCS path to object
     *
     * @return boolean
     */
    public function deleteObject($path)
    {
        $this->isAuthenticated();

        $ret = $this->createCurl($this->bucket . '/' . $path, self::METHOD_DELETE);

        return true;
    }

    /**
     * Checks if object exists
     *
     * @param string $path Path to OCS's object
     *
     * @return boolean  Returns true if object exists on OCS
     * @throws Oktawave_OCS_Exception_OCSException
     */
    public function checkObject($path)
    {
        $this->isAuthenticated();

        try {
            $ret = $this->createCurl($this->bucket . '/' . $path, self::METHOD_HEAD);
            return $ret['httpCode'] === 200;
        } catch (Oktawave_OCS_Exception_HttpException $e) {
            if ($e->isNotFound()) {
                return false;
            }

            throw $e;
        }

        return false;
    }

    /**
     * Gets object's metadata.
     *
     * @param string $path Path to OCS's object
     *
     * @return array  With the same format as a listObject returns for single object
     * @throws Oktawave_OCS_Exception_OCSException
     */
    public function getObjectMetadata($path)
    {
        $this->isAuthenticated();

        $ret = $this->createCurl($this->bucket . '/' . $path, self::METHOD_HEAD);

        return array(
            'content_type' => $ret['headers']['Content-Type'],
            'bytes' => $ret['headers']['Content-Length'],
            'last_modified' => date('c', $ret['headers']['X-Timestamp']),
            'hash' => $ret['headers']['Etag'],
        );
    }

    /**
     * Server-Side rename object
     *
     * @param string $path
     * @param string $newName
     *
     * @return string URL Full URL of renamed object
     */
    public function renameObject($path, $newName)
    {
        $this->isAuthenticated();

        $ret1 = $this->copyObject($path, $newName);
        $ret2 = $this->deleteObject($path);

        return $this->storageUrl . '/' . $this->bucket . '/' . $newName;
    }

    /**
     * Server-Side copy object
     *
     * @param string $path
     * @param string $destinationPath
     *
     * @return string URL Full URL of copied object
     */
    public function copyObject($path, $destinationPath)
    {
        $this->isAuthenticated();

        $customHeaders = array(
            'X-Copy-From' => '/' . $this->bucket . '/' . $path,
            'Content-Length' => 0
        );

        $ret = $this->createCurl($this->bucket . '/' . $destinationPath, self::METHOD_PUT, null, $customHeaders);

        return $this->storageUrl . '/' . $this->bucket . '/' . $destinationPath;
    }

    /**
     * Saves object as file
     *
     * @param string $path
     * @param string $destinationPath
     *
     * @return string path
     */
    public function downloadObjectToFile($path, $destinationPath)
    {
        $this->isAuthenticated();

        if (!file_exists($destinationPath)) {
            mkdir(dirname($destinationPath), 0777, true);
        }
        $file = fopen($destinationPath, "w+");

        $ret = $this->createCurl($this->bucket . '/' . $path, self::METHOD_GET, array('file' => $file));

        fclose($file);

        return $path;
    }

    /**
     * Downloads object content
     *
     * @param string $path
     *
     * @return string Object's content
     */
    public function downloadObject($path)
    {
        $this->isAuthenticated();

        $file = tmpfile();

        $ret = $this->createCurl($this->bucket . '/' . $path, self::METHOD_GET, array('file' => $file));

        rewind($file);
        $content = stream_get_contents($file);

        fclose($file);

        return $content;
    }

    /**
     * Checks if OCS Client is authenticated
     *
     * @throws Oktawave_OCS_Exception_NotAuthenticatedException
     */
    protected function isAuthenticated()
    {
        if (!$this->authToken) {
            $this->reauthenticate();
            throw new Oktawave_OCS_Exception_NotAuthenticatedException('Authentication required. Use authenticate method first!');
        }

        if ($this->isTokenExpired()) {
            $this->reauthenticate();
        }
    }

    protected function isTokenExpired()
    {
        $now = new \DateTime();
        return ($now > $this->authTokenExpirationDate);
    }

    /**
     * Get Curl response
     *
     * @param string $endpoint
     * @param string $method
     * @param array $file
     * @param array $customHeaders
     * @param boolean $includeHeader
     * @param boolean $noBody
     * @param string $format
     *
     * @return array
     * @throws Oktawave_OCS_Exception_HttpException
     */
    protected function createCurl($endpoint, $method = self::METHOD_GET, array $file = null, array $customHeaders = null, $includeHeader = true, $noBody = false, $format = null)
    {
        $curl = curl_init();

        if ($this->authToken && !$this->isTokenExpired()) {
            $headers = array(
                'X-Auth-Token' => $this->authToken,
            );
        } else {
            $headers = array();
        }

        if ($format) {
            $headers = $this->setContentType($format, $headers);
        }

        if (substr($endpoint, 0, 8) == "https://") {
            $url = $endpoint;
        } else {
            $url = $this->storageUrl . '/' . $endpoint;
        }

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => dirname(__FILE__) . '/ca-bundle.crt',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE => $this->isVerbosity(),
            CURLOPT_USERAGENT => $this->useragent,
            CURLOPT_FAILONERROR => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ));

        if ($includeHeader) {
            curl_setopt($curl, CURLOPT_HEADER, true);
        }

        if ($noBody) {
            curl_setopt($curl, CURLOPT_NOBODY, true);
        }

        switch ($method) {
            case self::METHOD_GET:
                if (!empty($file)) {
                    curl_setopt($curl, CURLOPT_FILE, $file['file']);
                    curl_setopt($curl, CURLOPT_HEADER, false);
                }
                break;
            case self::METHOD_HEAD:
                curl_setopt($curl, CURLOPT_NOBODY, true);
                break;
            case self::METHOD_POST:
                curl_setopt($curl, array(
                    CURLOPT_POST => true,
                ));
                break;
            case self::METHOD_PUT:
                curl_setopt($curl, CURLOPT_PUT, true);
                if (!empty($file)) {
                    curl_setopt($curl, CURLOPT_INFILE, $file['file']);
                    curl_setopt($curl, CURLOPT_INFILESIZE, $file['filesize']);

                    if ($file['etag']) {
                        $headers['ETag'] = $file['etag'];
                    }
                }
                break;
            case self::METHOD_DELETE:
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
                break;
            default:
                break;
        }

        if (!empty($customHeaders)) {
            $headers = array_merge($customHeaders, $headers);
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->makeHeaders($headers));

        $response = curl_exec($curl);

        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $errorCode = curl_errno($curl);
        $errorMessage = curl_error($curl);
        $errorMessage .= 'Curl error_code: ' . $errorCode . '. Token in object-client: ' . mb_substr($this->authToken, 0, 22) .
            '. $headers[X-Auth-Token]: ' . mb_substr($headers['X-Auth-Token'], 0, 22);

        curl_close($curl);

        if (isset($fhRes)) {
            fclose($fhRes);
        }

        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $responseHeaders = $this->getHeaders($header);

        if (0 === $errorCode) {
            return array('body' => $body, 'httpCode' => $httpCode, 'headers' => $responseHeaders);
        } elseif (22 == $errorCode && in_array($httpCode, [401, 503]) && $this->recursionCounter < 10) {
            $this->recursionCounter++;
            $this->reauthenticate();
            $results = $this->createCurl($endpoint, $method, $file, $customHeaders, $includeHeader, $noBody, $format);
            $this->recursionCounter = 0;
            return $results;
        } else {
            $e = new Oktawave_OCS_Exception_HttpException($errorMessage, $errorCode, $body, $httpCode);
            throw $e;
        }
    }

    /**
     * Get headers as array
     *
     * @param string $response
     *
     * @return array
     */
    protected function getHeaders($response)
    {
        $headerText = substr($response, 0, strpos($response, "\r\n\r\n"));

        $headers = array();
        foreach (explode("\r\n", $headerText) as $i => $line) {
            if ($i === 0) {
                $headers['http_code'] = $line;
            } else {
                list ($key, $value) = explode(': ', $line);

                $headers[$key] = $value;
            }
        }

        return $headers;
    }

    /**
     * Makes headers for CURL request from associative array in format:
     * array(
     *     'X-Auth-User' => 'value',
     *     'X-Auth-Key'  => 'value2',
     * );
     *
     * @param array $headersAsAssoc
     * @return array
     */
    protected function makeHeaders(array $headersAsAssoc = array())
    {
        $headers = array();

        foreach ($headersAsAssoc as $header => $value) {
            $headers[] = $header . ': ' . $value;
        }

        return $headers;
    }

    /**
     * Sets content type for CURL request
     *
     * @param string $format
     * @param array $headers
     *
     * @throws Oktawave_OCS_Exception_FormatNotSupportedException
     * @return array
     */
    protected function setContentType($format, $headers)
    {
        if (!isset(self::$contentType[$format])) {
            throw new Oktawave_OCS_Exception_FormatNotSupportedException($format, array_keys(self::$contentType));
        }

        $contentType = self::$contentType[$format];

        $headers['Accept'] = $contentType;
        $headers['Content-Type'] = $contentType;

        return $headers;
    }

}
