<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to export reading list author affiliation data to TXT or CSV files  
 * for Library staff to do further processing   
 * 
 * Output files have a row-per-citation 
 * (compare with longExport.php which outputs a row-per-author-information) 
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
 * php simpleExport.php <Data\PSYC3505_ASWV.json 
 * Writes a file-per-reading-list from the input citations file, plus a summary file with a row-per-reading-list - the summary file is emptied and recreated with a header row before running   
 *
 * php simpleExport.php -a <Data\PSYC3505_ASWV.json 
 * As above but does not empty the summary file first and does not add a header row 
 * 
 * php simpleExport.php -i 
 * Empty the summary file and add the header row 
 * 
 * i.e. 
 * php simpleExport.php -i 
 * php simpleExport.php -a <Data\PSYC3505_ASWV.json
 * is equivalent to  
 * php simpleExport.php <Data\PSYC3505_ASWV.json
 * 
 * (-i and -a options are used in wrapper-scripts that loop over a number of modules)  
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


$inclusionThreshold = 80; // author-title similarity threshold 



$shortopts = 'ai';
$longopts = array('append', 'initialise');
$options = getopt($shortopts,$longopts);
// defaults
$append = FALSE;
$initialise = FALSE;
// set options
if (isset($options['append']) || isset($options['a'])) {
    $append = TRUE;
}
if (isset($options['initialise']) || isset($options['i'])) {
    $initialise = TRUE;
}



$outFormat  = isset($config["Export"]["Format"]) ? $config["Export"]["Format"] : "CSV"; 
$outBOM     = isset($config["Export"]["BOM"]) ? json_decode('"'.$config["Export"]["BOM"].'"') : "";
$outCountryCounts = isset($config["Export"]["CountryCounts"]) && $config["Export"]["CountryCounts"] ? TRUE : FALSE; 

function outFilename($record) { return $record["LIST-CODE"]; };  
$fileSummary = "Summary";
$outFolder = "Data/"; 


if (!$initialise) { 

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
    
} 

$outputRecords = Array(); 
$rowHeadings = Array("CIT-NUMBER", "CIT-TYPE", "CIT-TAGS", "CIT-TITLE", "CIT-CONTAINER", "CIT-AUTHOR", "DOI-MATCH", "SIMILARITY", "SOURCE", "SOURCE-AUTHORS", "SOURCE-COUNTRIES");

