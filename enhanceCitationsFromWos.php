<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to enhance reading list citations using data from the WoS Expanded API 
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
 * php enhanceCitationsFromScopus.php <Data/1AS.json >Data/1ASW.json 
 * 
 * The input citation data is assumed to already contain data from Leganto and Alma, and possibly Scopus 
 * 
 * See getCitationsByModule.php and enhanceCitationsFromAlma.php for how this data is prepared  
 * 
 * =======================================================================
 * 
 * General process: 
 * 
 * Loop over citations - for each citation: 
 * 
 *  - Collect the metadata that might potentially be useful in a WoS search
 *  - Prepare a set of progressively-looser search strings from this metadata 
 *  - Try these searches in turn, until a search returns at least one result   
 *  - Take the *first* record in the result set - 
 *    TODO: may need an intelligent way of picking the best record where multiple records are returned 
 *  - Fetch the relevant metadata from this record    
 *  - Calculate string similarities between source and WoS authors and titles 
 *  - Save all the data (including any WoS rate-limit data and errors) in the citation object 
 *  
 * Export the enhanced citations 
 * 
 * =======================================================================
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * You need a developer key for the WoS Expanded API 
 * 
 * Save your key in config.ini 
 * 
 * The API is rate-limited 
 * NB the code below caches the results of an API call to help limit usage 
 * 
 * The code below includes a small delay (usleep(550000)) between API calls because of limit of 2 calls per second  
 * 
 * 
 * 
 * Some nodes in the data may either be single objects, or arrays of more than one object  
 * To make code simpler we have a function forceArray(&$node) that forces everything into an array of objects 
 * even if the array only has one member 
 * 
 * e.g. we call 
 *    forceArray($entry["static_data"]["summary"]["titles"]["title"]);
 * 
 * before running 
 * 
 *    foreach ($entry["static_data"]["summary"]["titles"]["title"] as $title) {
 *        // do something with this title entry 
 *    } 
 * 
 *  
 * 
 */


/*
 * Examples 
 * 
 * TI=("Effects of the COVID-19 recession on the US labor market: Occupation, family, and gender") AND AU=("Albanesi")
 * i.e. surround whole title in "" because of "and" 
 * TI=("Effects of the COVID-19 recession on the US labor market: Occupation, family, and gender") AND AU=("Albanesi") AND IS=(08953309)
 * TI=("Effects of the COVID-19 recession on the US labor market: Occupation, family, and gender") AND AU=("Albanesi") AND IS=(08953309) AND PY=(2021)
 * DO=("10.1257/jep.35.3.3")
 * 
 * TI=("Curved flight paths and sideways vision in peregrine falcons Falco peregrinus") AND AU=("Tucker")
 * i.e. deleting ( and ) from "...peregrine falcons (Falco peregrinus)"  
 * 
 * TI=("Honeybee Navigation: Nature and Calibration of the Odometer") AND AU=("Srinivasan") 
 * i.e. deleting " from around Odometer 
 * 
 * 
 */




error_reporting(E_ALL);                     // we want to know about all problems

$wosCache = Array();                     // because of rate limit, don't fetch unless we have to 

require_once("utils.php");                  // contains helper functions  


// fetch the data from STDIN  
$citations = json_decode(file_get_contents("php://stdin"), TRUE);


