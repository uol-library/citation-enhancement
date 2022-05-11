<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to export reading list citations from the Alma Courses API 
 * 
 * =======================================================================
 * 
 * Input: 
 * None from STDIN 
 * Reading-lists-of-interest hardcoded in script  
 * 
 * Output: 
 * JSON-encoded list of citations on STDOUT
 * 
 * =======================================================================
 *
 * Typical usage: 
 * php getCitationsByModule.php -m PSYC3505 >Data/PSYC3505.json 
 * php getCitationsByModule.php --modcode PSYC3505 >Data/PSYC3505.json 
 * php getCitationsByModule.php -m PSYC3505,PSYC3506,PSYC3507 >Data/PSYC.json 
 * 
 * This script is the start of the typical citation-enhancement process: 
 * It will typically be followed by the various enhanceCitationsFrom....php scripts  
 * 
 * =======================================================================
 * 
 * General process: 
 * 
 * Make an empty list of citations 
 * 
 * For each list to process we have a course code and a list code: 
 * 
 *  - Using the Courses API, turn the course code into a course ID 
 *  - Using the Courses API, fetch the list of reading lists belonging to this Course ID 
 *    - When we find a list that matches the desired list code, fetch all the citations in the reading list 
 *    - Select the data of interest and save it in a citation object 
 *    - Add the new citation object to the list of citations 
 *  
 * Export the list of citations as JSON 
 * 
 * =======================================================================
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * Data from Leganto is cleaned using utils.php:standardise() which 
 * allows better comparison between data from different sources  
 * Specifically, it removes the Heavy Asterisk characters that are present at the start of some titles in Leganto - 
 * These are present in some lists migrated from the Leeds reading lists system, where the tutor has used an 
 * asterisk to indicate essential reading - but their presence in the Title field will cause problems later 
 * when we search APIs to find matching records 
 * 
 * 
 * 
 */


error_reporting(E_ALL);                     // we want to know about all problems

require_once("utils.php");                  // Helper functions 


$shortopts = 'm:';
$longopts = array('modcode:');
$options = getopt($shortopts,$longopts);
// defaults
$modcodes = FALSE;
$modulesToInclude = Array(); 
// set options
if (isset($options['m']) && $options['m']) {
    $modcodes = $options['m'];
} else if (isset($options['modcode']) && $options['modcode']) {
    $modcodes = $options['modcode'];
} 
if ($modcodes) { 
    foreach (preg_split('/\s*[,;:\.\s]+\s*/', $modcodes) as $modcode) {
        if (preg_match('/\w+/', $modcode)) {
            $modulesToInclude[] = $modcode; 
        }
    }
}
if (!count($modulesToInclude)) {
    trigger_error("Error: Must specify module codes in -m or --modcode option", E_USER_ERROR);
}


// Alma Courses API 
require 'AlmaAPI/AlmaCourses.php';
// require 'AlmaAPI/LULAlmaCodeTables.php';
require 'AlmaAPI/AlmaReadingLists.php';
$course_endpoint = new AlmaCourses();
$list_endpoint = new AlmaReadingLists();
//$course_endpoint->setDebug(TRUE);        // during testing 
//$list_endpoint->setDebug(TRUE);


$citations = Array();
/* 
 * in retrospect this should be an array of modules not citations, but
 * simpler to leave it as it is. so as not to refactor other scripts 
 * 
 * we can find modcodes which don't exist in Alma by filtering for "citations" without 
 * a value of Course.course_code 
 * and for modules which are in Alma but don't have any list by filtering for "citations" with 
 * a value of Course.course_code but no Leganto value 
 * 
 */ 


