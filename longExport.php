<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to export reading list author affiliation data to TXT or CSV files  
 * for Library staff to do further processing   
 * 
 * Output files have a row-per-author-information 
 * (compare with simpleExport.php which outputs a row-per-citation) 
 * 
 * =======================================================================
 * 
 * Input: 
 * JSON-encoded list of citations on STDIN 
 * 
 * Output: 
 * Tab-delim-TXT- or CSV-format files - one file per reading list 
 * 
 * =======================================================================
 *
 * Typical usage: 
 * 
 * e.g. 
 * php longExport.php <Data\PSYC3505_ASWV.json 
 * Writes a file-per-reading-list from the input citations file   
 *
 *  
 * 
 * Unlike other scripts this does not write to STDOUT but instead to a set of files 
 * with names defined by the function outFilename($record) 
 * 
 * The input citation data is assumed to already contain data from Leganto, Alma, Scopus and VIAF  
 * 
 * See getCitationsByModule.php, enhanceCitationsFromAlma.php 
 * enhanceCitationsFrom Scopus.php and enhanceCitationsFromViaf.php for how this data is prepared  
 * 
 * 
 * 
 * =======================================================================
 * 
 * General process: 
 * 
 * Make an empty result set to populate 
 * 
 * Loop over citations - for each citation:  
 *  - Assemble the relevant data from the different sources
 *  - Add the resulting record to the result set   
 *    
 * Export the result set as TXT or CSV 
 * 
 * =======================================================================
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * The output file is (like all the other data in this project) UTF-8-encoded
 * But Excel expects ANSI-encoded CSV files and will not open files as UTF-8 
 * So special characters hash in Excel if using CSV 
 * For this reason, we're for now exporting as TXT (see $outFormat)   
 * 
 * Different sources variously use ISO-2-letter country codes, ISO-3-letter country codes and country names 
 * During the earlier stages of the process (enhanceCitations...) we simply take the data exactly as provided 
 * During this CSV-export process we have to convert everything to a single standard format 
 * Currently we are using ISO-2-letter codes but this could change 
 * For conversion we use JSON mapping tables downloaded from http://country.io/data/ and saved in Config/CountryCodes 
 * Some sources have some records with error or placeholder codes (e.g. "XX") and some have free-text country names in 
 * other languages - currently we ignore anything we cannot recognise  
 * TODO: For a production service we may want to build config data with e.g. translations of country names from other languages
 * 
 * Some affiliation data does not contain country codes or names e.g. the 5xx fields in VIAF might contain a city or an institution 
 * We currently are not using it but 
 * TODO: in a production system we might want to look at whether we can lookup institutions and find their countries 
 *    
 *    
 * 
 * 
 * 
 */


error_reporting(E_ALL);                     // we want to know about all problems


require_once("utils.php"); 


$inclusionThreshold = 60; // author-title similarity threshold - looser than the 80% required in simple export  




$outFormat  = isset(CONFIG["Export"]["Format"]) ? CONFIG["Export"]["Format"] : "CSV";
$outBOM     = isset(CONFIG["Export"]["BOM"]) ? json_decode('"'.CONFIG["Export"]["BOM"].'"') : "";

function outFilename($record) { return $record["LIST-CODE"]."_LONG"; };  
$outFolder = "Data/"; 


// country codes
$iso3Map = json_decode(file_get_contents("Config/CountryCodes/iso3.json"), TRUE);               // 2-letter codes -> 3-letter codes
$namesMap = json_decode(file_get_contents("Config/CountryCodes/names.json"), TRUE);             // 2-letter codes -> Names
$continentMap = json_decode(file_get_contents("Config/CountryCodes/continent.json"), TRUE);     // 2-letter country -> 2-letter continent
$iso2Map = array_flip($iso3Map);                                                                // 3-letter codes -> 2-letter codes
$namesToCodesMap = array_change_key_case(array_flip($namesMap));                                // Names -> 2-letter codes

// country name aliases 
$countryNameAlias = json_decode(file_get_contents("Config/CountryCodes/nameAlias.json"), TRUE);      // e.g. "England" to "United Kingdom"
foreach ($countryNameAlias as $countryNameSource=>$countryNameTarget) {
    $countryNameAlias[strtolower($countryNameSource)] = $countryNameTarget; // to cater for capitalisation inconsistencies, keep a copy in all lower case
}
// ISO 2-letter country code aliases
$iso2Alias = json_decode(file_get_contents("Config/CountryCodes/iso2Alias.json"), TRUE);


