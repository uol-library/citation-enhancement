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
 * php getCitationsByModule.php >Data/1.json 
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


// Configuration - hardcode the modules we want to extract lists for  
// $modulesToInclude = Array("PHIL1444","PHIL2600","PHIL3320","PHIL3700","THEO3190"); 

// $modulesToInclude = Array("PHIL1444","PHIL2600","PHIL3320","PHIL3700","THEO3190","THEO2720","MODL2015","MODL2016","MODL3620","MODL3620","LAW2146","LAW5637M","MATH5315M","MATH5315M","SOEE2650","LUBS2125","LUBS1620","LUBS1295","LUBS3340","HPSC2400","HPSC3450","HECS5169M","HECS3295","HECS5186M","HECS5189M","COMP2121","COMP5840M","XJCO2121","OCOM5204M","GEOG1081","GEOG2000","DSUR5130M","DSUR5022M","BLGY3135","SOEE1640");
// $modulesToInclude = Array("PHIL1444","PHIL2600","PHIL3320","PHIL3700","THEO3190","THEO2720","MM9544","MODL3620","MODL3620","LAW2146","LAW5637M","MATH5315M","MATH5315M","SOEE2650","LUBS2125","LUBS1620","LUBS1295","LUBS3340","HPSC2400","HPSC3450","HECS5169M","HECS3295","HECS5186M","HECS5189M","COMP2121","COMP5840M","XJCO2121","OCOM5204M","GEOG1081","GEOG2000","DSUR5130M","DSUR5022M","BLGY3135","SOEE1640");

// $modulesToInclude = Array("PHIL1444","PHIL2600","PHIL3320","PHIL3700","THEO3190","THEO2720","MM9544","MODL3620","MODL3620","LAW2146","LAW5637M","MATH5315M","MATH5315M","SOEE2650","LUBS2125","LUBS1620");
// $modulesToInclude = Array("LUBS1295","LUBS3340","HPSC2400","HPSC3450","HECS5169M","HECS3295","HECS5186M","HECS5189M","COMP2121","COMP5840M","XJCO2121","OCOM5204M","GEOG1081","GEOG2000","DSUR5130M","DSUR5022M","BLGY3135","SOEE1640");
$modulesToInclude = Array("GEOG2000");



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
    
    $course_records = $course_endpoint->searchCourses($modcode, "searchable_ids", 100, 0, "false");
    
    if (!isset($course_records["course"])) { 
        // modcode not found in Alma - create a dummy "citation" 
        $citations[] = Array("Course"=>$newCitationCourse); 
    } else { 

        foreach ($course_records["course"] as $course_record) {
            
            $course_code = $course_record["code"];
            $course_id = $course_record["id"];
            $list_records = $list_endpoint->retrieveReadingLists($course_id);
            
            $newCitationCourse["course_code"] = $course_code; 
            
            if (!isset($list_records["reading_list"]) || !count($list_records["reading_list"])) {
                // course has no reading lists in Alma/Leganto - create a dummy "citation"
                $citations[] = Array("Course"=>$newCitationCourse);
            } else {
            
                foreach ($list_records["reading_list"] as $list_record) {
                    
                    $list_code = $list_record["code"];
                    $list_title = $list_record["name"];
                    
                    $list_full_record = $list_endpoint->retrieveReadingList($course_id, $list_record["id"], "full");
                    
                    foreach ($list_full_record["citations"]["citation"] as $citation) {
                        
                        // $to_keep = Array("id"=>TRUE, "type"=>TRUE, "secondary_type"=>TRUE);
                        $to_keep = Array("id"=>TRUE, "secondary_type"=>TRUE, "source2"=>TRUE);
                        $to_keep_metadata = Array("title"=>TRUE, "journal_title"=>TRUE, "article_title"=>TRUE, "author"=>TRUE, "chapter_title"=>TRUE, "chapter_author"=>TRUE, "additional_title"=>TRUE, "additional_author"=>TRUE, "isbn"=>TRUE, "issn"=>TRUE, "place_of_publication"=>TRUE, "publication_date"=>TRUE, "editor"=>TRUE, "doi"=>TRUE, "lccn"=>TRUE, "mms_id"=>TRUE, "source"=>TRUE);
                        $newCitationLeganto = array_filter(array_intersect_key($citation, $to_keep));
                        $newCitationLeganto["list_code"] = $list_code;
                        $newCitationLeganto["list_title"] = $list_title;
                        $newCitationLeganto["section"] = $citation["section_info"]["name"];
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