<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to enhance reading list citations using data from the Alma Bib API 
 * 
 * =======================================================================
 * 
 * Input: 
 * JSON-encoded list of citations on STDIN 
 * 
 * Output: 
 * JSON-encoded list of enhanced citations on STDOUT
 * 
 * =======================================================================
 *
 * Typical usage: 
 * php enhanceCitationsFromAlma.php <Data/1.json >Data/2.json 
 * 
 * The input citation data is assumed to already contain data from Leganto  
 * 
 * See getCitationsByCourseAndList.php for how this data is prepared  
 * 
 * =======================================================================
 * 
 * General process: 
 * 
 * Loop over citations - for each citation: 
 * 
 *  - Check whether Leganto contains an MMS ID 
 *    - If it does not, skip to the next 
 *    - If it does, query the Alma Bib API by this MMS ID and retrieve relevant fields 
 *      e.g. titles, authors, ISBN, ISSN, LCCN
 *      For Title and Author field, save each subfield-of-interest and also save the data from these subfields 
 *      collated together, e.g.:  
 *      {
 *          "tag": "210",
 *          "collated": "Am. hist. rev. (Online)",
 *          "a": "Am. hist. rev.",
 *          "b": "(Online)"
 *      }
 *      Which Marc tags to query, and which subfields we are interested in, are hardcoded below 
 *      TODO: Store this Marc field and subfield information in config.ini?  
 *      Data from some of the fields is cleaned using utils.php:standardise() which 
 *      allows better comparison between data from different sources 
 *  
 * Export the enhanced citations 
 * 
 * =======================================================================
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * 
 * 
 * 
 * 
 */


error_reporting(E_ALL);                     // we want to know about all problems

require_once("utils.php");                  // helper functions 


require '../AlmaAPI/private/AlmaAPI/LULAlmaBibs.php';   // client for the Alma Bib API 
                                                        // NB this assiumes a copy of this client is installed 
                                                        // in a sibling-folder to this project, so that the relative paths work
                                                        // The client is in: 
                                                        // https://dev.azure.com/uol-support/Library%20API/_git/AlmaAPI?path=%2F&version=GBrl-export&_a=contents

$bib_endpoint = new LULAlmaBibs('bibs');                // we'll use this in any calls to the API 
// $bib_endpoint->setDebug(TRUE);                       // during testing 


// fetch the data from STDIN
$citations = json_decode(file_get_contents("php://stdin"), TRUE);


// main loop: process each citation
foreach ($citations as &$citation) { 
    
    if (isset($citation["Leganto"])) { // only do any enhancement for entries in the citations file that have an actual list 
    
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
    
    
    
}




print json_encode($citations, JSON_PRETTY_PRINT);






?>