// main loop: process each citation 
foreach ($citations as &$citation) { 
    
    if (isset($citation["Leganto"])) { // only do any enhancement for entries in the citations file that have an actual list
    
    $searchParameters = Array();    // collect things in here for the WoS search
    $extraParameters = Array();     // not using in search query but may use e.g. to calculate result to source similarity 
    
    if (isset($citation["Leganto"]["metadata"]["doi"]) && $citation["Leganto"]["metadata"]["doi"]) {
        $doi = $citation["Leganto"]["metadata"]["doi"];
        $doi = preg_replace('/^https?:\/\/doi\.org\//', '', $doi);
        $searchParameters["DOI"] = $doi; 
        
        // fetch some Leganto metadata purely to do similarity check - not for searching 
        if (isset($citation["Leganto"]["metadata"]["chapter_author"])) { 
            $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["chapter_author"];
        } else if (isset($citation["Leganto"]["metadata"]["author"])) {
            $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
        } else if (isset($citation["Leganto"]["metadata"]["editor"])) {
            $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["editor"];
        }
        if (isset($citation["Leganto"]["metadata"]["article_title"])) {
            $extraParameters["LEGANTO-TITLE"] = $citation["Leganto"]["metadata"]["article_title"];
        } else if (isset($citation["Leganto"]["metadata"]["chapter_title"])) {
            $extraParameters["LEGANTO-TITLE"] = $citation["Leganto"]["metadata"]["chapter_title"];
        } else if (isset($citation["Leganto"]["metadata"]["title"])) {
            $extraParameters["LEGANTO-TITLE"] = $citation["Leganto"]["metadata"]["title"];
        }
        
    }
    // we see some articles with type JR - better include them? 
    if (isset($citation["Leganto"]["secondary_type"]["value"]) && in_array($citation["Leganto"]["secondary_type"]["value"], Array("CR", "E_CR", "JR"))
        && isset($citation["Leganto"]["metadata"]["article_title"]) && $citation["Leganto"]["metadata"]["article_title"]) {
            $searchParameters["TITLE"] = $citation["Leganto"]["metadata"]["article_title"];
            if (isset($citation["Leganto"]["metadata"]["issn"]) && $citation["Leganto"]["metadata"]["issn"]) {
                $searchParameters["ISSN"] = $citation["Leganto"]["metadata"]["issn"];
            }
            if (isset($citation["Leganto"]["metadata"]["chapter_author"]) && $citation["Leganto"]["metadata"]["chapter_author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["chapter_author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["chapter_author"];
            } else if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
            } else if (isset($citation["Leganto"]["metadata"]["editor"]) && $citation["Leganto"]["metadata"]["editor"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["editor"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["editor"];
            }
            if (isset($citation["Leganto"]["metadata"]["publication_date"]) && $citation["Leganto"]["metadata"]["publication_date"]) {
                $rawDate = $citation["Leganto"]["metadata"]["publication_date"];
                if (preg_match('/\b(\d{4})\b/', $rawDate, $dateMatches)) { 
                    $searchParameters["DATE"] = $dateMatches[1]; 
                }
            }
    } else if (isset($citation["Leganto"]["secondary_type"]["value"]) && in_array($citation["Leganto"]["secondary_type"]["value"], Array("BK", "E_BK"))
        && isset($citation["Leganto"]["metadata"]["title"]) && $citation["Leganto"]["metadata"]["title"]) {
            $searchParameters["TITLE"] = $citation["Leganto"]["metadata"]["title"];
            if (isset($citation["Leganto"]["metadata"]["isbn"]) && $citation["Leganto"]["metadata"]["isbn"]) {
                $searchParameters["ISBN"] = $citation["Leganto"]["metadata"]["isbn"];
            }
            if (isset($citation["Leganto"]["metadata"]["chapter_author"]) && $citation["Leganto"]["metadata"]["chapter_author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["chapter_author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["chapter_author"];
            } else if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
            } else if (isset($citation["Leganto"]["metadata"]["editor"]) && $citation["Leganto"]["metadata"]["editor"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["editor"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["editor"];
            }
            if (isset($citation["Leganto"]["metadata"]["publication_date"]) && $citation["Leganto"]["metadata"]["publication_date"]) {
                $rawDate = $citation["Leganto"]["metadata"]["publication_date"];
                if (preg_match('/\b(\d{4})\b/', $rawDate, $dateMatches)) {
                    $searchParameters["DATE"] = $dateMatches[1];
                }
            }
    } else if (isset($citation["Leganto"]["secondary_type"]["value"]) && in_array($citation["Leganto"]["secondary_type"]["value"], Array("WS", "CONFERENCE", "OTHER"))
        && isset($citation["Leganto"]["metadata"]["title"]) && $citation["Leganto"]["metadata"]["title"]
        && 
        (
            ( isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"] )
            ||
            ( isset($citation["Leganto"]["metadata"]["editor"]) && $citation["Leganto"]["metadata"]["editor"] )
            ||
            ( isset($citation["Leganto"]["metadata"]["chapter_author"]) && $citation["Leganto"]["metadata"]["chapter_author"] )
            )
        ) {
            $searchParameters["TITLE"] = $citation["Leganto"]["metadata"]["title"];
            if (isset($citation["Leganto"]["metadata"]["isbn"]) && $citation["Leganto"]["metadata"]["isbn"]) {
                $searchParameters["ISBN"] = $citation["Leganto"]["metadata"]["isbn"];
            }
            if (isset($citation["Leganto"]["metadata"]["issn"]) && $citation["Leganto"]["metadata"]["issn"]) {
                $searchParameters["ISSN"] = $citation["Leganto"]["metadata"]["issn"];
            }
            if (isset($citation["Leganto"]["metadata"]["chapter_author"]) && $citation["Leganto"]["metadata"]["chapter_author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["chapter_author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["chapter_author"];
            } else if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
            } else if (isset($citation["Leganto"]["metadata"]["editor"]) && $citation["Leganto"]["metadata"]["editor"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["editor"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["editor"];
            }
            if (isset($citation["Leganto"]["metadata"]["publication_date"]) && $citation["Leganto"]["metadata"]["publication_date"]) {
                $rawDate = $citation["Leganto"]["metadata"]["publication_date"];
                if (preg_match('/\b(\d{4})\b/', $rawDate, $dateMatches)) {
                    $searchParameters["DATE"] = $dateMatches[1];
                }
            }
    }
    
    // now also collect some a-t data from Alma, that we can use to calculate source-WoS similarity
    $extraParameters["ALMA-CREATORS"] = Array();
    $extraParameters["ALMA-TITLES"] = Array();
    $creatorsSeen = Array();
    $titlesSeen = Array();
    // first choice - data from Alma Marc record
    if (isset($citation["Leganto"]["secondary_type"]["value"]) && $citation["Leganto"]["secondary_type"]["value"]=="BK"
        && isset($citation["Leganto"]["metadata"]["mms_id"]) && $citation["Leganto"]["metadata"]["mms_id"]
        ) {
            if (isset($citation["Alma"]) && isset($citation["Alma"]["creators"])) {
                foreach ($citation["Alma"]["creators"] as $creatorAlma) {
                    $creatorAlmaSerialised = print_r($creatorAlma, TRUE);
                    if (isset($creatorAlma["collated"]) && $creatorAlma["collated"] && !in_array($creatorAlmaSerialised, $creatorsSeen)) {
                        $extraParameters["ALMA-CREATORS"][] = $creatorAlma;
                        $creatorsSeen[] = $creatorAlmaSerialised;
                    }
                }
            }
            if (isset($citation["Alma"]) && isset($citation["Alma"]["titles"])) {
                foreach ($citation["Alma"]["titles"] as $titleAlma) {
                    $titleAlmaSerialised = print_r($titleAlma, TRUE);
                    if (isset($titleAlma["collated"]) && $titleAlma["collated"] && !in_array($titleAlmaSerialised, $titlesSeen)) {
                        $extraParameters["ALMA-TITLES"][] = $titleAlma;
                        $titlesSeen[] = $titleAlmaSerialised;
                    }
                }
            }
    }
    
    
    // if ($searchTermEncoded) {
    if (count($searchParameters)) { 
        
        $citation["WoS"] = Array(); // to populate
        
        $searchStrings = Array(); // assemble search parameters into one or more search strings, in order of preference
        
        // 1st choice - DOI 
        if (isset($searchParameters["DOI"]) && $searchParameters["DOI"]) {
            $searchStrings[1] = "DO=(".wosQuote($searchParameters["DOI"], TRUE).")";
        }
        // 2nd choice - title match and author surname *and* pub year *and* isbn/issn 
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TI=(".wosQuote($searchParameters["TITLE"]).")"; 
            $qualifyingField = FALSE;
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"]) { 
                $qualifyingField = TRUE; 
                $searchString .= " AND AU=(".wosQuote($searchParameters["AUTH"]).")"; 
            }
            if (isset($searchParameters["DATE"]) && $searchParameters["DATE"]) { 
                $qualifyingField = TRUE;
                $searchString .= " AND PY=(".wosQuote($searchParameters["DATE"]).")"; 
            }
            if (isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND IS=(".wosQuote($searchParameters["ISSN"]).")"; 
            } else if (isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND IS=(".wosQuote($searchParameters["ISBN"]).")";
            }
            if ($qualifyingField) { $searchStrings[2] = $searchString; }
        }
        // 3rd choice - title match and author surname *and* isbn/issn ) 
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TI=(".wosQuote($searchParameters["TITLE"]).")";
            $qualifyingField = FALSE;
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND AU=(".wosQuote($searchParameters["AUTH"]).")";
            }
            if (isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND IS=(".wosQuote($searchParameters["ISSN"]).")";
            } else if (isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND IS=(".wosQuote($searchParameters["ISBN"]).")";
            }
            if ($qualifyingField && !in_array($searchString, $searchStrings)) { $searchStrings[3] = $searchString; } // only add this one if we've made a difference
        }
        // 4th choice - title match and ( author surname *or* isbn/issn ) 
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TI=(".wosQuote($searchParameters["TITLE"]).")";
            $qualifyingAuField = FALSE;
            $qualifyingIsField = FALSE;
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"]) {
                $qualifyingAuField = TRUE;
                $searchString .= " AND ( AU=(".wosQuote($searchParameters["AUTH"]).")";  // potentially unbalanced ( but we won't do search unless we also have an IS below 
            }
            if (isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) {
                $qualifyingIsField = TRUE;
                $searchString .= " OR IS=(".wosQuote($searchParameters["ISSN"]).") )";
            } else if (isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) {     // potentially unbalanced ) but we won't do search unless we also have an AU above
                $qualifyingIsField = TRUE;
                $searchString .= " OR IS=(".wosQuote($searchParameters["ISBN"]).") )";      // potentially unbalanced ) but we won't do search unless we also have an AU above
            }
            if ($qualifyingAuField && $qualifyingIsField && !in_array($searchString, $searchStrings)) { $searchStrings[4] = $searchString; } // only add this one if we've made a difference
        }
        // 5th choice - title match and date )
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TI=(".wosQuote($searchParameters["TITLE"]).")";
            $qualifyingPyField = FALSE;
            if (isset($searchParameters["DATE"]) && $searchParameters["DATE"]) {
                $qualifyingPyField = TRUE;
                $searchString .= " AND PY=(".wosQuote($searchParameters["DATE"]).")";  
            }
            if ($qualifyingPyField && !in_array($searchString, $searchStrings)) { $searchStrings[5] = $searchString; } // only add this one if we've made a difference
        }
        
        
        foreach ($searchStrings as $searchPref=>$searchString) { 
            $wosSearchData = wosApiQuery($searchString, $citation["WoS"], "search", TRUE);
            if ($wosSearchData && isset($wosSearchData["Data"]) && isset($wosSearchData["QueryResult"]) && isset($wosSearchData["QueryResult"]["RecordsFound"]) && intval($wosSearchData["QueryResult"]["RecordsFound"])>0) { break; } // first successful result
            if (!isset($citation["WoS"]["searches-no-results"])) { $citation["WoS"]["searches-no-results"] = Array(); } 
            $citation["WoS"]["searches-no-results"][] = $searchString; // record the one we're trying
        }
        if (!$wosSearchData) { continue; } // move on to next citation 
            
        
        $citation["WoS"]["result-count"] = intval($wosSearchData["QueryResult"]["RecordsFound"]);
        
        if ($citation["WoS"]["result-count"]) {
            
            $citation["WoS"]["search-active"] = $searchString;
            $citation["WoS"]["search-pref"] = $searchPref;
            
            $citation["WoS"]["first-match"] = Array(); 
            $citation["WoS"]["results"] = Array(); 
            foreach ($wosSearchData["Data"]["Records"]["records"]["REC"] as &$entry) {
                
                $citationResult = Array(); 
                $citationResult["UID"] = $entry["UID"]; 
                
                // some nodes in the data may have a single value or an array of values - for ease of processing
                // we want them to always be arrays (even if the array contains only a single value)
                forceArray($entry["static_data"]["summary"]["titles"]["title"]);
                forceArray($entry["static_data"]["summary"]["names"]["name"]);  // and we'll keep it like this for the code below 
                
                foreach ($entry["static_data"]["summary"]["titles"]["title"] as $title) { 
                    if ($title["type"]=="item") { 
                        $citationResult["title"] = $title["content"]; 
                    }
                }
                $citationResult["year"] = $entry["static_data"]["summary"]["pub_info"]["pubyear"];
                $citationResult["doctype"] = $entry["static_data"]["summary"]["doctypes"]["doctype"];
                $citationResult["authors"] = Array();
                foreach ($entry["static_data"]["summary"]["names"]["name"] as $name) { 
                    if (isset($name["wos_standard"])) {
                        $citationResult["authors"][] = $name["wos_standard"];
                    } else if (isset($name["display_name"])) {
                        $citationResult["authors"][] = $name["display_name"];
                    } else if (isset($name["full_name"])) {
                        $citationResult["authors"][] = $name["full_name"];
                    } else if (isset($name["last_name"])) {
                        $citationResult["authors"][] = $name["last_name"];
                    } else {
                        $citationResult["authors"][] = "?";
                    }
                }
                $citation["WoS"]["results"][] = $citationResult; 
            }
            
            $entry = $wosSearchData["Data"]["Records"]["records"]["REC"][0]; // now only interested in first result 
            //$links = $entry["link"]; 
            //$linkAuthorAffiliation = FALSE; 
            
            //$citation["WoS"]["first-match"]["summary"] = array_filter(array_intersect_key($entry, $summaryFields)); // repeat the summary fields we already collected 
            $citation["WoS"]["first-match"]["metadata"] = Array(); 
            $citation["WoS"]["first-match"]["metadata"]["UID"] = $entry["UID"];
            
            foreach ($entry["static_data"]["summary"]["titles"]["title"] as $title) {
                if ($title["type"]=="item") {
                    $citation["WoS"]["first-match"]["metadata"]["title"] = $title["content"];
                }
                if ($title["type"]=="source") {
                    $citation["WoS"]["first-match"]["metadata"]["source"] = $title["content"];
                }
            }
            $citation["WoS"]["first-match"]["metadata"]["year"] = $entry["static_data"]["summary"]["pub_info"]["pubyear"];
            $citation["WoS"]["first-match"]["metadata"]["doctype"] = $entry["static_data"]["summary"]["doctypes"]["doctype"];
            
            if (isset($entry["static_data"]["summary"]["publishers"])) {
                $citation["WoS"]["first-match"]["metadata"]["publisher"] = $entry["static_data"]["summary"]["publishers"]["publisher"];
            }
            $citation["WoS"]["first-match"]["metadata"]["citations"] = $entry["dynamic_data"]["citation_related"]["tc_list"]["silo_tc"]["local_count"];
            
            $citation["WoS"]["first-match"]["metadata"]["authors"] = $entry["static_data"]["summary"]["names"]["name"]; // we've already dealt with singleton authors above and turned them into arrays of one 
            
            forceArray($entry["static_data"]["fullrecord_metadata"]["addresses"]["address_name"]);
            
            // step 1 - save the individual addresses by their addr_no 
            $addresses = Array(); 
            if (isset($entry["static_data"]["fullrecord_metadata"]["addresses"]["address_name"])) { 
                $citation["WoS"]["first-match"]["metadata"]["addresses"] = $entry["static_data"]["fullrecord_metadata"]["addresses"]["address_name"]; 
                foreach ($entry["static_data"]["fullrecord_metadata"]["addresses"]["address_name"] as $address) { 
                    $addresses[$address["address_spec"]["addr_no"]] = $address["address_spec"]; 
                }
            }
            // step 2 add these to author data already retrieved 
            foreach ($citation["WoS"]["first-match"]["metadata"]["authors"] as &$author) { 
                if (isset($author["addr_no"])) {
                    $author["addresses"] = Array(); 
                    $addrNos = preg_split('/\s+/', $author["addr_no"]); // authors may have multiple address numbers crammed into a single string
                    foreach ($addrNos as $addrNo) { 
                        $author["addresses"][] = $addresses[$addrNo];
                    }
                }
            }

            // reprint addresses 
            forceArray($entry["static_data"]["fullrecord_metadata"]["reprint_addresses"]["address_name"]);
            if (isset($entry["static_data"]["fullrecord_metadata"]["reprint_addresses"]["address_name"])) {
                $citation["WoS"]["first-match"]["metadata"]["reprint_addresses"] = $entry["static_data"]["fullrecord_metadata"]["reprint_addresses"]["address_name"];
            }
            
            
            
            $citation["WoS"]["first-match"]["metadata"]["identifiers"] = Array();
            if (is_array($entry["dynamic_data"]["cluster_related"]["identifiers"])) {
                forceArray($entry["dynamic_data"]["cluster_related"]["identifiers"]["identifier"]);
                foreach ($entry["dynamic_data"]["cluster_related"]["identifiers"]["identifier"] as $identifier) {
                    // might a record contain multiple identifiers of a give type? allow for the possibility
                    if (!isset($citation["WoS"]["first-match"]["metadata"]["identifiers"][$identifier["type"]])) {
                        $citation["WoS"]["first-match"]["metadata"]["identifiers"][$identifier["type"]] = Array();
                    }
                    $citation["WoS"]["first-match"]["metadata"]["identifiers"][$identifier["type"]][] = $identifier["value"];
                }
            }
            
            
            // find the similarity in title between our citation and WoS data - 
            // we will save this both at the citation-level and the individual author-level 
            // (originally we kept citation-level similarities but I think author-level similarities may be more useful) 
            $titleSimilarity = 0;
            $foundTitleSimilarity = FALSE;
            if (isset($citation["WoS"]["first-match"]["metadata"]["title"]) && $citation["WoS"]["first-match"]["metadata"]["title"])  {
                // first just use the title we searched for (the Leganto title)
                if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
                    $foundTitleSimilarity = TRUE;
                    $titleSimilarity = max($titleSimilarity, similarity($citation["WoS"]["first-match"]["metadata"]["title"], $searchParameters["TITLE"], "Levenshtein", FALSE));
                    $titleSimilarity = max($titleSimilarity, similarity($citation["WoS"]["first-match"]["metadata"]["title"], $searchParameters["TITLE"], "Levenshtein", "colon"));
                }
                // now try comparing with all the Alma titles
                if (isset($extraParameters["ALMA-TITLES"])) {
                    foreach ($extraParameters["ALMA-TITLES"] as $titleAlma) {
                        $foundTitleSimilarity = TRUE;
                        if (isset($titleAlma["collated"])) {
                            $titleSimilarity = max($titleSimilarity, similarity($citation["WoS"]["first-match"]["metadata"]["title"], $titleAlma["collated"], "Levenshtein", FALSE));
                            $titleSimilarity = max($titleSimilarity, similarity($citation["WoS"]["first-match"]["metadata"]["title"], $titleAlma["collated"], "Levenshtein", "colon"));
                        }
                        if (isset($titleAlma["a"])) {
                            $titleSimilarity = max($titleSimilarity, similarity($citation["WoS"]["first-match"]["metadata"]["title"], $titleAlma["a"], "Levenshtein", FALSE));
                            $titleSimilarity = max($titleSimilarity, similarity($citation["WoS"]["first-match"]["metadata"]["title"], $titleAlma["a"], "Levenshtein", "colon"));
                        }
                    }
                }
                if (isset($extraParameters["LEGANTO-TITLE"]) && $extraParameters["LEGANTO-TITLE"]) {
                    $foundTitleSimilarity = TRUE;
                    if (isset($titleAlma["collated"])) {
                        $titleSimilarity = max($titleSimilarity, similarity($citation["WoS"]["first-match"]["metadata"]["title"], $extraParameters["LEGANTO-TITLE"], "Levenshtein", FALSE));
                        $titleSimilarity = max($titleSimilarity, similarity($citation["WoS"]["first-match"]["metadata"]["title"], $extraParameters["LEGANTO-TITLE"], "Levenshtein", "colon"));
                    }
                }
            }

            $collatedAuthorsSurname = Array();
            $collatedAuthorsShort = Array();
            $collatedAuthorsLong = Array();
            $collatedAuthorsFull = Array();
            
            foreach ($citation["WoS"]["first-match"]["metadata"]["authors"] as &$author) { 
                
                // assemble string list of authors for later comparison with source metadata
                $collatedAuthorSurname = FALSE;
                $collatedAuthorShort = FALSE;
                $collatedAuthorLong = FALSE;
                $collatedAuthorFull = FALSE;
                if (isset($author["last_name"]) && $author["last_name"]) {
                    $collatedAuthorSurname = $author["last_name"];
                    $collatedAuthorsSurname[] = $collatedAuthorSurname;
                }
                if (isset($author["wos_standard"]) && $author["wos_standard"]) {
                    $collatedAuthorShort = $author["wos_standard"];
                    $collatedAuthorsShort[] = $collatedAuthorShort;
                }
                if (isset($author["display_name"]) && $author["display_name"]) {
                    $collatedAuthorLong = $author["display_name"];
                    $collatedAuthorsLong[] = $collatedAuthorLong;
                }
                if (isset($author["preferred_name"]) && isset($author["preferred_name"]["full_name"]) && $author["preferred_name"]["full_name"]) { 
                    $collatedAuthorFull = $author["preferred_name"]["full_name"];
                    $collatedAuthorsFull[] = $collatedAuthorFull;
                } else if (isset($author["full_name"]) && $author["full_name"]) {
                    $collatedAuthorFull = $author["full_name"];
                    $collatedAuthorsFull[] = $collatedAuthorFull;
                }
                
                    $thisSimilarity = 0;
                    $foundSimilarity = FALSE;
                    // try taking any Alma authors
                    if (isset($extraParameters["ALMA-CREATORS"])) {
                        foreach ($extraParameters["ALMA-CREATORS"] as $creatorAlma) {
                            if ($creatorAlma) {
                                if (isset($creatorAlma["collated"]) && $creatorAlma["collated"]) {
                                    if ($collatedAuthorShort) {
                                        $foundSimilarity = TRUE;
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorShort, $creatorAlma["collated"], "Levenshtein", FALSE));
                                    }
                                    if ($collatedAuthorLong) {
                                        $foundSimilarity = TRUE;
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorLong, $creatorAlma["collated"], "Levenshtein", FALSE));
                                    }
                                    if ($collatedAuthorLong) {
                                        $foundSimilarity = TRUE;
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorFull, $creatorAlma["collated"], "Levenshtein", FALSE));
                                    }
                                }
                                if (isset($creatorAlma["a"]) && $creatorAlma["a"]) {
                                    if ($collatedAuthorShort) {
                                        $foundSimilarity = TRUE;
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorShort, $creatorAlma["a"], "Levenshtein", FALSE));
                                    }
                                    if ($collatedAuthorLong) {
                                        $foundSimilarity = TRUE;
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorLong, $creatorAlma["a"], "Levenshtein", FALSE));
                                    }
                                    if ($collatedAuthorLong) {
                                        $foundSimilarity = TRUE;
                                        // try both a straight comparison and a comparison cropping to initials and sorting words alphabetically
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorFull, $creatorAlma["a"], "Levenshtein", FALSE));
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorFull, $creatorAlma["a"], "Levenshtein", "initials", TRUE));
                                    }
                                }
                            }
                        }
                    }
                    // now try splitting the Leganto author field and comparing with any individual WoS author
                    if (isset($extraParameters["LEGANTO-AUTHOR"]) && $extraParameters["LEGANTO-AUTHOR"]) {
                        $creatorsLeganto = preg_split('/(\s*;\s*|\s+and\s+|\s+&\s+)/', $extraParameters["LEGANTO-AUTHOR"]); // separate multiple authors
                        if ($creatorsLeganto) {
                            foreach ($creatorsLeganto as $creatorLeganto) {
                                if ($creatorLeganto) {
                                    if ($collatedAuthorShort) {
                                        $foundSimilarity = TRUE;
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorShort, $creatorLeganto, "Levenshtein", FALSE));
                                    }
                                    if ($collatedAuthorLong) {
                                        $foundSimilarity = TRUE;
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorLong, $creatorLeganto, "Levenshtein", FALSE));
                                    }
                                    if ($collatedAuthorLong) {
                                        $foundSimilarity = TRUE;
                                        $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorFull, $creatorLeganto, "Levenshtein", FALSE));
                                    }
                                }
                            }
                        }
                    }
                    if ($foundSimilarity) { $author["similarity-author"] = $thisSimilarity; }
                    if ($foundTitleSimilarity) { $author["similarity-title"] = $titleSimilarity; }
                    
                    
                    
                    
                    
                
            }
            
            
        }
        
        
        
    }
    }
}



