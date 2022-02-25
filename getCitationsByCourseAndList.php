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
 * None 
 * 
 * Output: 
 * JSON-encoded list of citations on STDOUT
 * 
 * =======================================================================
 *
 * Typical usage: 
 * php getCitationsByCourseAndList.php >Data/1.json 
 * 
 * This script is the start of the typical citation-enhancement process: 
 * It will typically be followed by the various enhanceCItationsFrom....php scripts  
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


// Configuration - hardcode the lists we want to extract 
//TODO: Provide a better way to identify the lists for processing 
$lists_to_process = Array(
    /*
     Array("course_code"=>"28573_SOEE5531M", "list_code"=>"202122_SOEE5531M__8970365_1"),
     Array("course_code"=>"32925_MEDS5107M", "list_code"=>"202122_MEDS5107M__9256341_1_B"),
     Array("course_code"=>"37648_LLLC0189", "list_code"=>"202122_LLLC0189__9226086_1")
     */
    Array("course_code"=>"29679_HIST1055", "list_code"=>"202122_HIST1055__9463092_1")
);



// Alma Courses API 
// NB this assiumes a copy of this client is installed
// in a sibling-folder to this project, so that the relative paths work
// The client is in:
// https://dev.azure.com/uol-support/Library%20API/_git/AlmaAPI?path=%2F&version=GBrl-export&_a=contents
require '../AlmaAPI/private/AlmaAPI/LULAlmaCourses.php';
require '../AlmaAPI/private/AlmaAPI/LULAlmaCodeTables.php';
require '../AlmaAPI/private/AlmaAPI/LULAlmaReadingLists.php';
$course_endpoint = new LULAlmaCourses();
$list_endpoint = new LULAlmaReadingLists();
// $course_endpoint->setDebug(TRUE);        // during testing 
// $list_endpoint->setDebug(TRUE);


$citations = Array(); 


foreach ($lists_to_process as $list_to_process) { 
    
    $course_code = $list_to_process["course_code"];
    $list_code = $list_to_process["list_code"];
    
    $course_records = $course_endpoint->searchCourses($course_code);
    foreach ($course_records["course"] as $course_record) {
        $course_id = $course_record["id"];
        $list_records = $list_endpoint->retrieveReadingLists($course_id);
        
        foreach ($list_records["reading_list"] as $list_record) {
            
            if ($list_record["code"]==$list_code) {
                
                $list_full_record = $list_endpoint->retrieveReadingList($course_id, $list_record["id"], "full");
                
                foreach ($list_full_record["citations"]["citation"] as $citation) {
                    
                    // $to_keep = Array("id"=>TRUE, "type"=>TRUE, "secondary_type"=>TRUE);
                    $to_keep = Array("id"=>TRUE, "secondary_type"=>TRUE, "source2"=>TRUE);
                    $to_keep_metadata = Array("title"=>TRUE, "journal_title"=>TRUE, "article_title"=>TRUE, "author"=>TRUE, "chapter_title"=>TRUE, "chapter_author"=>TRUE, "additional_title"=>TRUE, "additional_author"=>TRUE, "isbn"=>TRUE, "issn"=>TRUE, "place_of_publication"=>TRUE, "publication_date"=>TRUE, "editor"=>TRUE, "doi"=>TRUE, "lccn"=>TRUE, "mms_id"=>TRUE, "source"=>TRUE);
                    $new_citation = array_filter(array_intersect_key($citation, $to_keep));
                    $new_citation["course_code"] = $course_code;
                    $new_citation["list_code"] = $list_code; 
                    $new_citation["section"] = $citation["section_info"]["name"];
                    // don't yet have section tags in any lists, but for when we do...
                    if (isset($citation["section_info"]["section_tags"]["section_tag"])) {
                        $new_citation["section_tags"] = Array();
                        foreach ($citation["section_info"]["section_tags"]["section_tag"] as $tag) {
                            if ($tag["type"]["value"]=="PUBLIC") {
                                $new_citation["section_tags"][] = $tag["value"];
                            }
                        }
                    }
                    if (isset($citation["citation_tags"]["citation_tag"])) {
                        $new_citation["citation_tags"] = Array();
                        foreach ($citation["citation_tags"]["citation_tag"] as $tag) {
                            if ($tag["type"]["value"]=="PUBLIC") {
                                $new_citation["citation_tags"][] = $tag["value"];
                            }
                        }
                    }
                    
                    $new_citation_metadata = array_intersect_key($citation["metadata"], $to_keep_metadata);
                    $new_citation_metadata = array_map('standardise', $new_citation_metadata);
                    $new_citation["metadata"] = array_filter($new_citation_metadata); // array_filter to remove empty fields
                    
                    $citations[] = Array("Leganto"=>$new_citation);
                }
                
                break 2;
            }
        }
    }
    
    
}


print json_encode($citations, JSON_PRETTY_PRINT);


?>