foreach ($modulesToInclude as $modcode) { 
    
    $newCitationCourse = Array("modcode"=>$modcode); 
    
    usleep(200000); // to avoid hitting API too hard
    
    $course_records = $course_endpoint->searchCourses($modcode, "searchable_ids", 100, 0, "false");
    
    if ($course_endpoint->error) {
        trigger_error($course_endpoint->error, E_USER_ERROR);
    }
    
    if (!isset($course_records["course"])) { 
        // modcode not found in Alma - create a dummy "citation" 
        $citations[] = Array("Course"=>$newCitationCourse); 
    } else { 

        foreach ($course_records["course"] as $course_record) {
            
            $course_code = $course_record["code"];
            $course_id = $course_record["id"];
            
            usleep(200000); // to avoid hitting API too hard
            
            $list_records = $list_endpoint->retrieveReadingLists($course_id);
            if ($list_endpoint->error) {
                trigger_error($list_endpoint->error, E_USER_ERROR);
            }
            
            $newCitationCourse["course_code"] = $course_code; 
            
            if (!isset($list_records["reading_list"]) || !count($list_records["reading_list"])) {
                // course has no reading lists in Alma/Leganto - create a dummy "citation"
                $citations[] = Array("Course"=>$newCitationCourse);
            } else {
            
                foreach ($list_records["reading_list"] as $list_record) {
                    
                    $list_code = $list_record["code"];
                    $list_title = $list_record["name"];
                    
                    $list_full_record = $list_endpoint->retrieveReadingList($course_id, $list_record["id"], "full");
                    if ($list_endpoint->error) {
                        trigger_error($list_endpoint->error, E_USER_ERROR);
                    }
                    
                    $citationNumber = 1; // NB start at one not zero 
                    
                    foreach ($list_full_record["citations"]["citation"] as $citation) {
                        
                        // $to_keep = Array("id"=>TRUE, "type"=>TRUE, "secondary_type"=>TRUE);
                        $to_keep = Array("id"=>TRUE, "secondary_type"=>TRUE, "source2"=>TRUE);
                        $to_keep_metadata = Array("title"=>TRUE, "journal_title"=>TRUE, "article_title"=>TRUE, "author"=>TRUE, "chapter_title"=>TRUE, "chapter_author"=>TRUE, "additional_title"=>TRUE, "additional_author"=>TRUE, "isbn"=>TRUE, "issn"=>TRUE, "place_of_publication"=>TRUE, "publication_date"=>TRUE, "editor"=>TRUE, "doi"=>TRUE, "lccn"=>TRUE, "mms_id"=>TRUE, "source"=>TRUE);
                        $newCitationLeganto = array_filter(array_intersect_key($citation, $to_keep));
                        $newCitationLeganto["list_code"] = $list_code;
                        $newCitationLeganto["list_title"] = $list_title;
                        $newCitationLeganto["section"] = $citation["section_info"]["name"];
                        $newCitationLeganto["citation"] = $citationNumber++;
                        
                        // don't yet have section tags in any lists, but for when we do...
                        if (isset($citation["section_info"]["section_tags"]["section_tag"])) {
                            $newCitationLeganto["section_tags"] = Array();
                            foreach ($citation["section_info"]["section_tags"]["section_tag"] as $tag) {
                                if ($tag["type"]["value"]=="PUBLIC") {
                                    $newCitationLeganto["section_tags"][] = $tag["value"];
                                }
                            }
                        }
                        if (isset($citation["citation_tags"]["citation_tag"])) {
                            $newCitationLeganto["citation_tags"] = Array();
                            foreach ($citation["citation_tags"]["citation_tag"] as $tag) {
                                if ($tag["type"]["value"]=="PUBLIC") {
                                    $newCitationLeganto["citation_tags"][] = $tag["value"];
                                }
                            }
                        }
                        
                        $newCitationLeganto_metadata = array_intersect_key($citation["metadata"], $to_keep_metadata);
                        $newCitationLeganto_metadata = array_map('standardise', $newCitationLeganto_metadata);
                        $newCitationLeganto["metadata"] = array_filter($newCitationLeganto_metadata); // array_filter to remove empty fields
                        
                        $citations[] = Array("Course"=>$newCitationCourse, "Leganto"=>$newCitationLeganto);
                    }
                    
                }
                
            }
            
        }
        
    }
    
    
}


print json_encode($citations, JSON_PRETTY_PRINT);


?>