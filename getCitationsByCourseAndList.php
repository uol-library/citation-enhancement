<?php 

/*
 * Writes JSON output to stdout
 */


error_reporting(E_ALL);                     // we want to know about all problems


require_once("utils.php"); 



require '../AlmaAPI/private/AlmaAPI/LULAlmaCourses.php';
require '../AlmaAPI/private/AlmaAPI/LULAlmaCodeTables.php';
require '../AlmaAPI/private/AlmaAPI/LULAlmaReadingLists.php';

$course_endpoint = new LULAlmaCourses();
$list_endpoint = new LULAlmaReadingLists();
// $course_endpoint->setDebug(TRUE);
// $list_endpoint->setDebug(TRUE);

/*
$course_code = "25874_PSYC3505";
$list_code = "202122_PSYC3505__8994937_1"; 

$course_code = "29679_HIST1055";
$list_code = "202122_HIST1055__8661554_1";

$course_code = "30214_LUBS2680";
$list_code = "202122_LUBS2680__8694118_1";

$course_code = "38495_LAW5358M";
$list_code = "202122_LAW5358M__9442593_1";

$course_code = "35627_EDUC5264M";
$list_code = "202122_EDUC5264M__8629446_1";


28573_SOEE5531M
202122_SOEE5531M__8970365_1

32925_MEDS5107M
202122_MEDS5107M__9256341_1

37648_LLLC0189
202122_LLLC0189_9226086_1


*/


$lists_to_process = Array(
    Array("course_code"=>"28573_SOEE5531M", "list_code"=>"202122_SOEE5531M__8970365_1"),  
    Array("course_code"=>"32925_MEDS5107M", "list_code"=>"202122_MEDS5107M__9256341_1_B"),
    Array("course_code"=>"37648_LLLC0189", "list_code"=>"202122_LLLC0189__9226086_1")
);

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