if (!$initialise) { 

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
                
                
                $outputRecord = Array();
                $outputRecord["MOD-CODE"] = $citation["Course"]["modcode"];
                $outputRecord["LIST-CODE"] = $citation["Leganto"]["list_code"];
                $outputRecord["LIST-TITLE"] = $citation["Leganto"]["list_title"];
                $outputRecord["NOTES"] = Array();
                
                $outputRecord["CIT-NUMBER"] = isset($citation["Leganto"]["citation"]) ? $citation["Leganto"]["citation"] : ""; 
                
                if (isset($citation["Leganto"]["secondary_type"])) {
                    $outputRecord["CIT-TYPE"] = $citation["Leganto"]["secondary_type"]["desc"];
                }
                $outputRecord["CIT-TAGS"] = Array();
                if (isset($citation["Leganto"]["section_tags"])) {
                    foreach ($citation["Leganto"]["section_tags"] as $tag) {
                        $outputRecord["CIT-TAGS"][] = $tag["desc"];
                    }
                }
                if (isset($citation["Leganto"]["citation_tags"])) {
                    foreach ($citation["Leganto"]["citation_tags"] as $tag) {
                        $outputRecord["CIT-TAGS"][] = $tag["desc"];
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
                                $outputRecord["CIT-TITLE"] = $almaTitle["collated"];
                                $foundAlmaCitTitle = TRUE;
                                break; // stop at the first one
                            }
                        }
                    }
                    
                    if (!$foundAlmaCitTitle) {
                        if (isset($citation["Leganto"]["metadata"]["title"])) {
                            $outputRecord["CIT-TITLE"] = $citation["Leganto"]["metadata"]["title"];
                        } else if (isset($citation["Leganto"]["metadata"]["journal_title"])) {
                            $outputRecord["CIT-TITLE"] = $citation["Leganto"]["metadata"]["journal_title"];
                        }
                    }
                    
                    if (isset($citation["Leganto"]["metadata"]["article_title"])) {
                        if (isset($outputRecord["CIT-TITLE"])) { $outputRecord["CIT-CONTAINER"] = $outputRecord["CIT-TITLE"]; }
                        $outputRecord["CIT-TITLE"] = $citation["Leganto"]["metadata"]["article_title"];
                    } else if (isset($citation["Leganto"]["metadata"]["chapter_title"])) {
                        if (isset($outputRecord["CIT-TITLE"])) { $outputRecord["CIT-CONTAINER"] = $outputRecord["CIT-TITLE"]; }
                        $outputRecord["CIT-TITLE"] = $citation["Leganto"]["metadata"]["chapter_title"];
                    }
                    
                    if (
                        $citation["Leganto"]["secondary_type"]["value"]=="BK" &&
                        isset($citation["Alma"]) &&
                        isset($citation["Alma"]["creators"]) &&
                        count($citation["Alma"]["creators"])
                        ) {
                            $outputRecord["CIT-AUTHOR"] = array_map(function($a) { return $a["collated"]; }, $citation["Alma"]["creators"]);
                        } else if (isset($citation["Leganto"]["metadata"]["author"])) {
                            $outputRecord["CIT-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
                        }
                        
                        $sources = Array();
                        
                        if (isset($citation["Scopus"])) {
                            
                            //TODO allow a "force" option to bypass errors
                            if (isset($citation["Scopus"]["errors"])) {
                                trigger_error("Error from Scopus integration: ".print_r($citation["Scopus"]["errors"], TRUE), E_USER_ERROR);
                                exit;
                            }
                            
                            
                            if (isset($citation["Scopus"]["first-match"]) && isset($citation["Scopus"]["first-match"]["authors"])) {
                                
                                $outputRecord["DATA"][] = "SCOPUS";
                                $outputRecord["SCOPUS-MATCH"] = "Y";
                                $outputRecord["SCOPUS-AUTHORS"] = Array();
                                $outputRecord["SCOPUS-COUNTRY-CODES"] = Array();
                                $outputRecord["SCOPUS-COUNTRIES"] = Array();
                                
                                $outputRecord["SCOPUS-SEARCH"] = isset($citation["Scopus"]["search-active"]) ? $citation["Scopus"]["search-active"] : NULL;
                                
                                $outputRecord["SCOPUS-SEARCH-DOI"] = ($outputRecord["SCOPUS-SEARCH"] && strpos($outputRecord["SCOPUS-SEARCH"], "DOI")===0) ? TRUE : FALSE;
                                
                                $totalSimilarity = 0;
                                $countSimilarity = 0;
                                $maxSimilarity = FALSE;
                                $minSimilarity = FALSE;
                                
                                foreach ($citation["Scopus"]["first-match"]["authors"] as $author) {
                                    
                                    $contemporaryAffiliation = FALSE; // set to TRUE if we find one
                                    
                                    if (isset($author["similarity-title"]) && isset($author["similarity-author"])) {
                                        $thisSimilarity = floor($author["similarity-title"]*$author["similarity-author"]/100); 
                                        $totalSimilarity += $thisSimilarity;
                                        $countSimilarity++;
                                        if ($maxSimilarity===FALSE || $thisSimilarity>$maxSimilarity) { $maxSimilarity = $thisSimilarity; }
                                        if ($minSimilarity===FALSE || $thisSimilarity<$minSimilarity) { $minSimilarity = $thisSimilarity; }
                                    }
                                    
                                    $outputRecord["SCOPUS-AUTHORS"][] = $author["ce:indexed-name"];
                                    
                                    $thisAuthorCountries = Array();
                                    
                                    if (isset($author["affiliation"]) && is_array($author["affiliation"])) {
                                        foreach ($author["affiliation"] as $authorAffiliation) {
                                            
                                            if (isset($authorAffiliation["country"])) {
                                                if (isset($namesToCodesMap[strtolower($authorAffiliation["country"])])) {
                                                    $nationalityCode = $namesToCodesMap[strtolower($authorAffiliation["country"])];
                                                    $thisAuthorCountries[] = $nationalityCode;
                                                    $contemporaryAffiliation = TRUE;
                                                } else {
                                                    if ($config["General"]["Debug"]) {
                                                        trigger_error("Can't derive nation code for \"".$authorAffiliation["country"]."\": you may need to add a mapping in Config/Countries/nameAlias.json", E_USER_NOTICE);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    if (!$contemporaryAffiliation) { // no contemporary affiliation, try current instead
                                        if (isset($author["affiliation-current"]) && is_array($author["affiliation-current"])) {
                                            $outputRecord["NOTES"][] = "Scopus: using current affiliation for at least one author";
                                            foreach ($author["affiliation-current"] as $authorAffiliation) {
                                                if (isset($authorAffiliation["address"])) {
                                                    $nationalityCode = NULL;
                                                    if (isset($authorAffiliation["address"]["@country"])) {
                                                        // 3-digit code
                                                        $nationalityValue = strtoupper($authorAffiliation["address"]["@country"]);
                                                        if (isset($iso2Map[$nationalityValue]) && $iso2Map[$nationalityValue]) {
                                                            if (preg_match('/^[A-Z]{2}$/', $iso2Map[$nationalityValue]) && !preg_match('/^(AA|Q[M-Z]|X[A-Z]|ZZ)$/', $iso2Map[$nationalityValue])) {
                                                                $nationalityCode = $iso2Map[$nationalityValue];
                                                            } else if ($config["General"]["Debug"]) {
                                                                trigger_error("User-assigned country code ".$iso2Map[$nationalityValue], E_USER_NOTICE);
                                                            }
                                                        } else if ($config["General"]["Debug"]) {
                                                            trigger_error("No 3-letter to 2-letter mapping for ".$nationalityValue, E_USER_NOTICE);
                                                        }
                                                    }
                                                    if ($nationalityCode==NULL && isset($authorAffiliation["address"]["country"])) {
                                                        // country name
                                                        $nationalityValue = strtolower($authorAffiliation["address"]["country"]);
                                                        if (isset($namesToCodesMap[$nationalityValue])) {
                                                            $nationalityCode = $namesToCodesMap[$nationalityValue];
                                                        } else if ($config["General"]["Debug"]) {
                                                            trigger_error("No Name to 2-letter mapping for ".$nationalityValue, E_USER_NOTICE);
                                                        }
                                                    }
                                                    if ($nationalityCode!==NULL) {
                                                        $thisAuthorCountries[] = $nationalityCode;
                                                    } else {
                                                        // trigger_error("Can't derive nation code for ".$authorAffiliation["address"]["@country"].":".$authorAffiliation["address"]["country"], E_USER_NOTICE);
                                                    }
                                                }
                                                
                                            }
                                        }
                                    }
                                    
                                    
                                    
                                    $outputRecord["SCOPUS-COUNTRY-CODES"][] = array_unique($thisAuthorCountries);
                                    $outputRecord["SCOPUS-COUNTRIES"][] = array_map(function($code) use ($namesMap) { return ( isset($namesMap[$code]) && $namesMap[$code] ) ? $namesMap[$code] : $code; }, array_unique($thisAuthorCountries));
                                    
                                }
                                
                                
                                if ($countSimilarity) {
                                    // $outputRecord["SCOPUS-SIMILARITY"] = floor($totalSimilarity/$countSimilarity);
                                    $outputRecord["SCOPUS-SIMILARITY"] = $maxSimilarity; 
                                }
                                
                                
                            }
                        }
                        
                        
                        
                        
                        if (isset($citation["WoS"])) {
                            
                            //TODO allow a "force" option to bypass errors
                            if (isset($citation["WoS"]["errors"])) {
                                trigger_error("Error from WoS integration: ".print_r($citation["WoS"]["errors"], TRUE), E_USER_ERROR);
                                exit;
                            }
                            
                            
                            if (isset($citation["WoS"]["first-match"]) && isset($citation["WoS"]["first-match"]["metadata"]) && isset($citation["WoS"]["first-match"]["metadata"]["authors"])) {
                                
                                $outputRecord["DATA"][] = "WOS";
                                $outputRecord["WOS-MATCH"] = "Y";
                                $outputRecord["WOS-AUTHORS"] = Array();
                                $outputRecord["WOS-COUNTRY-CODES"] = Array();
                                $outputRecord["WOS-COUNTRIES"] = Array();
                                $outputRecord["WOS-AUTHOR-COUNTRIES-DISTINCT"] = Array();
                                $outputRecord["WOS-FLOAT-COUNTRIES-DISTINCT"] = Array();
                                $outputRecord["WOS-REPRINT-COUNTRIES-DISTINCT"] = Array();
                                
                                $outputRecord["WOS-SEARCH"] = isset($citation["WoS"]["search-active"]) ? $citation["WoS"]["search-active"] : NULL;
                                
                                $outputRecord["WOS-SEARCH-DOI"] = ($outputRecord["WOS-SEARCH"] && strpos($outputRecord["WOS-SEARCH"], "DO=")===0) ? TRUE : FALSE; 
                                
                                
                                $outputRecord["WOS-TITLE"] = $citation["WoS"]["first-match"]["metadata"]["title"];
                                if (isset($citation["WoS"]["first-match"]["metadata"]["identifiers"]) && isset($citation["WoS"]["first-match"]["metadata"]["identifiers"]["doi"]) && count($citation["WoS"]["first-match"]["metadata"]["identifiers"]["doi"])) {
                                    $outputRecord["WOS-DOI"] = $citation["WoS"]["first-match"]["metadata"]["identifiers"]["doi"][0];
                                } else if (isset($citation["WoS"]["first-match"]["metadata"]["identifiers"]) && isset($citation["WoS"]["first-match"]["metadata"]["identifiers"]["xref_doi"]) && count($citation["WoS"]["first-match"]["metadata"]["identifiers"]["xref_doi"])) {
                                    $outputRecord["WOS-DOI"] = $citation["WoS"]["first-match"]["metadata"]["identifiers"]["xref_doi"][0];
                                } else{
                                    $outputRecord["WOS-DOI"] = NULL;
                                }
                                
                                $totalSimilarity = 0;
                                $countSimilarity = 0;
                                $maxSimilarity = FALSE;
                                $minSimilarity = FALSE;
                                
                                $seenAddresses = Array();
                                
                                
                                foreach ($citation["WoS"]["first-match"]["metadata"]["authors"] as $author) {
                                    
                                    $contemporaryAffiliation = FALSE; // set to TRUE if we find one
                                    
                                    if (isset($author["similarity-title"]) && isset($author["similarity-author"])) {
                                        $thisSimilarity = floor($author["similarity-title"]*$author["similarity-author"]/100);
                                        $totalSimilarity += $thisSimilarity;
                                        $countSimilarity++;
                                        if ($maxSimilarity===FALSE || $thisSimilarity>$maxSimilarity) { $maxSimilarity = $thisSimilarity; }
                                        if ($minSimilarity===FALSE || $thisSimilarity<$minSimilarity) { $minSimilarity = $thisSimilarity; }
                                    }
                                    
                                    $outputRecord["WOS-AUTHORS"][] = $author["display_name"];
                                    
                                    $thisAuthorCountries = Array();
                                    
                                    if (isset($author["addr_no"]) && $author["addr_no"]) {
                                        $seenAddresses[$author["addr_no"]] = TRUE;
                                    }
                                    
                                    if (isset($author["addresses"]) && $author["addresses"]) {
                                        foreach ($author["addresses"] as $address) {
                                            if (isset($address["country"]) && $address["country"]) {
                                                $countryName = $address["country"];
                                                if (!isset($namesToCodesMap[strtolower($countryName)])) {
                                                    // try an alias
                                                    if (isset($countryNameAlias[strtolower($countryName)])) {
                                                        $countryName = $countryNameAlias[strtolower($countryName)];
                                                    }
                                                }
                                                if (isset($namesToCodesMap[strtolower($countryName)])) {
                                                    $nationalityCode = $namesToCodesMap[strtolower($countryName)];
                                                    $thisAuthorCountries[] = $nationalityCode;
                                                    $outputRecord["WOS-AUTHOR-COUNTRIES-DISTINCT"][] = $nationalityCode;
                                                } else {
                                                    if (TRUE || $config["General"]["Debug"]) {
                                                        trigger_error("Can't derive nation code for \"".$address["country"]."\": you may need to add a mapping in Config/Countries/nameAlias.json", E_USER_NOTICE);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    
                                    $thisAuthorCountries = $outputRecord["WOS-AUTHOR-COUNTRIES-DISTINCT"];
                                    $outputRecord["WOS-COUNTRY-CODES"][] = array_unique($thisAuthorCountries);
                                    $outputRecord["WOS-COUNTRIES"][] = array_map(function($code) use ($namesMap) { return ( isset($namesMap[$code]) && $namesMap[$code] ) ? $namesMap[$code] : $code; }, array_unique($thisAuthorCountries));
                                    
                                    
                                    
                                }
                                
                                // floating addresses
                                if (isset($citation["WoS"]["first-match"]["metadata"]["addresses"]) && $citation["WoS"]["first-match"]["metadata"]["addresses"]) {
                                    foreach ($citation["WoS"]["first-match"]["metadata"]["addresses"] as $address) {
                                        if (isset($address["address_spec"]["country"]) && $address["address_spec"]["country"]) {
                                            $countryName = $address["address_spec"]["country"];
                                            if (!isset($namesToCodesMap[strtolower($countryName)])) {
                                                // try an alias
                                                if (isset($countryNameAlias[strtolower($countryName)])) {
                                                    $countryName = $countryNameAlias[strtolower($countryName)];
                                                }
                                            }
                                            if (isset($namesToCodesMap[strtolower($countryName)])) {
                                                $nationalityCode = $namesToCodesMap[strtolower($countryName)];
                                                // $outputRecord["WOS-COUNTRY-CODES"][] = $nationalityCode; 
                                                // $outputRecord["WOS-COUNTRIES"][] = ( isset($namesMap[$nationalityCode]) && $namesMap[$nationalityCode] ) ? $namesMap[$nationalityCode] : $nationalityCode; 
                                                if (!isset($seenAddresses[$address["address_spec"]["addr_no"]]) || !$seenAddresses[$address["address_spec"]["addr_no"]]) {
                                                    $outputRecord["WOS-FLOAT-COUNTRIES-DISTINCT"][] = $nationalityCode;
                                                }
                                            } else {
                                                if (TRUE || $config["General"]["Debug"]) {
                                                    trigger_error("Can't derive nation code for \"".$address["address_spec"]["country"]."\": you may need to add a mapping in Config/Countries/nameAlias.json", E_USER_NOTICE);
                                                }
                                            }
                                        }
                                    }
                                    
                                    // treat floating addresses as if for another "author"
                                    if (count($outputRecord["WOS-FLOAT-COUNTRIES-DISTINCT"])) { 
                                        $thisAuthorCountries = $outputRecord["WOS-FLOAT-COUNTRIES-DISTINCT"];
                                        $outputRecord["WOS-COUNTRY-CODES"][] = array_unique($thisAuthorCountries);
                                        $outputRecord["WOS-COUNTRIES"][] = array_map(function($code) use ($namesMap) { return ( isset($namesMap[$code]) && $namesMap[$code] ) ? $namesMap[$code] : $code; }, array_unique($thisAuthorCountries));
                                    }
                                    
                                    
                                }
                                // reprint addresses
                                if (isset($citation["WoS"]["first-match"]["metadata"]["reprint_addresses"]) && $citation["WoS"]["first-match"]["metadata"]["reprint_addresses"]) {
                                    foreach ($citation["WoS"]["first-match"]["metadata"]["reprint_addresses"] as $address) {
                                        if (isset($address["address_spec"]["country"]) && $address["address_spec"]["country"]) {
                                            
                                            $countryName = $address["address_spec"]["country"];
                                            if (!isset($namesToCodesMap[strtolower($countryName)])) {
                                                // try an alias
                                                if (isset($countryNameAlias[strtolower($countryName)])) {
                                                    $countryName = $countryNameAlias[strtolower($countryName)];
                                                }
                                            }
                                            if (isset($namesToCodesMap[strtolower($countryName)])) {
                                                $nationalityCode = $namesToCodesMap[strtolower($countryName)];
                                                $thisAuthorCountries[] = $nationalityCode;
                                                $outputRecord["WOS-REPRINT-COUNTRIES-DISTINCT"][] = $nationalityCode;
                                                // $outputRecord["WOS-COUNTRY-CODES"][] = $nationalityCode;
                                                // $outputRecord["WOS-COUNTRIES"][] = ( isset($namesMap[$nationalityCode]) && $namesMap[$nationalityCode] ) ? $namesMap[$nationalityCode] : $nationalityCode;
                                            } else {
                                                if (TRUE || $config["General"]["Debug"]) {
                                                    trigger_error("Can't derive nation code for \"$countryName\": you may need to add a mapping in Config/Countries/nameAlias.json", E_USER_NOTICE);
                                                }
                                            }
                                        }
                                    }
                                    
                                }
                                
                                
                                
                                if ($countSimilarity) {
                                    
                                    $outputRecord["WOS-SIMILARITY-AVG"] = floor($totalSimilarity/$countSimilarity);
                                    // $outputRecord["WOS-SIMILARITY"] = floor($totalSimilarity/$countSimilarity);
                                    $outputRecord["WOS-SIMILARITY-MIN"] = $minSimilarity;
                                    $outputRecord["WOS-SIMILARITY-MAX"] = $maxSimilarity;
                                    $outputRecord["WOS-SIMILARITY"] = $maxSimilarity;
                                }
                                
                                $outputRecord["WOS-AUTHOR-COUNTRIES-DISTINCT"] = array_unique($outputRecord["WOS-AUTHOR-COUNTRIES-DISTINCT"]);
                                sort($outputRecord["WOS-AUTHOR-COUNTRIES-DISTINCT"]); // gives something comparable with other sources
                                $outputRecord["WOS-FLOAT-COUNTRIES-DISTINCT"] = array_unique($outputRecord["WOS-FLOAT-COUNTRIES-DISTINCT"]);
                                sort($outputRecord["WOS-FLOAT-COUNTRIES-DISTINCT"]); // gives something comparable with other sources
                                $outputRecord["WOS-REPRINT-COUNTRIES-DISTINCT"] = array_unique($outputRecord["WOS-REPRINT-COUNTRIES-DISTINCT"]);
                                sort($outputRecord["WOS-REPRINT-COUNTRIES-DISTINCT"]); // gives something comparable with other sources
                                
                                // $outputRecord["WOS-COUNTRY-CODES"] = Array($outputRecord["WOS-COUNTRY-CODES"]); // code below assumes array of arrays
                                // $outputRecord["WOS-COUNTRIES"] = Array($outputRecord["WOS-COUNTRIES"]);
                                
                                
                            }
                            
                        }
                        
                        
                        
                        
                        if (isset($citation["VIAF"])) {
                            
                            //TODO allow a "force" option to bypass errors
                            if (isset($citation["VIAF"]["errors"])) {
                                trigger_error("Error from VIAF integration: ".print_r($citation["VIAF"]["errors"], TRUE), E_USER_ERROR);
                                exit;
                            }
                            
                            
                            $outputRecord["DATA"][] = "VIAF";
                            $outputRecord["VIAF-MATCH"] = "Y";
                            $outputRecord["VIAF-AUTHORS"] = Array();
                            $outputRecord["VIAF-COUNTRY-CODES"] = Array();
                            $outputRecord["VIAF-COUNTRIES"] = Array();
                            
                            
                            $outputRecord["VIAF-SEARCH-DOI"] = FALSE; // always false  
                            

                            
                            
                            $totalSimilarity = 0;
                            $countSimilarity = 0;
                            $maxSimilarity = FALSE;
                            $minSimilarity = FALSE;
                            
                            foreach ($citation["VIAF"] as $viafCitation) {
                                
                                if ($viafCitation["data-source"]=="Scopus") {
                                    $outputRecord["NOTES"][]="VIAF: cross-search from Scopus results";
                                }
                                if ($viafCitation["data-source"]=="Leganto") {
                                    $outputRecord["NOTES"][]="VIAF: search from Leganto citation";
                                }
                                
                                if (isset($viafCitation["best-match"])) {
                                    
                                    $thisSimilarity = floor($viafCitation["best-match"]["similarity-title"]*$viafCitation["best-match"]["similarity-author"]/100);
                                    $totalSimilarity += $thisSimilarity;
                                    $countSimilarity++;
                                    if ($maxSimilarity===FALSE || $thisSimilarity>$maxSimilarity) { $maxSimilarity = $thisSimilarity; }
                                    if ($minSimilarity===FALSE || $thisSimilarity<$minSimilarity) { $minSimilarity = $thisSimilarity; }
                                    
                                    if ($thisSimilarity>=$inclusionThreshold) {     // we need to test this here in contrast to WoS and Scopus 
                                                                                    // because each author search is independent - some may be "right" and some "wrong" 

                                        
                                        $outputRecord["VIAF-AUTHORS"][] = $viafCitation["best-match"]["heading"];
                                        $thisAuthorCountries = Array();
                                        
                                        foreach (Array("NAT"=>"nationalities") as $fieldCode=>$countryField) {
                                            
                                            if (isset($viafCitation["best-match"][$countryField]) && is_array($viafCitation["best-match"][$countryField])) {
                                                
                                                foreach ($viafCitation["best-match"][$countryField] as $nationality) {
                                                    
                                                    $nationalityValue = strtoupper($nationality["value"]);
                                                    $nationalityCode = NULL;
                                                    
                                                    if (strlen($nationalityValue)==2) {
                                                        if (preg_match('/^[A-Z]{2}$/', $nationalityValue) && !preg_match('/^(AA|Q[M-Z]|X[A-Z]|ZZ)$/', $nationalityValue)) {
                                                            if (isset($iso2Alias[$nationalityValue])) { 
                                                                // allow aliases e.g. UK=>GB 
                                                                $nationalityCode = $iso2Alias[$nationalityValue];
                                                            } else { 
                                                                $nationalityCode = $nationalityValue;
                                                            }
                                                        } else if ($config["General"]["Debug"]) {
                                                            trigger_error("User-assigned country code ".$nationalityValue, E_USER_NOTICE);
                                                        }
                                                    } else if (strlen($nationalityValue)==3) {
                                                        if (isset($iso2Map[$nationalityValue]) && $iso2Map[$nationalityValue]) {
                                                            if (preg_match('/^[A-Z]{2}$/', $iso2Map[$nationalityValue]) && !preg_match('/^(AA|Q[M-Z]|X[A-Z]|ZZ)$/', $iso2Map[$nationalityValue])) {
                                                                $nationalityCode = $iso2Map[$nationalityValue];
                                                            } else if ($config["General"]["Debug"]) {
                                                                trigger_error("User-assigned country code ".$iso2Map[$nationalityValue], E_USER_NOTICE);
                                                            }
                                                        } else if ($config["General"]["Debug"]) {
                                                            trigger_error("No 3- to 2-letter mapping for ".$nationalityValue, E_USER_NOTICE);
                                                        }
                                                    } else if (
                                                        isset($namesToCodesMap[strtolower($nationalityValue)])
                                                        &&
                                                        $namesToCodesMap[strtolower($nationalityValue)]
                                                    ) {
                                                        $nationalityCode = $namesToCodesMap[strtolower($nationalityValue)]; 
                                                    } else if (
                                                        isset($countryNameAlias[strtolower($nationalityValue)])
                                                        &&
                                                        $countryNameAlias[strtolower($nationalityValue)]
                                                        &&
                                                        isset($namesToCodesMap[strtolower($countryNameAlias[strtolower($nationalityValue)])])
                                                        &&
                                                        $namesToCodesMap[strtolower($countryNameAlias[strtolower($nationalityValue)])]
                                                    ) { 
                                                        $nationalityCode = $namesToCodesMap[strtolower($countryNameAlias[strtolower($nationalityValue)])];
                                                    } else if ($config["General"]["Debug"]) {
                                                        trigger_error("Neither 2- nor 3-letter code nor recognised name ".$nationalityValue, E_USER_NOTICE);
                                                    }
                                                    if ($nationalityCode==NULL) {
                                                        if (isset($namesToCodesMap[strtolower($nationality["value"])])) {
                                                            // try a country name
                                                            $nationalityCode = $namesToCodesMap[strtolower($nationality["value"])];
                                                        } else if ($config["General"]["Debug"]) {
                                                            trigger_error("No Name to 2-letter mapping for ".$nationality["value"], E_USER_NOTICE);
                                                        }
                                                    }
                                                    if ($nationalityCode!==NULL) {
                                                        $thisAuthorCountries[] = $nationalityCode;
                                                    } else {
                                                        if ($config["General"]["Debug"]) {
                                                            trigger_error("Can't derive nation code for \"".$nationality["value"]."\": you may need to add a mapping in Config/Countries/nameAlias.json", E_USER_NOTICE);
                                                        }
                                                    }
                                                    
                                                }
                                            }
                                            
                                        }
                                        
                                        $outputRecord["VIAF-COUNTRY-CODES"][] = array_unique($thisAuthorCountries);
                                        $outputRecord["VIAF-COUNTRIES"][] = array_map(function($code) use ($namesMap) { return ( isset($namesMap[$code]) && $namesMap[$code] ) ? $namesMap[$code] : $code; }, array_unique($thisAuthorCountries));
                                        
                                        
                                    }
                                    
                                    
                                }
                                
                                
                            }
                            
                            if ($countSimilarity) {
                                // $outputRecord["VIAF-SIMILARITY"] = floor($totalSimilarity/$countSimilarity);
                                $outputRecord["VIAF-SIMILARITY"] = $maxSimilarity; 
                            }
                            
                        }
                        
                        
                        
                        $outputRecord["SIMILARITY"] = NULL;
                        $outputRecord["SOURCE"] = NULL;
                        $outputRecord["SOURCE-AUTHORS"] = NULL;
                        $outputRecord["SOURCE-COUNTRY-CODES"] = NULL;
                        $outputRecord["SOURCE-COUNTRIES"] = NULL;
                        $outputRecord["CSI"] = NULL;
                        
                        // filter and combine VIAF, Scopus and WoS data
                        if (in_array($citation["Leganto"]["secondary_type"]["desc"], Array("CR", "E_CR", "JR"))) {    // article-ish
                            $sourcePreferences = Array("SCOPUS", "WOS", "VIAF");
                        } else {
                            $sourcePreferences = Array("VIAF", "SCOPUS", "WOS");
                        }
                        foreach ($sourcePreferences as $sourcePreference) {
                            if (isset($outputRecord["DATA"]) &&
                                is_array($outputRecord["DATA"]) &&
                                in_array($sourcePreference, $outputRecord["DATA"]) &&
                                $outputRecord["$sourcePreference-MATCH"]=="Y" &&
                                (
                                (
                                isset($outputRecord["$sourcePreference-SIMILARITY"]) &&
                                $outputRecord["$sourcePreference-SIMILARITY"] && 
                                $outputRecord["$sourcePreference-SIMILARITY"]>=$inclusionThreshold 
                                ) 
                                || 
                                    $outputRecord["$sourcePreference-SEARCH-DOI"]
                                    )
                                    ) {
                                        // provisionally set for the first source in case we never hit any affiliation data 
                                        if (!isset($outputRecord["SOURCE"])) { 
                                            $outputRecord["SOURCE"] = $sourcePreference;
                                            $outputRecord["DOI-MATCH"] = ( isset($outputRecord["$sourcePreference-SEARCH-DOI"]) && $outputRecord["$sourcePreference-SEARCH-DOI"]) ? "Y" : "N";
                                            $outputRecord["SIMILARITY"] = $outputRecord["$sourcePreference-SIMILARITY"];
                                            $outputRecord["SOURCE-AUTHORS"] = $outputRecord["$sourcePreference-AUTHORS"];
                                            $outputRecord["SOURCE-TITLE"] = isset($outputRecord["$sourcePreference-TITLE"]) ? $outputRecord["$sourcePreference-TITLE"] : "";
                                        }
                                        
                                        
                                        if (count($outputRecord["$sourcePreference-COUNTRIES"]) &&
                                            implode("", array_map(function ($a) { return implode("", $a); }, $outputRecord["$sourcePreference-COUNTRIES"]))>""
                                                ) {
                                                    
                                            // overwrite with the actual source we're using 
                                            $outputRecord["SOURCE"] = $sourcePreference;
                                            $outputRecord["DOI-MATCH"] = ( isset($outputRecord["$sourcePreference-SEARCH-DOI"]) && $outputRecord["$sourcePreference-SEARCH-DOI"]) ? "Y" : "N";
                                            $outputRecord["SIMILARITY"] = $outputRecord["$sourcePreference-SIMILARITY"];
                                            $outputRecord["SOURCE-AUTHORS"] = $outputRecord["$sourcePreference-AUTHORS"];
                                            $outputRecord["SOURCE-TITLE"] = isset($outputRecord["$sourcePreference-TITLE"]) ? $outputRecord["$sourcePreference-TITLE"] : "";
                                                    
                                            $outputRecord["SOURCE-COUNTRY-CODES"] = $outputRecord["$sourcePreference-COUNTRY-CODES"];
                                            $outputRecord["SOURCE-COUNTRIES"] = $outputRecord["$sourcePreference-COUNTRIES"];
                                            break; // don't check any more sources
                                        }
                                    }
                        }
                        
                        
                        
                        
                        $outputRecord["NOTES"] = array_unique($outputRecord["NOTES"]);
                        
                        $outputRecords[] = $outputRecord;
                        
                        
            }
            
        }
        
    }
    
    

    // now go through records and collect country counts
    // we could have done this in the above loop but it may be easier to do it separately
    // NB we need to do it separately foreach output file we're going to make - this is a bit messy
    
    $countryCodeCounts = Array(); // e.g. [ "202122_EAST3703__8629959_1": [ "GB":5, "US":2 ] ]
    foreach ($outputRecords as &$outputRecord) {
        
        $thisFilename = outFilename($outputRecord);
        if (!isset($countryCodeCounts[$thisFilename])) { $countryCodeCounts[$thisFilename] = Array(); }
        
        $countryCodes = $outputRecord["SOURCE-COUNTRIES"];  // array of arrays
        // needs averaging out per-author and per-citation
        
        if ($countryCodes) {
            
            $significantCitationAuthorCount = 0;
            $citationAuthorCounts = Array();
            foreach ($countryCodes as $authorCountryCodes) {
                // within this loop we're processing a single author
                $significantAuthorCountryCount = 0;
                $authorCountryCodeCounts = Array();
                foreach ($authorCountryCodes as $authorCountryCode) {
                    // within this loop we're processing a single affiliation-instance for an author
                    if ($authorCountryCode) {
                        $significantAuthorCountryCount++;
                        if (!isset($authorCountryCodeCounts[$authorCountryCode])) { $authorCountryCodeCounts[$authorCountryCode] = 0; }
                        $authorCountryCodeCounts[$authorCountryCode]++;
                    }
                }
                // now normalise country code counts so add up to one and add to running total
                if ($significantAuthorCountryCount) {
                    $significantCitationAuthorCount++;
                    foreach ($authorCountryCodeCounts as $countryCode=>$countryCount) {
                        if (!isset($citationAuthorCounts[$countryCode])) { $citationAuthorCounts[$countryCode] = 0; }
                        $citationAuthorCounts[$countryCode] += $countryCount/$significantAuthorCountryCount;
                    }
                }
            }
            $outputRecord["significantCitationAuthorCount"] = $significantCitationAuthorCount; 
            // not normalising at citation level - if we have data for two authors, total country count will be 2 not 1! 
            if ($significantCitationAuthorCount) {
                foreach ($citationAuthorCounts as $countryCode=>$countryCount) {
                    $outputRecord[$countryCode] = $countryCount;
                    if (!isset($countryCodeCounts[$thisFilename][$countryCode])) { $countryCodeCounts[$thisFilename][$countryCode] = 0; }
                    $countryCodeCounts[$thisFilename][$countryCode] += $countryCount; // grand total for ordering columns
                }
            }
            
        }
        
    }
    unset($outputRecord); // important! would cause probs in next foreach loop otherwise
    
}


$lastFilename = FALSE;  // once we hit the first record we'll set this 
$out = NULL;            // will be a CSV file handle 
$summary = NULL;        // will be an arry of list-level metadata  
$summaryHeadings = Array("FILE", "MOD-CODE", "LIST-CODE", "LIST-TITLE", "CITATIONS-NON-NOTE", "CITATIONS-WITH-COUNTRY", "AUTHORS-WITH-COUNTRY", "COUNTRY-COUNT", "COUNTRIES"); 
$outSummary = NULL; 


// open the summary file 
if ($outFormat == "CSV") {
    $outSummary = fopen($outFolder.$fileSummary.".".$outFormat, $append ? 'a' : 'w');
}
if ($initialise || !$append) { 
    if ($outFormat == "CSV") {
        fwrite($outSummary, $outBOM);
        fputcsv($outSummary, $summaryHeadings);
    } else if ($outFormat == "TXT") {
        file_put_contents($outFolder.$fileSummary.".".$outFormat, $outBOM.implode("\t", $summaryHeadings)."\n");
    }
}


if (!$initialise) { 

    foreach ($outputRecords as $outputRecord) {
        
        $thisFilename = outFilename($outputRecord);
        if ($thisFilename!==$lastFilename) {
            
            // start a new file
            
            // first, close off any existing ones and export the summary
            // do we want the extra table of country counts below the main one?
            if ($out && $outCountryCounts) {
                insertCountryCounts($thisFilename, $out, $outFormat, $countryCodeCounts, $outFolder);
            }
            if ($outFormat == "CSV" && $out!==NULL) {
                fclose($out);
                $out = NULL;
            }
            if ($outFormat == "CSV" && $summary) {
                fputcsv($outSummary, $summary);
            } else if ($outFormat == "TXT" && $summary) {
                file_put_contents($outFolder.$fileSummary.".".$outFormat, implode("\t", $summary)."\n", FILE_APPEND);
            }
            $summary = NULL;
            
            // now open a new file
            if ($outFormat == "CSV") {
                $out = fopen($outFolder.$thisFilename.".".$outFormat, 'w');
            }
            
            // now output the header
            // add country codes to header row
            $thisRowHeadings = $rowHeadings;
            arsort($countryCodeCounts[$thisFilename]);
            foreach ($countryCodeCounts[$thisFilename] as $countryCode=>$countryCount) {
                // not doing this for now 
                // $thisRowHeadings[] = $countryCode;
            }
            if ($outFormat == "CSV") {
                fwrite($out, $outBOM);
                fputcsv($out, $thisRowHeadings);
            } else if ($outFormat == "TXT") {
                file_put_contents($outFolder.$thisFilename.".".$outFormat, $outBOM.implode("\t", $thisRowHeadings)."\n");
            }
            
            // now start off the summary
            $summary= array_fill_keys($summaryHeadings, NULL);
            foreach($summaryHeadings as $summaryHeading) {
                // use the data from the output record if it is there
                if (isset($outputRecord[$summaryHeading])) {
                    $summary[$summaryHeading] = $outputRecord[$summaryHeading];
                }
            }
            // other summary initialisation
            $summary["FILE"] = $thisFilename;
            $summary["CITATIONS-NON-NOTE"] = 0;     // fill in later 
            $summary["CITATIONS-WITH-COUNTRY"] = 0; // fill in later 
            $summary["AUTHORS-WITH-COUNTRY"] = 0;   // fill in later 
            $summary["COUNTRY-COUNT"] = count($countryCodeCounts[$thisFilename]);
            $summary["COUNTRIES"] = implode(", ", array_keys($countryCodeCounts[$thisFilename]));
            
            // OK remember this filename for future rows
            $lastFilename = $thisFilename;
            
        }
        
        // now output each row and do various summary counts we can only do by processing each row 
        $summary["CITATIONS-NON-NOTE"]++;
        if ($outputRecord["SOURCE-COUNTRIES"] && count($outputRecord["SOURCE-COUNTRIES"])) { 
            $summary["CITATIONS-WITH-COUNTRY"]++; 
            // count authors for whom we have some country data 
            foreach ($outputRecord["SOURCE-COUNTRIES"] as $authorCountries) { 
                if ($authorCountries && count($authorCountries)) {
                    $summary["AUTHORS-WITH-COUNTRY"]++; 
                }
            }
        }
        
        $outputRow = Array();
        
        // the fields we want to export 
        $thisRowHeadings = $rowHeadings;
        // need to add the individual country counts for this list 
        arsort($countryCodeCounts[$thisFilename]);
        foreach ($countryCodeCounts[$thisFilename] as $countryCode=>$countryCount) {
            // not doing this for now
            // $thisRowHeadings[] = $countryCode;
        }
        
        foreach ($thisRowHeadings as $rowHeading) {
            $outputField = FALSE;
            if (!isset($outputRecord[$rowHeading])) {
                $outputField = "";
            } else if (is_array($outputRecord[$rowHeading])) {          // for arrays we will delimit with |
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
        
        if ($outFormat == "CSV") {
            fputcsv($out, $outputRow);
        } else if ($outFormat == "TXT") {
            file_put_contents($outFolder.$thisFilename.".".$outFormat, implode("\t", $outputRow)."\n", FILE_APPEND);
        }
        
    }
    
    // finally, close off any existing ones and export the summary
    // do we want the extra table of country counts below the main one?
    if ($out && $outCountryCounts) {
        insertCountryCounts($thisFilename, $out, $outFormat, $countryCodeCounts, $outFolder);
    }
    if ($outFormat == "CSV" && $out!==NULL) {
        fclose($out);
        $out = NULL;
    }
    if ($outFormat == "CSV" && $summary) {
        fputcsv($outSummary, $summary);
    } else if ($outFormat == "TXT" && $summary) {
        file_put_contents($outFolder.$fileSummary.".".$outFormat, implode("\t", $summary)."\n", FILE_APPEND);
    }
    $summary = NULL;
    
}

if ($outFormat == "CSV" && $outSummary!==NULL) {
    fclose($outSummary);
    $outSummary = NULL;
} 


function insertCountryCounts($thisFilename, $out, $outFormat, $countryCodeCounts, $outFolder) {
    
    // first add two blank lines
    if ($outFormat == "CSV") {
        fputcsv($out, Array(" "));
        fputcsv($out, Array(" "));
    } else if ($outFormat == "TXT") {
        file_put_contents($outFolder.$thisFilename.".".$outFormat, " \n", FILE_APPEND);
        file_put_contents($outFolder.$thisFilename.".".$outFormat, " \n", FILE_APPEND);
    }
    
    // now insert the count table
    
    // header row 
    if ($outFormat == "CSV") {
        fputcsv($out, Array("COUNTRY", "AUTHOR-COUNT"));
    } else if ($outFormat == "TXT") {
        file_put_contents($outFolder.$thisFilename.".".$outFormat, "COUNTRY\tAUTHOR-COUNT\n", FILE_APPEND);
    }
    
    // data 
    
    arsort($countryCodeCounts[$thisFilename]);
    foreach ($countryCodeCounts[$thisFilename] as $countryCode=>$countryCount) {
       
        if ($outFormat == "CSV") {
            fputcsv($out, Array($countryCode,$countryCount));
        } else if ($outFormat == "TXT") {
            file_put_contents($outFolder.$thisFilename.".".$outFormat, "$countryCode\t$countryCount\n", FILE_APPEND);
        }
        
        
    }
    
    
}




?>