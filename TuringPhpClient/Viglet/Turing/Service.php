<?php
require_once(dirname(__FILE__) . '/Exception.php');
require_once(dirname(__FILE__) . '/HttpTransportException.php');
require_once(dirname(__FILE__) . '/InvalidArgumentException.php');

require_once(dirname(__FILE__) . '/Response.php');
require_once(dirname(__FILE__) . '/HttpTransport/Interface.php');

class Viglet_Turing_Service
{

    /**
     * SVN Revision meta data for this class
     */
    const SVN_REVISION = '$Revision$';

    /**
     * SVN ID meta data for this class
     */
    const SVN_ID = '$Id$';

    /**
     * SVN HeadURL meta data for this class
     */
    const SVN_URL = '$HeadURL$';

    /**
     * Response writer we'll request - JSON.
     * See http://code.google.com/p/solr-php-client/issues/detail?id=6#c1 for reasoning
     */
    const SOLR_WRITER = 'json';

    /**
     * NamedList Treatment constants
     */
    const NAMED_LIST_FLAT = 'flat';

    const NAMED_LIST_MAP = 'map';

    /**
     * Search HTTP Methods
     */
    const METHOD_GET = 'GET';

    const METHOD_POST = 'POST';

    /**
     * Servlet mappings
     */
    const PING_SERVLET = '/api';

    const UPDATE_SERVLET = 'update';

    const SEARCH_SERVLET = 'select';

    const SYSTEM_SERVLET = 'admin/system';

    const THREADS_SERVLET = 'admin/threads';

    const EXTRACT_SERVLET = 'update/extract';

    /**
     * Server identification strings
     *
     * @var string
     */
    protected $_protocol, $_host, $_port, $_path;

    /**
     * Whether {@link Apache_Solr_Response} objects should create {@link Apache_Solr_Document}s in
     * the returned parsed data
     *
     * @var boolean
     */
    protected $_createDocuments = true;

    /**
     * Whether {@link Apache_Solr_Response} objects should have multivalue fields with only a single value
     * collapsed to appear as a single value would.
     *
     * @var boolean
     */
    protected $_collapseSingleValueArrays = true;

    /**
     * How NamedLists should be formatted in the output.
     * This specifically effects facet counts. Valid values
     * are {@link Apache_Solr_Service::NAMED_LIST_MAP} (default) or {@link Apache_Solr_Service::NAMED_LIST_FLAT}.
     *
     * @var string
     */
    protected $_namedListTreatment = self::NAMED_LIST_MAP;

    /**
     * Query delimiters.
     * Someone might want to be able to change
     * these (to use &amp; instead of & for example), so I've provided them.
     *
     * @var string
     */
    protected $_queryDelimiter = '?', $_queryStringDelimiter = '&', $_queryBracketsEscaped = true;

    /**
     * Constructed servlet full path URLs
     *
     * @var string
     */
    protected $_pingUrl, $_updateUrl, $_searchUrl, $_systemUrl, $_threadsUrl;

    /**
     * Keep track of whether our URLs have been constructed
     *
     * @var boolean
     */
    protected $_urlsInited = false;

    /**
     * HTTP Transport implementation (pluggable)
     *
     * @var Viglet_Turing_HttpTransport_Interface
     */
    protected $_httpTransport = false;

    /**
     * Escape a value for special query characters such as ':', '(', ')', '*', '?', etc.
     *
     * NOTE: inside a phrase fewer characters need escaped, use {@link Apache_Solr_Service::escapePhrase()} instead
     *
     * @param string $value
     * @return string
     */
    static public function escape($value)
    {
        // list taken from http://lucene.apache.org/java/docs/queryparsersyntax.html#Escaping%20Special%20Characters
        $pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';
        $replace = '\\\$1';

        return preg_replace($pattern, $replace, $value);
    }

    /**
     * Escape a value meant to be contained in a phrase for special query characters
     *
     * @param string $value
     * @return string
     */
    static public function escapePhrase($value)
    {
        $pattern = '/("|\\\)/';
        $replace = '\\\$1';

        return preg_replace($pattern, $replace, $value);
    }