$citations = json_decode(file_get_contents("php://stdin"), TRUE);

$outputRecords = Array(); 
$rowHeadings = Array("CIT-NUMBER", "CIT-TYPE", "CIT-TAGS", "CIT-TITLES", "CIT-CONTAINER", "CIT-AUTHORS", "DOI-MATCH", "SIMILARITY", "SOURCE", "SOURCE-TYPE", "SOURCE-TITLES", "SOURCE-CONTAINER", "SOURCE-AUTHORS", "SOURCE-LOCATION-TYPE", "SOURCE-LOCATION");

foreach ($citations as $citation) {
    
    if (!isset($citation["Leganto"])) {
        
        if (isset($citation["Course"]["course_code"]) && $citation["Course"]["course_code"]) {
            trigger_error("Error: Course ".$citation["Course"]["course_code"]." has no reading list in Leganto", E_USER_ERROR);
            exit;
        } else {
            trigger_error("Error: Module code ".$citation["Course"]["modcode"]." does not correspond to a course in Alma", E_USER_ERROR);
            exit;
        }
        
    } else {
        
        if ($citation["Leganto"]["secondary_type"]["value"]!="NOTE") {
            // only do any enhancement for entries in the citations file that have an actual list
            // and which are not notes
            
            $outputRecordBase = Array();    // base information used by all lines
            
            $outputRecordBase["MOD-CODE"] = $citation["Course"]["modcode"];
            $outputRecordBase["LIST-CODE"] = $citation["Leganto"]["list_code"];
            $outputRecordBase["LIST-TITLE"] = $citation["Leganto"]["list_title"];
            
            $outputRecordBase["CIT-NUMBER"] = isset($citation["Leganto"]["citation"]) ? $citation["Leganto"]["citation"] : "";

            
            $outputRecordBase["CIT-TITLES"] = Array();
            $outputRecordBase["CIT-AUTHORS"] = Array();
            
            $foundTitle = FALSE; 
            if (isset($citation["Leganto"]["metadata"]["title"])) {
                $foundTitle = $citation["Leganto"]["metadata"]["title"];
                if (!in_array($foundTitle, $outputRecordBase["CIT-TITLES"])) { 
                    $outputRecordBase["CIT-TITLES"][] = $foundTitle;
                }
            } 
            if (isset($citation["Leganto"]["metadata"]["journal_title"])) {
                $foundTitle = $citation["Leganto"]["metadata"]["journal_title"];
                if (!in_array($foundTitle, $outputRecordBase["CIT-TITLES"])) {
                    $outputRecordBase["CIT-TITLES"][] = $foundTitle;
                }
            }
            
            if (isset($citation["Leganto"]["metadata"]["article_title"])) {
                if ($foundTitle) {
                    $outputRecordBase["CIT-CONTAINER"] = $foundTitle;
                }
                if (!in_array($citation["Leganto"]["metadata"]["article_title"], $outputRecordBase["CIT-TITLES"])) {
                    $outputRecordBase["CIT-TITLES"][] = $citation["Leganto"]["metadata"]["article_title"];
                }
            } 
            if (isset($citation["Leganto"]["metadata"]["chapter_title"])) {
                if ($foundTitle) {
                    $outputRecordBase["CIT-CONTAINER"] = $foundTitle;
                }
                if (!in_array($citation["Leganto"]["metadata"]["chapter_title"], $outputRecordBase["CIT-TITLES"])) {
                    $outputRecordBase["CIT-TITLES"][] = $citation["Leganto"]["metadata"]["chapter_title"];
                }
            }
            if (isset($citation["Leganto"]["metadata"]["author"])) {
                if (!in_array($citation["Leganto"]["metadata"]["author"], $outputRecordBase["CIT-AUTHORS"])) {
                    $outputRecordBase["CIT-AUTHORS"][] = $citation["Leganto"]["metadata"]["author"];
                }
            }
            if (isset($citation["Leganto"]["metadata"]["chapter_author"])) {
                if (!in_array($citation["Leganto"]["metadata"]["chapter_author"], $outputRecordBase["CIT-AUTHORS"])) {
                    $outputRecordBase["CIT-AUTHORS"][] = $citation["Leganto"]["metadata"]["chapter_author"];
                }
            }
            
            if (isset($citation["Alma"]["titles"])) {  
                foreach ($citation["Alma"]["titles"] as $almaTitle) {  
                    if (!in_array($almaTitle["collated"], $outputRecordBase["CIT-TITLES"])) {
                        $outputRecordBase["CIT-TITLES"][] = $almaTitle["collated"];
                    }
                }
            }
            if (isset($citation["Alma"]["creators"])) {
                foreach ($citation["Alma"]["creators"] as $almaCreator) {
                    if (!in_array($almaCreator["collated"], $outputRecordBase["CIT-AUTHORS"])) {
                        $outputRecordBase["CIT-AUTHORS"][] = $almaCreator["collated"];
                    }
                }
            }
            
            if (isset($citation["Leganto"]["secondary_type"])) {
                $outputRecordBase["CIT-TYPE"] = $citation["Leganto"]["secondary_type"]["desc"];
            }
            $outputRecordBase["CIT-TAGS"] = Array();
            if (isset($citation["Leganto"]["section_tags"])) {
                foreach ($citation["Leganto"]["section_tags"] as $tag) {
                    $outputRecordBase["CIT-TAGS"][] = $tag["desc"];
                }
            }
            if (isset($citation["Leganto"]["citation_tags"])) {
                foreach ($citation["Leganto"]["citation_tags"] as $tag) {
                    $outputRecordBase["CIT-TAGS"][] = $tag["desc"];
                }
            }
            
            $foundAlmaCitTitle = FALSE;
            if (
                $citation["Leganto"]["secondary_type"]["value"]=="BK" &&
                isset($citation["Alma"]) &&
                isset($citation["Alma"]["titles"]) &&
                count($citation["Alma"]["titles"])
                ) {
                    foreach ($citation["Alma"]["titles"] as $almaTitle) {
                        if ($almaTitle["tag"]=="245" && $almaTitle["collated"]) {
                            $outputRecordBase["CIT-TITLE"] = $almaTitle["collated"];
                            $foundAlmaCitTitle = TRUE;
                            break; // stop at the first one
                        }
                    }
                }
                
                if (!$foundAlmaCitTitle) {
                    if (isset($citation["Leganto"]["metadata"]["title"])) {
                        $outputRecordBase["CIT-TITLE"] = $citation["Leganto"]["metadata"]["title"];
                    } else if (isset($citation["Leganto"]["metadata"]["journal_title"])) {
                        $outputRecordBase["CIT-TITLE"] = $citation["Leganto"]["metadata"]["journal_title"];
                    }
                }
                
                if (isset($citation["Leganto"]["metadata"]["article_title"])) {
                    if (isset($outputRecordBase["CIT-TITLE"])) { $outputRecordBase["CIT-CONTAINER"] = $outputRecordBase["CIT-TITLE"]; }
                    $outputRecordBase["CIT-TITLE"] = $citation["Leganto"]["metadata"]["article_title"];
                } else if (isset($citation["Leganto"]["metadata"]["chapter_title"])) {
                    if (isset($outputRecordBase["CIT-TITLE"])) { $outputRecordBase["CIT-CONTAINER"] = $outputRecordBase["CIT-TITLE"]; }
                    $outputRecordBase["CIT-TITLE"] = $citation["Leganto"]["metadata"]["chapter_title"];
                }
                
                if (
                    $citation["Leganto"]["secondary_type"]["value"]=="BK" &&
                    isset($citation["Alma"]) &&
                    isset($citation["Alma"]["creators"]) &&
                    count($citation["Alma"]["creators"])
                    ) {
                        $outputRecordBase["CIT-AUTHOR"] = array_map(function($a) { return $a["collated"]; }, $citation["Alma"]["creators"]);
                    } else if (isset($citation["Leganto"]["metadata"]["author"])) {
                        $outputRecordBase["CIT-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
                    }
                    
                    $sources = Array();
                    
                    $generatedHeadEntry = FALSE;
                    
                    $maxCitationSimilarities = Array(); // kludge - we need to stash the citation-level
                    // maximum similarities, because at the point atwhich we assemble each
                    // row we don't yet know the maximum
                    // probably a better way to do this
                    $maxCitationSimilaritiesIndex = 0;
                    
                    if (isset($citation["Scopus"])) {
                        
                        //TODO allow a "force" option to bypass errors
                        if (isset($citation["Scopus"]["errors"])) {
                            trigger_error("Error from Scopus integration: ".print_r($citation["Scopus"]["errors"], TRUE), E_USER_ERROR);
                            exit;
                        }
                        
                        if (isset($citation["Scopus"]["first-match"]) && isset($citation["Scopus"]["first-match"]["authors"])) {
                            
                            $maxCitationSimilaritiesIndex++; // make a new one
                            $maxCitationSimilarities[$maxCitationSimilaritiesIndex] = FALSE;
                            $outputRecordScopus["SIMILARITY-MAX-INDEX"] = $maxCitationSimilaritiesIndex;
                            
                            foreach ($citation["Scopus"]["first-match"]["authors"] as $author) {
                                
                                $outputRecordScopus = $outputRecordBase; // we'll assemble author-instance line here
                                $generatedHeadEntry = TRUE;
                                $outputRecordScopus["SOURCE"] = "SCOPUS";
                                if (isset($author["similarity-title"]) && isset($author["similarity-author"])) {
                                    $outputRecordScopus["SIMILARITY"] = floor($author["similarity-title"]*$author["similarity-author"]/100);
                                    if ($maxCitationSimilarities[$maxCitationSimilaritiesIndex]===FALSE || $outputRecordScopus["SIMILARITY"]>$maxCitationSimilarities[$maxCitationSimilaritiesIndex]) {
                                        $maxCitationSimilarities[$maxCitationSimilaritiesIndex] = $outputRecordScopus["SIMILARITY"];
                                    }
                                    
                                    
                                }
                                $outputRecordScopus["SOURCE-TYPE"] = isset($citation["Scopus"]["first-match"]["summary"]["subtype"]) ? $citation["Scopus"]["first-match"]["summary"]["subtype"] : "";
                                $outputRecordScopus["SOURCE-TITLES"] = isset($citation["Scopus"]["first-match"]["summary"]["dc:title"]) ? $citation["Scopus"]["first-match"]["summary"]["dc:title"] : "";
                                $outputRecordScopus["SOURCE-CONTAINER"] = isset($citation["Scopus"]["first-match"]["summary"]["prism:publicationName"]) ? $citation["Scopus"]["first-match"]["summary"]["prism:publicationName"] : "";
                                
                                $outputRecordScopus["SOURCE-AUTHORS"] = Array();
                                
                                $outputRecordScopus["SCOPUS-SEARCH"] = isset($citation["Scopus"]["search-active"]) ? $citation["Scopus"]["search-active"] : NULL;
                                $outputRecordScopus["DOI-MATCH"] = ($outputRecordScopus["SCOPUS-SEARCH"] && strpos($outputRecordScopus["SCOPUS-SEARCH"], "DOI")===0) ? "Y" : "N";
                                if (isset($author["ce:indexed-name"]) && !in_array($author["ce:indexed-name"], $outputRecordScopus["SOURCE-AUTHORS"])) { 
                                    $outputRecordScopus["SOURCE-AUTHORS"][] = $author["ce:indexed-name"];
                                }
                                if (isset($author["ce:indexed-name"]) && !in_array($author["ce:indexed-name"], $outputRecordScopus["SOURCE-AUTHORS"])) {
                                    $outputRecordScopus["SOURCE-AUTHORS"][] = $author["ce:indexed-name"];
                                }
                                if (isset($author["ce:surname"])) { 
                                    if (isset($author["ce:given-name"]) && !in_array($author["ce:surname"].", ".$author["ce:given-name"], $outputRecordScopus["SOURCE-AUTHORS"])) {
                                        $outputRecordScopus["SOURCE-AUTHORS"][] = $author["ce:surname"].", ".$author["ce:given-name"];
                                    }
                                    if (isset($author["ce:initials"]) && !in_array($author["ce:surname"].", ".$author["ce:initials"], $outputRecordScopus["SOURCE-AUTHORS"])) {
                                        $outputRecordScopus["SOURCE-AUTHORS"][] = $author["ce:surname"].", ".$author["ce:initials"];
                                    }
                                }
                                
                                $outputRecord = $outputRecordScopus; // start a new one from the base + scopus author info
                                
                                if (isset($author["affiliation"]) && is_array($author["affiliation"])) {
                                    foreach ($author["affiliation"] as $authorAffiliation) {
                                        $outputRecord["SOURCE-LOCATION-TYPE"] = "Contemporary affiliation";
                                        $outputRecord["SOURCE-LOCATION"] = Array();
                                        if (isset($authorAffiliation["affiliation-name"])) {
                                            $outputRecord["SOURCE-LOCATION"][] = $authorAffiliation["affiliation-name"];
                                        }
                                        if (isset($authorAffiliation["address"])) {
                                            $outputRecord["SOURCE-LOCATION"][] = $authorAffiliation["address"];
                                        }
                                        if (isset($authorAffiliation["city"])) {
                                            $outputRecord["SOURCE-LOCATION"][] = $authorAffiliation["city"];
                                        }
                                        if (isset($authorAffiliation["country"])) {
                                            $outputRecord["SOURCE-LOCATION"][] = $authorAffiliation["country"];
                                        }
                                        $outputRecords[] = $outputRecord;
                                        $outputRecord = $outputRecordScopus; // start a new one from the base + scopus author info
                                    }
                                }
                                // current affiliation
                                if (isset($author["affiliation-current"]) && is_array($author["affiliation-current"])) {
                                    foreach ($author["affiliation-current"] as $authorAffiliation) {
                                        $outputRecord["SOURCE-LOCATION-TYPE"] = "Current affiliation";
                                        $outputRecord["SOURCE-LOCATION"] = Array();
                                        if (isset($authorAffiliation["sort-name"])) {
                                            $outputRecord["SOURCE-LOCATION"][] = $authorAffiliation["sort-name"];
                                        }
                                        if (isset($authorAffiliation["address"]) && $authorAffiliation["address"] && isset($authorAffiliation["address"]["address-part"])) {
                                            $outputRecord["SOURCE-LOCATION"][] = $authorAffiliation["address"]["address-part"];
                                        }
                                        if (isset($authorAffiliation["address"]) && $authorAffiliation["address"] && isset($authorAffiliation["address"]["city"])) {
                                            $outputRecord["SOURCE-LOCATION"][] = $authorAffiliation["address"]["city"];
                                        }
                                        if (isset($authorAffiliation["address"]) && $authorAffiliation["address"] && isset($authorAffiliation["address"]["state"])) {
                                            $outputRecord["SOURCE-LOCATION"][] = $authorAffiliation["address"]["state"];
                                        }
                                        if (isset($authorAffiliation["address"]) && $authorAffiliation["address"] && isset($authorAffiliation["address"]["country"])) {
                                            $outputRecord["SOURCE-LOCATION"][] = $authorAffiliation["address"]["country"];
                                        }
                                        $outputRecords[] = $outputRecord;
                                        $outputRecord = $outputRecordScopus; // start a new one from the base + scopus author info
                                    }
                                }
                                
                            }
                            
                        }
                    }
                    
                    
                    
                    
                    if (isset($citation["WoS"])) {
                        
                        //TODO allow a "force" option to bypass errors
                        if (isset($citation["WoS"]["errors"])) {
                            trigger_error("Error from WoS integration: ".print_r($citation["WoS"]["errors"], TRUE), E_USER_ERROR);
                            exit;
                        }
                        
                        $totalSimilarity = 0;
                        $countSimilarity = 0;
                        $maxSimilarity = FALSE;
                        $minSimilarity = FALSE;
                        
                        $seenAddresses = Array();
                        
                        if (isset($citation["WoS"]["first-match"]) && isset($citation["WoS"]["first-match"]["metadata"]) && isset($citation["WoS"]["first-match"]["metadata"]["authors"])) {
                            
                            
                            $maxCitationSimilaritiesIndex++; // make a new one
                            $maxCitationSimilarities[$maxCitationSimilaritiesIndex] = FALSE;
                            $outputRecordWoS["SIMILARITY-MAX-INDEX"] = $maxCitationSimilaritiesIndex;
                            
                            foreach ($citation["WoS"]["first-match"]["metadata"]["authors"] as $author) {
                                
                                $outputRecordWoS = $outputRecordBase; // we'll assemble author-instance line here
                                $generatedHeadEntry = TRUE;
                                
                                $outputRecordWoS["SOURCE"] = "WOS";
                                $outputRecordWoS["SOURCE-AUTHORS"] = Array();
                                
                                if (isset($author["similarity-title"]) && isset($author["similarity-author"])) {
                                    $outputRecordWoS["SIMILARITY"] = floor($author["similarity-title"]*$author["similarity-author"]/100);
                                    if ($maxCitationSimilarities[$maxCitationSimilaritiesIndex]===FALSE || $outputRecordWoS["SIMILARITY"]>$maxCitationSimilarities[$maxCitationSimilaritiesIndex]) {
                                        $maxCitationSimilarities[$maxCitationSimilaritiesIndex] = $outputRecordWoS["SIMILARITY"];
                                    }
                                    $thisSimilarity = floor($author["similarity-title"]*$author["similarity-author"]/100);
                                    $totalSimilarity += $thisSimilarity;
                                    $countSimilarity++;
                                    if ($maxSimilarity===FALSE || $thisSimilarity>$maxSimilarity) { $maxSimilarity = $thisSimilarity; }
                                    if ($minSimilarity===FALSE || $thisSimilarity<$minSimilarity) { $minSimilarity = $thisSimilarity; }
                                }
                                
                                $outputRecordWoS["WOS-SEARCH"] = isset($citation["WoS"]["search-active"]) ? $citation["WoS"]["search-active"] : NULL;
                                $outputRecordWoS["DOI-MATCH"] = ($outputRecordWoS["WOS-SEARCH"] && strpos($outputRecordWoS["WOS-SEARCH"], "DO=")===0) ? "Y" : "N";
                                
                                if (isset($author["display_name"]) && !in_array($author["display_name"], $outputRecordWoS["SOURCE-AUTHORS"])) {
                                    $outputRecordWoS["SOURCE-AUTHORS"][] = $author["display_name"];
                                }
                                if (isset($author["wos_standard"]) && !in_array($author["wos_standard"], $outputRecordWoS["SOURCE-AUTHORS"])) {
                                    $outputRecordWoS["SOURCE-AUTHORS"][] = $author["wos_standard"];
                                }
                                if (isset($author["full_name"]) && !in_array($author["full_name"], $outputRecordWoS["SOURCE-AUTHORS"])) {
                                    $outputRecordWoS["SOURCE-AUTHORS"][] = $author["full_name"];
                                }
                                
                                $outputRecordWoS["SOURCE-TYPE"] = isset($citation["WoS"]["first-match"]["metadata"]["doctype"]) ? $citation["WoS"]["first-match"]["metadata"]["doctype"] : "";
                                $outputRecordWoS["SOURCE-TITLES"] = isset($citation["WoS"]["first-match"]["metadata"]["title"]) ? $citation["WoS"]["first-match"]["metadata"]["title"] : "";
                                $outputRecordWoS["SOURCE-CONTAINER"] = isset($citation["WoS"]["first-match"]["metadata"]["source"]) ? $citation["WoS"]["first-match"]["metadata"]["source"] : "";
                                
                                if (isset($author["addr_no"]) && $author["addr_no"]) {
                                    $seenAddresses[$author["addr_no"]] = TRUE;
                                }
                                
                                $outputRecord = $outputRecordWoS; // start a new one from the base + WoS author info
                                
                                if (isset($author["addresses"]) && $author["addresses"]) {
                                    foreach ($author["addresses"] as $address) {
                                        $outputRecord["SOURCE-LOCATION-TYPE"] = "Author address";
                                        $outputRecord["SIMILARITY"] = $thisSimilarity; // kludge
                                        if (isset($address["full_address"])) {
                                            $outputRecord["SOURCE-LOCATION"] = $address["full_address"];
                                        }
                                        $outputRecords[] = $outputRecord;
                                        $outputRecord = $outputRecordWoS; // start a new one from the base + WoS author info
                                    }
                                }
                            }
                            
                            if ($countSimilarity) {
                                $avgSimilarity = floor($totalSimilarity/$countSimilarity);
                                // also have $minSimilarity and $maxSimilarity
                            }
                            
                            // floating addresses
                            if (isset($citation["WoS"]["first-match"]["metadata"]["addresses"]) && $citation["WoS"]["first-match"]["metadata"]["addresses"]) {
                                foreach ($citation["WoS"]["first-match"]["metadata"]["addresses"] as $address) {
                                    if (isset($address["address_spec"])) {
                                        if (!isset($seenAddresses[$address["address_spec"]["addr_no"]]) || !$seenAddresses[$address["address_spec"]["addr_no"]]) {
                                            $outputRecord["SOURCE-LOCATION-TYPE"] = "Unassigned address";
                                            $outputRecord["SOURCE-AUTHORS"] = "";
                                            $outputRecord["SIMILARITY"] = $maxSimilarity; // we have no choice but to use this
                                            if (isset($address["address_spec"]["full_address"])) {
                                                $outputRecord["SOURCE-LOCATION"] = $address["address_spec"]["full_address"];
                                            }
                                            $outputRecords[] = $outputRecord;
                                            $outputRecord = $outputRecordWoS; // start a new one from the base + WoS author info
                                        }
                                    }
                                    
                                }
                            }
                            // reprint addresses
                            if (isset($citation["WoS"]["first-match"]["metadata"]["reprint_addresses"]) && $citation["WoS"]["first-match"]["metadata"]["reprint_addresses"]) {
                                foreach ($citation["WoS"]["first-match"]["metadata"]["reprint_addresses"] as $address) {
                                    
                                    $outputRecord["SOURCE-LOCATION-TYPE"] = "Reprint address";
                                    if (isset($address["names"]) && isset($address["names"]["name"]) && isset($address["names"]["name"]["display_name"])) {
                                        $outputRecord["SOURCE-AUTHORS"] = $address["names"]["name"]["display_name"]; // for reprint addresses we won't bother listing everything 
                                    } else {
                                        $outputRecord["SOURCE-AUTHORS"] = "";
                                    }
                                    $outputRecord["SIMILARITY"] = $maxSimilarity; // we have no choice but to use this
                                    if (isset($address["address_spec"]["full_address"])) {
                                        $outputRecord["SOURCE-LOCATION"] = $address["address_spec"]["full_address"];
                                    }
                                    $outputRecords[] = $outputRecord;
                                    $outputRecord = $outputRecordWoS; // start a new one from the base + WoS author info
                                    
                                    
                                }
                            }
                            
                            // publisher addresses
                            if (isset($citation["WoS"]["first-match"]["metadata"]["publisher"]) && $citation["WoS"]["first-match"]["metadata"]["publisher"]) {
                                
                                $outputRecord["SOURCE-LOCATION-TYPE"] = "Publisher address";
                                $outputRecord["SOURCE-AUTHORS"] = "";
                                $outputRecord["SIMILARITY"] = $maxSimilarity; // we have no choice but to use this
                                if (isset($citation["WoS"]["first-match"]["metadata"]["publisher"]["names"]) && isset($citation["WoS"]["first-match"]["metadata"]["publisher"]["names"]["name"]) && isset($citation["WoS"]["first-match"]["metadata"]["publisher"]["names"]["name"]["display_name"])) {
                                    $outputRecord["SOURCE-LOCATION"] = $citation["WoS"]["first-match"]["metadata"]["publisher"]["names"]["name"]["display_name"];
                                } else {
                                    $outputRecord["SOURCE-LOCATION"] = "";
                                }
                                if (isset($citation["WoS"]["first-match"]["metadata"]["publisher"]["address_spec"]["full_address"])) {
                                    if ($outputRecord["SOURCE-LOCATION"]) { $outputRecord["SOURCE-LOCATION"] = $outputRecord["SOURCE-LOCATION"].", "; } 
                                    $outputRecord["SOURCE-LOCATION"] = $outputRecord["SOURCE-LOCATION"].$citation["WoS"]["first-match"]["metadata"]["publisher"]["address_spec"]["full_address"];
                                }
                                $outputRecords[] = $outputRecord;
                                $outputRecord = $outputRecordWoS; // start a new one from the base + WoS author info
                                
                                
                                
                            }
                            
                            
                        }
                        
                    }
                    
                    
                    
                    
                    if (isset($citation["VIAF"])) {
                        
                        //TODO allow a "force" option to bypass errors
                        if (isset($citation["VIAF"]["errors"])) {
                            trigger_error("Error from VIAF integration: ".print_r($citation["VIAF"]["errors"], TRUE), E_USER_ERROR);
                            exit;
                        }
                        
                        
                        
                        foreach ($citation["VIAF"] as $viafCitation) {
                            
                            
                            if (isset($viafCitation["best-match"])) {
                                
                                
                                $outputRecordViaf = $outputRecordBase; // we'll assemble author-instance line here
                                $generatedHeadEntry = TRUE;
                                
                                $outputRecordViaf["SOURCE"] = "VIAF";
                                $outputRecordViaf["DOI-MATCH"] = "N"; // never searching VIAF by DOI
                                $outputRecordViaf["SIMILARITY"] = floor($viafCitation["best-match"]["similarity-title"]*$viafCitation["best-match"]["similarity-author"]/100);
                                $outputRecordViaf["SOURCE-AUTHORS"] = array_unique($viafCitation["best-match"]["headings-all"]); // all forms of the author's name, not just one selected one
                                
                                $outputRecordViaf["SOURCE-TYPE"] = ""; // always - we have no possible value for this
                                $outputRecordViaf["SOURCE-TITLES"] = isset($viafCitation["best-match"]["best-matching-title"]) ? $viafCitation["best-match"]["best-matching-title"] : "";
                                $outputRecordViaf["SOURCE-CONTAINER"] = ""; // always - we have no possible value for this
                                
                                
                                $outputRecord = $outputRecordViaf; // start a new one from the base + VIAF author info
                                
                                
                                foreach (Array("nationalities"=>"Nationalities", "countriesOfPublication"=>"Countries of publication", "locations"=>"Locations", "affiliations"=>"Affiliations") as $loctype=>$loctypeLabel) {
                                    if (isset($viafCitation["best-match"][$loctype]) && is_array($viafCitation["best-match"][$loctype])) {
                                        $outputRecord["SOURCE-LOCATION-TYPE"] = $loctypeLabel;
                                        $outputRecord["SOURCE-LOCATION"] = Array();
                                        foreach ($viafCitation["best-match"][$loctype] as $location) {
                                            $outputRecord["SOURCE-LOCATION"][] = $location["value"];
                                        }
                                        $outputRecords[] = $outputRecord;
                                        $outputRecord = $outputRecordViaf; // start a new one from the base + VIAF author info
                                        
                                    }
                                }
                                
                            }
                            
                            
                        }
                        
                        
                    }
                    
                    
        }
        
    }
    
}



$lastFilename = FALSE;  // once we hit the first record we'll set this 
$out = NULL;            // will be a CSV file handle 


foreach ($outputRecords as $outputRecord) {
    
    $thisFilename = outFilename($outputRecord);
    if ($thisFilename!==$lastFilename) {
        
        // start a new file
        
        // first, close off any existing ones
        if ($outFormat == "CSV" && $out!==NULL) {
            fclose($out);
            $out = NULL;
        }
        
        // now open a new file
        if ($outFormat == "CSV") {
            $out = fopen($outFolder.$thisFilename.".".$outFormat, 'w');
        }
        
        $thisRowHeadings = $rowHeadings;
        if ($outFormat == "CSV") {
            fwrite($out, $outBOM);
            fputcsv($out, $thisRowHeadings);
        } else if ($outFormat == "TXT") {
            file_put_contents($outFolder.$thisFilename.".".$outFormat, $outBOM.implode("\t", $thisRowHeadings)."\n");
        }
        
        // OK remember this filename for future rows
        $lastFilename = $thisFilename;
        
    }
    
    $outputRow = Array();
    
    $thisRowHeadings = $rowHeadings;
    
    foreach ($thisRowHeadings as $rowHeading) {
        $outputField = FALSE;
        if (!isset($outputRecord[$rowHeading])) {
            $outputField = "";
        } else if (is_array($outputRecord[$rowHeading])) {          // for arrays we will delimit with ,
            $outputFieldParts = Array();
            foreach ($outputRecord[$rowHeading] as $fieldPart) {
                if (is_array($fieldPart)) {                          // for sub-arrays we will delimit with ,
                    $outputFieldParts[] = implode(";", $fieldPart);
                } else {
                    $outputFieldParts[] = $fieldPart;
                }
            }
            $outputField = implode("|", $outputFieldParts);
        } else {
            $outputField = $outputRecord[$rowHeading];
        }
        $outputRow[] = $outputField;
    }
    
    // only output ones over threshold
    $similarity = FALSE; 
    // ugly kludge to figure out what "similarity" we want to compare to the threshold 
    if (isset($outputRecord["SIMILARITY-MAX-INDEX"])) { 
        // for WoS and Scopus, use the citation-level max similarity that we stashed in $maxCitationSimilarities
        $similarity = $maxCitationSimilarities[$outputRecord["SIMILARITY-MAX-INDEX"]]; 
    } else {
        // for VIAF we just use this row's similarity because each author search is independent 
        $similarity = $outputRecord["SIMILARITY"]; 
    }
    if ($similarity>$inclusionThreshold || $outputRecord["DOI-MATCH"]=="Y") { 
        if ($outFormat == "CSV") {
            fputcsv($out, $outputRow);
        } else if ($outFormat == "TXT") {
            file_put_contents($outFolder.$thisFilename.".".$outFormat, implode("\t", $outputRow)."\n", FILE_APPEND);
        }
    }
    
}

// finally, close off any existing ones
if ($outFormat == "CSV" && $out!==NULL) {
    fclose($out);
    $out = NULL;
}







?>