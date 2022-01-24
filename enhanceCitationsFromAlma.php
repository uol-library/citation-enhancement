<?php 

/*
 * Expects JSON input from stdin
 */


error_reporting(E_ALL);                     // we want to know about all problems

require '../AlmaAPI/private/AlmaAPI/LULAlmaBibs.php';

$bib_endpoint = new LULAlmaBibs('bibs');
// $bib_endpoint->setDebug(TRUE);


function standardise($string) {
    $string = preg_replace('/\s*\/\s*$/', "", $string);
    $string = trim($string);
    return $string;
}


$citations = json_decode(file_get_contents("php://stdin"), TRUE);


foreach ($citations as &$citation) { 
    
    if (isset($citation["Leganto"]["metadata"]["mms_id"]) && $citation["Leganto"]["metadata"]["mms_id"]) {
        
        $citation["Alma"] = Array(); // will populate 
        $bib_record = $bib_endpoint->retrieveBib($citation["Leganto"]["metadata"]["mms_id"]);
        // print_r($bib_record);
        
        
        $citation["Alma"]["titles"] = Array();
        $citation["Alma"]["creators"] = Array();
        $citation["Alma"]["isbns"] = Array();
        $citation["Alma"]["issns"] = Array();
        $citation["Alma"]["lccns"] = Array();
        
        $creatorsSeen = Array();
        $anies = $bib_record["anies"];
        foreach ($anies as $anie) {
            $anie = str_replace("encoding=\"UTF-16\"?>", "encoding=\"UTF-8\"?>", $anie); // kludge
            $xmlrecord = new SimpleXmlElement($anie);
            foreach ($xmlrecord->datafield as $field) {
                if (in_array($field["tag"], Array("245", "210", "240", "242", "243", "246", "247", "730", "740"))) {
                    $title = Array();
                    $raw = "";
                    foreach ($field->subfield as $subfield) {
                        if (in_array($subfield["code"], Array("a","b"))) {
                            $raw .= $subfield->__toString()." ";
                        }
                    }
                    $title = trim(standardise($raw));
                    if ($title) {
                        $citation["Alma"]["titles"][] = $title;
                    }
                }
                if (in_array($field["tag"], Array("010"))) {
                    foreach ($field->subfield as $subfield) {
                        if (in_array($subfield["code"], Array("a"))) {
                            $raw = $subfield->__toString();
                            if ($raw) { 
                                $citation["Alma"]["lccns"][] = $raw; 
                            }
                        }
                    }
                }
                if (in_array($field["tag"], Array("020"))) {
                    foreach ($field->subfield as $subfield) {
                        if (in_array($subfield["code"], Array("a"))) {
                            $raw = $subfield->__toString();
                            if ($raw) { 
                                $citation["Alma"]["isbns"][] = $raw;
                            }
                        }
                    }
                }
                if (in_array($field["tag"], Array("022"))) {
                    foreach ($field->subfield as $subfield) {
                        if (in_array($subfield["code"], Array("a"))) {
                            $raw = $subfield->__toString();
                            if ($raw) {
                                $citation["Alma"]["issns"][] = $raw;
                            }
                        }
                    }
                }
                
                
                
                if (in_array($field["tag"], Array("100", "700"))) {
                    $creator = Array();
                    $raw = "";
                    foreach ($field->subfield as $subfield) {
                        if (in_array($subfield["code"], Array("a","b","c","d","q"))) {
                            $raw .= $subfield->__toString()." ";
                        }
                    }
                    $creator = trim(standardise($raw));
                    if ($creator && !isset($creatorsSeen[$creator])) {
                        $citation["Alma"]["creators"][] = $creator;
                        $creatorsSeen[$creator] = TRUE;
                    }
                }
            }
            
        }
        
        
        
    }
    
    
    
}




print json_encode($citations, JSON_PRETTY_PRINT);






?>