    /**
     * Convenience function for creating phrase syntax from a value
     *
     * @param string $value
     * @return string
     */
    static public function phrase($value)
    {
        return '"' . self::escapePhrase($value) . '"';
    }

    /**
     * Constructor.
     * All parameters are optional and will take on default values
     * if not specified.
     *
     * @param string $host
     * @param string $port
     * @param string $path
     * @param Viglet_Turing_HttpTransport_Interface $httpTransport
     * @param string $protocol
     */
    public function __construct($host = 'localhost', $port = 2700, $path = '/api/',$httpTransport = false, $protocol = 'http')
    {
        $this->setHost($host);
        $this->setPort($port);
        $this->setPath($path);
        $this->setProtocol($protocol);

        $this->_initUrls();

        // check that our php version is >= 5.1.3 so we can correct for http_build_query behavior later
        $this->_queryBracketsEscaped = version_compare(phpversion(), '5.1.3', '>=');
    }

    /**
     * Return a valid http URL given this server's host, port and path and a provided servlet name
     *
     * @param string $servlet
     * @return string
     */
    protected function _constructUrl($servlet, $params = array())
    {
        if (count($params)) {
            // escape all parameters appropriately for inclusion in the query string
            $escapedParams = array();

            foreach ($params as $key => $value) {
                $escapedParams[] = urlencode($key) . '=' . urlencode($value);
            }

            $queryString = $this->_queryDelimiter . implode($this->_queryStringDelimiter, $escapedParams);
        } else {
            $queryString = '';
        }

        return $this->_protocol . '://' . $this->_host . ':' . $this->_port . $servlet . $queryString;
    }

    /**
     * Construct the Full URLs for the three servlets we reference
     */
    protected function _initUrls()
    {
        // Initialize our full servlet URLs now that we have server information
        $this->_extractUrl = $this->_constructUrl(self::EXTRACT_SERVLET);
        $this->_pingUrl = $this->_constructUrl(self::PING_SERVLET);
        $this->_searchUrl = $this->_constructUrl(self::SEARCH_SERVLET);
        $this->_systemUrl = $this->_constructUrl(self::SYSTEM_SERVLET, array(
            'wt' => self::SOLR_WRITER
        ));
        $this->_threadsUrl = $this->_constructUrl(self::THREADS_SERVLET, array(
            'wt' => self::SOLR_WRITER
        ));
        $this->_updateUrl = $this->_constructUrl(self::UPDATE_SERVLET, array(
            'wt' => self::SOLR_WRITER
        ));

        $this->_urlsInited = true;
    }

    protected function _generateQueryString($params)
    {
        // use http_build_query to encode our arguments because its faster
        // than urlencoding all the parts ourselves in a loop
        //
        // because http_build_query treats arrays differently than we want to, correct the query
        // string by changing foo[#]=bar (# being an actual number) parameter strings to just
        // multiple foo=bar strings. This regex should always work since '=' will be urlencoded
        // anywhere else the regex isn't expecting it
        //
        // NOTE: before php 5.1.3 brackets were not url encoded by http_build query - we've checked
        // the php version in the constructor and put the results in the instance variable. Also, before
        // 5.1.2 the arg_separator parameter was not available, so don't use it
        if ($this->_queryBracketsEscaped) {
            $queryString = http_build_query($params, null, $this->_queryStringDelimiter);
            return preg_replace('/%5B(?:[0-9]|[1-9][0-9]+)%5D=/', '=', $queryString);
        } else {
            $queryString = http_build_query($params);
            return preg_replace('/\\[(?:[0-9]|[1-9][0-9]+)\\]=/', '=', $queryString);
        }
    }

    /**
     * Central method for making a post operation against this Solr Server
     *
     * @param string $url
     * @param string $rawPost
     * @param float $timeout
     *            Read timeout in seconds
     * @param string $contentType
     * @return Apache_Solr_Response
     *
     * @throws Apache_Solr_HttpTransportException If a non 200 response status is returned
     */
    protected function _sendRawPost($url, $rawPost, $timeout = FALSE, $contentType = 'text/xml; charset=UTF-8')
    {
        $httpTransport = $this->getHttpTransport();

        $httpResponse = $httpTransport->performPostRequest($url, $rawPost, $contentType, $timeout);
        $solrResponse = new Apache_Solr_Response($httpResponse, $this->_createDocuments, $this->_collapseSingleValueArrays);

        if ($solrResponse->getHttpStatus() != 200) {
            throw new Apache_Solr_HttpTransportException($solrResponse);
        }

        return $solrResponse;
    }

