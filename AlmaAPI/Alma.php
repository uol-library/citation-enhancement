<?php
	// Interface to Alma using the standard API
	// This is a base class containing key connection/retrieval methods and properties
	// There is a sub-class for each Alma API. Each of those should be sub-classed for
	// actual interaction with Alma

	require_once 'LULConstants.php';

	class LULAlma {
		/**
		* The Alma URL
		* @access private
		* @var string
		*/
		var $host;
		/**
		* The Alma API URL
		* @access private
		* @var string
		*/
		var $apiUrl;
		/**
		* The Alma API key type
		* @access private
		* @var string
		*/
		var $apiKeyType;
		/**
		* The Alma API key
		* @access private
		* @var string
		*/
		var $apiKey;
		/**
		* The API Client object for current conversation
		* @access private
		* @var string
		*/
		var $apiClient;
		/**
		* The default Alma return format - xml or json
		* @access private
		* @var string
		*/
		var $apiFormat;
		/**
		* The set of API fields
		* @access private
		* @var string
		*/
		var $fields;
		/**
		* The default Alma field to use for searching
		* @access private
		* @var array
		*/
		var $field;
		/**
		* The set API error messages
		* @access private
		* @var array
		*/
		var $errorMessages;
		/**
		* Whether the Alma target is available
		* @access private
		* @var boolean
		*/
		var $isAlive;
		/**
		* Whether to ouput debug messages
		* @access private
		* @var boolean
		*/
		var $debug;
		/**
		* The name of the log file
		* @access private
		* @var string
		*/
		var $logFile;
		/**
		* Error message if there's an error
		* @access private
		* @var string
		*/
		var $error;

	 	/**
	 	* The constructor simply sets default values.
	 	*/
	 	function __construct($type='users',$field='barcode',$format='json') {
	 		$this->setAPIHost();
	 		$this->setAPIURL();
			$this->setFields();
			$this->setErrorMessages();

	 		$this->apiKeyType	= $type;
	 		$this->apiKey		= $this->getAPIKey($this->apiKeyType);
	 		$this->apiFormat	= $format;
	 		$this->error		= '';
	 		$this->debug		= false;
	 		$this->field		= $field;
	 		$this->apiClient	= null;
			$this->isAlive		= true;
	 	}

		/**
		 * Gets the API Host
		 * @return string
		 */
		protected function getAPIHost() {
			if (!isset($this->apiHost)) $this->setAPIHost();
	 		return $this->apiHost;
		}

		/**
		 * Sets the API Host
		 * @return string
		 */
		protected function setAPIHost($apiHost=LUL_ALMA_API_HOST) {
		    $this->apiHost = $apiHost;
	 		return $this->apiHost;
		}

		/**
		 * Gets the API URL
		 * @return string
		 */
		protected function getAPIURL() {
			if (!isset($this->apiURL)) $this->setAPIURL();
	 		return $this->apiUrl;
		}

		/**
		 * Sets the API URL
		 * @return string
		 */
		protected function setAPIURL($apiURL=LUL_ALMA_API_URL) {
			$this->apiUrl = $apiURL;
	 		return $this->apiUrl;
		}

		/**
		 * Close API connection and unsets object values
		 */
		protected function closeAPIConnection() {
			curl_close($this->apiClient);
			$this->apiClient	= null;
			$this->isAlive		= false;
		}

		/**
		 * Creates a hash for full body parameters required by the API
		 * @param array $details - a hash where key is the body parameter field and value is its value
		 * 							- acts as (partial) overwrite of the default body parameters
		 * @return array - a hash where key is the body parameter field and value is its value
		 */
	 	public function buildBodyParameters($details) {
	 		$ret = array();
	 		$fields = $this->getFields();
	 		foreach ($fields as $k=>$v) {
	 			if (array_key_exists('default',$v)) {
	 				$ret[$k] = $v['default'];
	 			}else {
	 				$ret[$k] = '';
	 			}
	 		}
	 		return array_merge($ret,$details);
	 	}

		/**
		 * Gets the API fields details
		 * @return string
		 */
		public function getFields() {
	 		if (!isset($this->fields)) $this->setFields();
	 		return $this->fields;
		}

		/**
		 * Sets the API fields details
		 * NEEDS OVERRIDING IN THE SUB-CLASS
		 * @return string
		 */
		protected function setFields() {
			$this->fields = array();
	 		return $this->fields;
		}

		/**
		 * Given a return code, gets the error message
		 * @param integer $code
		 * @return string
		 */
		protected function getErrorMessage($code) {
			$ret = 'UNKNOWN';
			if (array_key_exists($code,$this->errorMessages)) {
				$ret = $this->errorMessages[$code];
			}

			return $ret;
		}

		/**
		 * Sets the error messages
		 * NEEDS OVERRIDING IN THE SUB-CLASS
		 * @return string
		 */
		protected function setErrorMessages() {
			$this->errorMessages = array();
	 		return $this->errorMessages;
		}

		/**
		 * Makes API connection and sets Token
		 * @param string $path - the Restful path to add to the core URL
		 * @param array $params - optional parameters to add to URL in format x=y
		 * @param array $headers - optional HTTP headers
		 * @param boolean $method - GET, POST, PUT or DELETE
		 * @param array $postData - the POST data
		 * @return boolean
		 */
		protected function makeConnection($path,$params=array(),$headers=array(),$method='GET',$postData=null) {
			$response = false;

			$params = array_merge(
				array(
					'apikey='.$this->apiKey,
					'format='.$this->apiFormat,
				),
				$params
			);
			$joiner = (preg_match('/\?/',$path)) ? '&' : '?';
			$url = $this->apiUrl."$path$joiner".implode('&',$params);
			$this->debugMessage($url);
			$client = curl_init($url);
	 		if (isset($client)) {
	 			$this->apiClient = $client;
				curl_setopt($client,CURLOPT_RETURNTRANSFER,true);
				curl_setopt($client,CURLOPT_TIMEOUT,30);
				curl_setopt($client,CURLOPT_HEADER,0);
				curl_setopt($client,CURLOPT_HTTPHEADER,$headers);
				 
				if ($method == 'POST') {
					curl_setopt($client,CURLOPT_POST,true);
					curl_setopt($client,CURLOPT_POSTFIELDS,$postData);
				}elseif ($method == 'PUT') {
					curl_setopt($client, CURLOPT_CUSTOMREQUEST, 'PUT');
					curl_setopt($client,CURLOPT_POSTFIELDS,$postData);
				}
				if ($method == 'DELETE') {
					return; # TODO DEAL WITH THIS
				}

				if ($this->debug) curl_setopt($client,CURLINFO_HEADER_OUT,true);

				$response = curl_exec($client);
				$info = curl_getinfo($client);
				$this->debugMessage($info);
				$this->debugMessage($response);
			    $err = curl_error($client);
	 			if ($response === false || !empty($err)) {
				    curl_close($client);
				    $this->error = 'Error creating connection to Alma: ' . $err;
				    $this->debugMessage($this->error);
	 			}elseif (is_array($info) &&
	 				array_key_exists('http_code',$info) &&
	 				$info['http_code'] != 200
 				){
 					$this->error = 'Error retrieving data from Alma: HTTP code ' . $info['http_code'];
					$this->debugMessage($this->error);
	 			}else {
					curl_close($client);
					$this->client = $client;
				}
	 		}
			return $response;
		}

		/**
	 	 * Gets the API key
	 	 * @param string $type - the type of Alma API key
	 	 * @return string
	 	 */
	 	protected function getAPIKey($type='users') { //TODO
	 		$keys = array(
	 			'users_online_registration'	=> 'l8xx5b80c2016fd54df3a0d3b8175a91bd22', #'l7xxa87b3f88ab5f4cdc9abbbfa79cf841d3',# OLD l7xxf72bbe4f58714b7a99d378434142f544
	 			'users_emu_patrons'			=> 'l8xxf3b7082c9bc741139217d22afe30fb72', #'l7xxa87b3f88ab5f4cdc9abbbfa79cf841d3',# OLD l7xxf72bbe4f58714b7a99d378434142f544
	 			'bibs'  					=> 'l8xxee997e638a044f73bd6469bc1ac63f9a',
	 			'config'					=> 'l8xx43373182ce4842aab016123fcdaa1e4a',
	 			'bibsupdate'				=> 'l8xx5e8ee1962dee419984c9f25ac1d5f8d6',
	 			'primosearchreadinglists'	=> 'l8xxa8b1252af0e3431c82d9d03fcd2e45ca',
	 		    'courses-sandbox'           => 'l8xx169b63e7251c486eb60e08baffad2736',  
	 		    'courses'                   => 'l8xxcce029462b05452c8889963f3581d549', 
	 		    'code-tables-sandbox'           => 'l8xxad18314330284ea99d2b3183405f01cb',
	 		    'code-tables'                   => 'l8xx43373182ce4842aab016123fcdaa1e4a'
	 		    
	 		);
	 		return $keys[$type];
	 	}

	 	/**
	 	 * Outputs debug message if are debugging
	 	 * @param string $msg
	 	 */
	 	protected function debugMessage($msg=null) {
	 		if ($this->debug && !empty($msg)) {
	 			print "\n\n<br/><br><pre>";
	 			print_r($msg);
	 			print "</pre><br/><br>\n\n";
	 		}
	 	}

		/**
		 * Sets the debug status - true or false
		 * @param boolean $debug
		 * @return boolean - the value of $this->debug
		 */
		public function setDebug($debug) {
			$this->debug = $debug;
			return $debug;
		}

		/**
		 * Sets the log file full path
		 * @param string $log
		 * @return string - the full path
		 */
		public function setLogFile($log) {
			$this->logFile = LUL_ALMA_API_LOG_DIR."/$log";
			return $this->logFile;
		}

		/**
		 * Write line of data to the log file
		 * Supplied data is prepended with a date stamp
		 * @param array $data - items to be written on the line
		 */
		public function putLog($data) {
			if (!empty($this->logFile) &&
				is_array($data) &&
				sizeof($data) > 0
			) {
				array_unshift($data,date('Y-m-d H:i:s'));
            	file_put_contents(
            		$this->logFile,
            		implode(' | ',$data)."\n",
            		FILE_APPEND
            	);
			}
			return;
		}

	}
?>