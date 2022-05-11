<?php
	/*
	 * Interface to the Leganto Courses API 
	 * 
	 * https://developers.exlibrisgroup.com/alma/apis/courses/
	 * 
	 * Jonathan Hooper 20210827 
	 * (For now) only implementing GET method 
	 * 
	 * 
	 */

	require_once 'Alma.php';

	class AlmaCourses extends Alma {


		/**
	 	* The constructor simply sets default values.
	 	* 
	 	*/
	 	function __construct($type='courses',$field='course',$format='json') {
			parent::__construct($type,$field,$format);
	 	}


		/**
		 * Get all the courses 
		 * @return array of course objects
		 */
		public function retrieveCourses($limit=100, $offset=0) {
		    $response = $this->makeConnection(
		        "courses?limit=$limit&offset=$offset"
		        );
		    
		    return $this->getCourseTidyUp($response);
		}
		
		
		/**
		 * Get a course using the course ID
		 * @param string $value - course ID value to search for
		 * @return course object
		 */
	 	public function retrieveCourse($value, $view="brief") {
	 		$response = $this->makeConnection(
	 		    "courses/$value?view=$view"
			);

	 		return $this->getCourseTidyUp($response);
	 	}


	 	/**
	 	 * Get all the courses
	 	 * @return array of course objects matching $value and $code e.g. course code
	 	 */
	 	public function searchCourses($value, $code="code", $limit=100, $offset=0, $exactSearch="true") {
	 	    $response = $this->makeConnection(
	 	        "courses?q=$code~$value&limit=$limit&offset=$offset&exact_search=$exactSearch"
	 	        );
	 	    
	 	    return $this->getCourseTidyUp($response);
	 	}
	 	

	 	/**
	 	 * Tidies up after getting course information
	 	 * @param object $response - a course record or an array of course records
	 	 * @return array
	 	 */
	 	private function getCourseTidyUp($response) {
	 		$ret = array();
	 		$this->error = '';

			if ($response === false) {
				$this->error = ' Error: Could not get Alma Course information - failed connection';
			}else {
				$decoded = json_decode($response,true);
				$this->debugMessage($decoded);

				if ($decoded===NULL) {
				    // invalid JSON - may be an error in an XML message?
				    try {
				        $responseXml = new SimpleXMLElement($response);
				        if (strtolower($responseXml->errorsExist->__toString())=="true") {
				            $ret = Array();
				            $this->error = "Error: API returned error: ".$responseXml->errorList->error->errorCode->__toString().": ".$responseXml->errorList->error->errorMessage->__toString();
				        }
				    } catch (Exception $e) {
				        $ret = Array();
				        $this->error = "Error: Could not decode API response: $response";
				    }
				} else if (is_array($decoded) &&
				    ( array_key_exists('course',$decoded) || array_key_exists('code',$decoded) ) 
				) {
					$ret = $decoded;
				} else {
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
   					if (!empty($err)) $this->error = " Error: Could not get Alma Course information: $err";
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
			    '402119'    => 'General error.',
				'401914'	=> 'Course not found.',
			    '401915'    => 'Reading list not found.',
			    '401666'    => 'courseId parameter is not valid.'
			);
	 		return $this->errorMessages;
		}

	}
?>