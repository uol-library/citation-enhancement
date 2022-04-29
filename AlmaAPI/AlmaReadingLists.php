<?php
	/*
	 * Interface to the Leganto Reading Lists API 
	 * 
	 * https://developers.exlibrisgroup.com/alma/apis/courses/
	 * 
	 * Jonathan Hooper 20210827 
	 * (For now) only implementing GET method 
	 * 
	 * 
	 */

	require_once 'LULAlma.php';
	require_once 'LULAlmaCourses.php';
	
	class LULAlmaReadingLists extends LULAlmaCourses { // which in turn extends LULAlma


	    /**
	     * The constructor simply sets default values.
	     *
	     */
	    function __construct($type='courses',$field='reading_list',$format='json') {
	        parent::__construct($type,$field,$format);
	    }

	    /**
	     * Get all the reading lists for a given course 
	     * Relies on previous call to LULAlmaCourses method to get course ID
	     * 
	     * @todo provide a new methd that accepts a course CODE rather than ID, searches for matching courses and returns matching reading lists  
	     * @return array of reading list objects belonging to the provided course ID 
	     */
	    public function retrieveReadingLists($course_id) {
	        $response = $this->makeConnection(
	            "courses/$course_id/reading-lists"
	            );
	        
	        return $this->getReadingListTidyUp($response);
	    }

	    /**
	     * Get the requested reading list for a given course
	     * Relies on previous call to LULAlmaCourses method to get course ID 
	     * and retrieveReadingLists to get list ID 
	     *
	     * @return reading list object
	     */
	    public function retrieveReadingList($course_id, $list_id, $view="brief") {
	        $response = $this->makeConnection(
	            "courses/$course_id/reading-lists/$list_id?view=$view"
	            );
	        
	        return $this->getReadingListTidyUp($response);
	    }
	    
	    /**
	     * Tidies up after getting reading list information
	     * @param object $response - a list record or an array of list records
	     * @return array
	     */
	    private function getReadingListTidyUp($response) {
	        $ret = array();
	        $this->error = '';
	        
	        if ($response === false) {
	            $this->error = ' Error: Could not get Alma Course information - failed connection';
	        }else {
	            $decoded = json_decode($response,true);
	            $this->debugMessage($decoded);
	            if (is_array($decoded) &&
	                ( array_key_exists('reading_list',$decoded) || array_key_exists('code',$decoded) )
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
	                    if (!empty($err)) $this->error = " Error: Could not get Alma Course information: $err";
	                }
	        }
	        return $ret;
	    }
	    
	    
	    

	}
?>