print json_encode($citations, JSON_PRETTY_PRINT);



/**
 * Removes unwanted characters e.g. ( ) " 
 * and then surrounds entire string in "" 
 * ready for inclusion in WoS query string 
 * 
 * @param String $parameter
 * @param Boolean $keepParentheses Whether to retain ( ) in string 
 */
function wosQuote($parameter, $keepParentheses=FALSE) { 
    $toReplace = Array('"'); 
    if (!$keepParentheses) { 
        $toReplace[] = "("; 
        $toReplace[] = ")";
    }
    $parameter = str_replace($toReplace, '', $parameter);
    $parameter = '"'.$parameter.'"';
    return $parameter; 
}




/** 
 * Fetches data from WoS API 
 * 
 * Checks the local cache first before making a call to the API 
 * 
 * @param String  $URL              API URL without httpAccept, apiKey and reqId 
 * @param Array   $citationWos      The value of the WoS entry in the citation - modified by this function as a side effect
 * @param String  $type             Used e.g. to identify the source of any errors  
 * @param Boolean $checkRateLimit   Whether to check and log the rate-limit data in the response   
 * @param String  $require          Key which we require to have in the returned array, otehrwise log error and return FALSE 
 */
function wosApiQuery($usrQuery, &$citationWos, $type="default", $checkRateLimit=FALSE, $require=NULL) { 
     
    global $wosCache, $config; 
    
    $URL = "https://wos-api.clarivate.com/api/wos/?databaseId=WOK&count=10&firstRecord=1&usrQuery=".urlencode($usrQuery); 
    
    // Cached result?
    if (isset($wosCache[$URL])) { 
        return $wosCache[$URL]; 
    }
    // else 
    
    usleep(550000); // because of API limits (2 requests per second) 
    
    $c = curl_init();
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_HEADER, 1);
    curl_setopt($c, CURLOPT_URL, $URL);
    curl_setopt($c, CURLOPT_HTTPHEADER, array(
        "accept: application/json",
        "X-ApiKey: ".$config["WoS"]["apiKey"]
    )); 
        
    $response = curl_exec($c);
    
    $header_size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    $http_response_header = preg_split('/[\r\n]+/', substr($response, 0, $header_size));
    $body = substr($response, $header_size);
    
    curl_close($c);
    
    if ($checkRateLimit) {
        if (!isset($citationWos["rate-limit"])) {
            $citationWos["rate-limit"] = Array();
        }
        if (!isset($citationWos["rate-limit"][$type])) {
            $citationWos["rate-limit"][$type] = Array();
        }
        foreach ($http_response_header as $header) {
            if (preg_match('/^(.*-remaining):\s*\'?(.*)\'?/', $header, $matches)) {
                $citationWos["rate-limit"][$type][$matches[1]] = $matches[2]; // limit, remaining, reset
            }
        }
    }
    
    
    if ($body) { 
        $wosData = json_decode($body, TRUE);
        $wosCache[$URL] = $wosData;
        
        if ($wosData) {
            
            if (isset($wosData["Data"])) {
                // OK 
            } else { 
                if (!isset($citationWos["errors"])) {
                    $citationWos["errors"] = Array();
                }
                if (!isset($citationWos["errors"][$type])) {
                    $citationWos["errors"][$type] = Array();
                }
                $citationWos["errors"][$type][] = Array("message"=>"No Data element in response");
                
                if (isset($citationWos["code"])) {
                    $citationWos["errors"][$type][] = $wosData; // returned data *is* the error object 
                }
                
            }
            
        } else {
            if (!isset($citationWos["errors"])) {
                $citationWos["errors"] = Array();
            }
            if (!isset($citationWos["errors"][$type])) {
                $citationWos["errors"][$type] = Array();
            }
            $citationWos["errors"][$type][] = Array("message"=>"Response can't be decoded from JSON"); 
        }
        
        return $wosData;
        
        
    } else { 
        
        if (!isset($citationWos["errors"])) {
            $citationWos["errors"] = Array();
        }
        if (!isset($citationWos["errors"][$type])) {
            $citationWos["errors"][$type] = Array();
        }
        $citationWos["errors"][$type][] = Array("message"=>"Empty response"); 
        
        $wosCache[$URL] = FALSE; //TODO need a way to feed error messages back as well to subsequent calls for this URL 
        return FALSE;
        
    }
    
}


/**
 * Some data in the WoS response may be either a single object (if only one of them) 
 * or an array of objects (if more than one) 
 * 
 * To make code manipulaing the response simpler, have one function to turrn any single objects 
 * into arrays of a single object
 * 
 * This function does not return a value, but modifies its parameter  
 * 
 * @param data $node
 */
function forceArray(&$node) { 
    if (is_array($node) && count(array_filter(array_keys($node), 'is_string')) > 0) { 
        // $node has string keys therefore is an object that needs wrapping in an array 
        $node = Array($node);
    }
}



?>