    /**
     * Returns the set host
     *
     * @return string
     */
    public function getHost()
    {
        return $this->_host;
    }

    /**
     * Set the host used.
     * If empty will fallback to constants
     *
     * @param string $host
     *
     * @throws Apache_Solr_InvalidArgumentException If the host parameter is empty
     */
    public function setHost($host)
    {
        // Use the provided host or use the default
        if (empty($host)) {
            throw new Apache_Solr_InvalidArgumentException('Host parameter is empty');
        } else {
            $this->_host = $host;
        }

        if ($this->_urlsInited) {
            $this->_initUrls();
        }
    }

    /**
     * Get the set port
     *
     * @return integer
     */
    public function getPort()
    {
        return $this->_port;
    }

    /**
     * Set the port used.
     * If empty will fallback to constants
     *
     * @param integer $port
     *
     * @throws Apache_Solr_InvalidArgumentException If the port parameter is empty
     */
    public function setPort($port)
    {
        // Use the provided port or use the default
        $port = (int) $port;

        if ($port <= 0) {
            throw new Apache_Solr_InvalidArgumentException('Port is not a valid port number');
        } else {
            $this->_port = $port;
        }

        if ($this->_urlsInited) {
            $this->_initUrls();
        }
    }

    /**
     * Get the set path.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->_path;
    }

    /**
     * Set the path used.
     * If empty will fallback to constants
     *
     * @param string $path
     */
    public function setPath($path)
    {
        $path = trim($path, '/');

        if (strlen($path) > 0) {
            $this->_path = '/' . $path . '/';
        } else {
            $this->_path = '/';
        }

        if ($this->_urlsInited) {
            $this->_initUrls();
        }
    }

    /**
     * Get the current protocol.
     *
     * @return string
     */
    public function getProtocol()
    {
        return $this->_protocol;
    }

    /**
     * Set the protocol used.
     * If empty will fallback to 'http'.
     *
     * @param string $protcol
     */
    public function setProtocol($protocol)
    {
        if (empty($protocol)) {
            throw new Apache_Solr_InvalidArgumentException('Protocl parameter is empty');
        } else {
            $this->_protocol = $protocol;
        }

        if ($this->_urlsInited) {
            $this->_initUrls();
        }
    }

    /**
     * Get the current configured HTTP Transport
     *
     * @return Viglet_Turing_HttpTransport_Interface
     */
    public function getHttpTransport()
    {
        // lazy load a default if one has not be set
        if ($this->_httpTransport === false)
        {
            require_once(dirname(__FILE__) . '/HttpTransport/FileGetContents.php');
            
            $this->_httpTransport = new Viglet_Turing_HttpTransport_FileGetContents();
        }
        
        return $this->_httpTransport;
    }
    
    /**
     * Set the HTTP Transport implemenation that will be used for all HTTP requests
     *
     * @param Viglet_Turing_HttpTransport_Interface
     */
    public function setHttpTransport(Viglet_Turing_HttpTransport_Interface $httpTransport)
    {
        $this->_httpTransport = $httpTransport;
    }
    
    /**
     * Call the /admin/ping servlet, can be used to quickly tell if a connection to the
     * server is able to be made.
     *
     * @param float $timeout maximum time to wait for ping in seconds, -1 for unlimited (default is 2)
     * @return float Actual time taken to ping the server, FALSE if timeout or HTTP error status occurs
     */
    public function ping($timeout = 2)
    {
        $start = microtime(true);
        
        $httpTransport = $this->getHttpTransport();
     
        $httpResponse = $httpTransport->performHeadRequest($this->_pingUrl, $timeout);
        $solrResponse = new Viglet_Turing_Response($httpResponse, $this->_createDocuments, $this->_collapseSingleValueArrays);
        
        if ($solrResponse->getHttpStatus() == 200)
        {
            return microtime(true) - $start;
        }
        else
        {
            return false;
        }
    }
}