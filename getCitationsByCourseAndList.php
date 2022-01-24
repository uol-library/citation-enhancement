<?php 

/*
 * Writes JSON output to stdout
 */


error_reporting(E_ALL);                     // we want to know about all problems

require '../AlmaAPI/private/AlmaAPI/LULAlmaCourses.php';
require '../AlmaAPI/private/AlmaAPI/LULAlmaCodeTables.php';
require '../AlmaAPI/private/AlmaAPI/LULAlmaReadingLists.php';

$course_endpoint = new LULAlmaCourses();
$list_endpoint = new LULAlmaReadingLists();
// $course_endpoint->setDebug(TRUE);
// $list_endpoint->setDebug(TRUE);


$course_code = "25874_PSYC3505";
$list_code = "202122_PSYC3505__8994937_1"; 

$citations = Array(); 

$course_records = $course_endpoint->searchCourses($course_code);
foreach ($course_records["course"] as $course_record) {
    $course_id = $course_record["id"]; 
    $list_records = $list_endpoint->retrieveReadingLists($course_id);
    foreach ($list_records["reading_list"] as $list_record) {
        if ($list_record["code"]==$list_code) { 
            
            $list_full_record = $list_endpoint->retrieveReadingList($course_id, $list_record["id"], "full");
            
            foreach ($list_full_record["citations"]["citation"] as $citation) {
                $to_keep = Array("id"=>TRUE, "type"=>TRUE, "secondary_type"=>TRUE); 
                $to_keep_metadata = Array("title"=>TRUE, "author"=>TRUE, "isbn"=>TRUE, "issn"=>TRUE, "editor"=>TRUE, "doi"=>TRUE, "lccn"=>TRUE, "mms_id"=>TRUE);
                $new_citation = array_intersect_key($citation, $to_keep);
                $new_citation["metadata"] = array_intersect_key($citation["metadata"], $to_keep_metadata);
                $citations[] = Array("Leganto"=>$new_citation);
            }
            
            break 2; 
        }
    }
}

print json_encode($citations, JSON_PRETTY_PRINT);


?>