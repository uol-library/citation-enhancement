<?php 

/*
 * Expects JSON input from stdin
 */


error_reporting(E_ALL);                     // we want to know about all problems

require_once("utils.php"); 


require '../AlmaAPI/private/AlmaAPI/LULAlmaBibs.php';

$bib_endpoint = new LULAlmaBibs('bibs');
// $bib_endpoint->setDebug(TRUE);


$citations = json_decode(file_get_contents("php://stdin"), TRUE);


foreach ($citations as &$citation) { 
    
    if (isset($citation["Leganto"]["metadata"]["mms_id"]) && $citation["Leganto"]["metadata"]["mms_id"]) {
        
        $citation["Alma"] = Array(); // will populate 
        $bib_record = $bib_endpoint->retrieveBib($citation["Leganto"]["metadata"]["mms_id"]);
        // print_r($bib_record);
        
        
        $citation["Alma"]["titles"] = Array();
        $citation["Alma"]["creators"] = Array();
        $citation["Alma"]["ids"] = Array();
        
        $anies = $bib_record["anies"];
        foreach ($anies as $anie) {
            $anie = str_replace("encoding=\"UTF-16\"?>", "encoding=\"UTF-8\"?>", $anie); // kludge
            $xmlrecord = new SimpleXmlElement($anie);
            foreach ($xmlrecord->datafield as $field) {
                
                $tag = $field["tag"]->__toString(); 
                
                if (in_array($tag, Array("245", "210", "240", "242", "243", "246", "247", "730", "740"))) {
                    $acceptedSubfields = Array("a","b"); 
                    $title = Array("tag"=>$tag, "collated"=>""); 
                    foreach ($acceptedSubfields as $acceptedSubfield) { $title[$acceptedSubfield] = ""; } 
                    foreach ($field->subfield as $subfield) {
                        $subfieldCode = $subfield["code"]->__toString(); 
                        if (in_array($subfieldCode, $acceptedSubfields)) {
                            $title["collated"] .= $subfield->__toString()." ";
                            $title[$subfieldCode] .= $subfield->__toString()." ";
                        }
                    }
                    $title["collated"] = trim(standardise($title["collated"]));
                    if (!$title["collated"]) { unset($title["collated"]); } 
                    foreach ($acceptedSubfields as $acceptedSubfield) { 
                        $title[$acceptedSubfield] = trim(standardise($title[$acceptedSubfield]));
                        if (!$title[$acceptedSubfield]) { unset($title[$acceptedSubfield]); }
                    }
                    $citation["Alma"]["titles"][] = $title;
                }
                if (in_array($tag, Array("010"))) {
                    foreach ($field->subfield as $subfield) {
                        if (in_array($subfield["code"], Array("a"))) {
                            $raw = $subfield->__toString();
                            if ($raw) { 
                                if (!isset($citation["Alma"]["ids"]["lccn"])) { $citation["Alma"]["ids"]["lccn"] = Array(); }
                                $citation["Alma"]["ids"]["lccn"][] = $raw; 
                            }
                        }
                    }
                }
                if (in_array($tag, Array("020"))) {
                    foreach ($field->subfield as $subfield) {
                        if (in_array($subfield["code"], Array("a"))) {
                            $raw = $subfield->__toString();
                            if ($raw) { 
                                if (!isset($citation["Alma"]["ids"]["isbn"])) { $citation["Alma"]["ids"]["isbn"] = Array(); }
                                $citation["Alma"]["ids"]["isbn"][] = $raw;
                            }
                        }
                    }
                }
                if (in_array($tag, Array("022"))) {
                    foreach ($field->subfield as $subfield) {
                        if (in_array($subfield["code"], Array("a"))) {
                            $raw = $subfield->__toString();
                            if ($raw) {
                                if (!isset($citation["Alma"]["ids"]["issn"])) { $citation["Alma"]["ids"]["issn"] = Array(); }
                                $citation["Alma"]["ids"]["issn"][] = $raw;
                            }
                        }
                    }
                }
                
                if (in_array($tag, Array("100", "700"))) {
                    $acceptedSubfields = Array("a","b","c","d","q");
                    $creator = Array("tag"=>$tag, "collated"=>"");
                    foreach ($acceptedSubfields as $acceptedSubfield) { $creator[$acceptedSubfield] = ""; }
                    foreach ($field->subfield as $subfield) {
                        $subfieldCode = $subfield["code"]->__toString();
                        if (in_array($subfieldCode, $acceptedSubfields)) {
                            $creator["collated"] .= $subfield->__toString()." ";
                            $creator[$subfieldCode] .= $subfield->__toString()." ";
                        }
                    }
                    $creator["collated"] = trim(standardise($creator["collated"]));
                    if (!$creator["collated"]) { unset($creator["collated"]); }
                    foreach ($acceptedSubfields as $acceptedSubfield) {
                        $creator[$acceptedSubfield] = trim(standardise($creator[$acceptedSubfield]));
                        if (!$creator[$acceptedSubfield]) { unset($creator[$acceptedSubfield]); }
                    }
                    $citation["Alma"]["creators"][] = $creator;
                }
            }
            
        }
        
        
        
    }
    
    
    
}




print json_encode($citations, JSON_PRETTY_PRINT);






?>