<?php
	// Interface to Alma using the Bib API
	// This is a sub-class of Alma containing general methods and properties
	// related to querying and manipulating user-related data.
	// You must sub-class this class for for actual interaction with Alma
	// within your application

	require_once 'Alma.php';

	class AlmaBibs extends Alma {


		/**
	 	* The constructor simply sets default values.
	 	*/
	 	function __construct($type='bibs',$field='mms_id',$format='json') {
			parent::__construct($type,$field,$format);
	 	}

		/**
		 * Sets the API body parameter field details
		 * @return array
		 */
		function setFields() {
			$this->fields = array();
	 		return $this->fields;
		}


		/**
		 * Get a bib record stored in ALMA using the ALMA mms_id
		 * @param string $value - mms_id value to search for
		 * @return array of the bib details
		 */
	 	public function retrieveBib($value) {
	 		$response = $this->makeConnection(
				"bibs/$value"
			);

	 		return $this->getBibTidyUp($response);
	 	}

	 	/**
	 	 * Tidies up after getting bib information
	 	 * @param object $response - a bib record...
	 	 * @return array
	 	 */
		private function getBibTidyUp($response) {
	 		$ret = array();
	 		$this->error = '';

			if ($response === false) {
				$this->error = ' Error: Could not get Alma Bib information - failed connection';
			}else {
				$decoded = json_decode($response,true);
				$this->debugMessage($decoded);
				if (is_array($decoded) &&
					array_key_exists('mms_id',$decoded)
				) {
					$ret = $decoded;
				}else {
					$err = '';
					if (is_array($decoded) &&
						array_key_exists('errorsExist',$decoded) &&
						array_key_exists('errorList',$decoded) &&
						array_key_exists('error',$decoded['errorList']) &&
						is_array($decoded['errorList']['error']) &&
						sizeof($decoded['errorList']['error']) > 0
					){
						$err = $decoded['errorList']['error'][0]['errorCode'].' - '.$decoded['errorList']['error'][0]['errorMessage'];
					}
   					if (!empty($err)) $this->error = " Error: Could not get Alma Bib information: $err";
				}
			}
			return $ret;
		}

		/**
		 * Sets the error messages
		 * @return string
		 */
		public function setErrorMessages() {
			$this->errorMessages = array(
				'401652'	=> 'General Error - An error has occurred while processing the request.',
				'402204'	=> 'Input parameters mmsId X is not numeric.',
				'402203'	=> 'Input parameters mmsId X is not valid.'
			);
	 		return $this->errorMessages;
		}

	}
?>