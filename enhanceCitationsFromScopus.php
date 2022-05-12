<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to enhance reading list citations using data from the Scopus API 
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
 * php enhanceCitationsFromScopus.php <Data/1A.json >Data/1AS.json 
 * 
 * The input citation data is assumed to already contain data from Leganto and Alma 
 * 
 * See getCitationsByModule.php and enhanceCitationsFromAlma.php for how this data is prepared  
 * 
 * =======================================================================
 * 
 * General process: 
 * 
 * Loop over citations - for each citation: 
 * 
 *  - Collect the metadata that might potentially be useful in a Scopus search
 *  - Prepare a set of progressively-looser search strings from this metadata 
 *  - Try these searches in turn, until a search returns at least one result   
 *  - Take the *first* record in the result set - 
 *    TODO: may need an intelligent way of picking the best record where multiple records are returned 
 *  - Fetch the abstract for this record (some relevant data is in here) 
 *  - For each author in the abstract - 
 *    - Fetch the contemporary affiliation of the author 
 *    - Fetch the author profile which includes current affiliation   
 *  - Calculate string similarities between source and Scopus authors and titles 
 *  - Save all the data (including any Scopus rate-limit data) in the citation object 
 *  
 * Export the enhanced citations 
 * 
 * =======================================================================
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * You need a developer key for the Scopus API - see https://dev.elsevier.com/ 
 * 
 * Save your key in config.ini 
 * 
 * The API is only fully-accessible from a machine on a subscribing University's network e.g. 129.11.0.0 - it will not generate the required output if run on a remote machine
 * 
 * The API is rate-limited - each category of call has a weekly quota - 
 * The remaining uses are logged by the code below in the citation data in CITATION["Scopus"]["rate-limit"]
 * NB the code below caches the results of an API call to help limit usage 
 * 
 * The code below includes a small delay (usleep(100000)) between API calls to avoid overloading the service
 * 
 * API calls use the function utils.php:curl_get_file_contents() rather than the more natural file_get_contents  
 * Because the http wrappers for the latter are not enabled on lib5hv, where development has been carried out 
 * 
 * Double-quote characters should be encoded within phrase searches {like \"this\"} or "like \"this\"" 
 * But even with this, I am still seeing errors from some searches including double-quotes 
 * For now I am removing double-quotes altogether from titles - looks like things are still found, 
 * but possibly via a looser search than would be ideal 
 * TODO: Figure out how to create searches including doublt-quotes correctly   
 * 
 * TODO: I think some other special characters and strings (* ? ( ) { } " AND OR ) may need special handling if they occur in other fields e.g. DOI  
 * I am not currently checking for these but it may need looking at 
 * 
 * Elsevier uses Cloudfare to protect its servers and during testing, some complex search strings triggered a block from Cloudfare  
 * This is likely related to the previous two points 
 * 
 * 
 */



error_reporting(E_ALL);                     // we want to know about all problems

//TODO implement a batch-wide cache to reduce unnecessary API calls 
$scopusCache = Array();                     // because of rate limit, don't fetch unless we have to 

require_once("utils.php");                  // contains helper functions  


// fetch the data from STDIN  
$citations = json_decode(file_get_contents("php://stdin"), TRUE);
if ($citations===NULL) {
    trigger_error("Error: No valid data passed to enhacement script: Something may have gone wrong in a previous step?", E_USER_ERROR);
}

// main loop: process each citation 
foreach ($citations as &$citation) { 
    
    if (isset($citation["Leganto"])) { // only do any enhancement for entries in the citations file that have an actual list
    
    $searchParameters = Array();    // collect things in here for the Scopus search
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
            $searchParameters["DOCTYPE"] = Array("ar", "re"); // this parameter may have multiple possible values (to join with "or") 
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
            $searchParameters["DOCTYPE"] = Array("bk");
    } else if (isset($citation["Leganto"]["secondary_type"]["value"]) && in_array($citation["Leganto"]["secondary_type"]["value"], Array("WS", "CONFERENCE", "OTHER"))
        && isset($citation["Leganto"]["metadata"]["title"]) && $citation["Leganto"]["metadata"]["title"]
        && (
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
            
    }
    
    // now also collect some a-t data from Alma, that we can use to calculate source-Scopus similarity
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
        
        // need to escape quote characters in TITLE - other fields don't matter for now 
        // because their search terms are not wrapped in {} or "" 
        //TODO need to properly deal with special characters (",*,?) in *any* field?
        if (isset($searchParameters["TITLE"])) { 
            // for now removing double-quotes altogether because of problems
            $searchParameters["TITLE"] = str_replace('"', '', $searchParameters["TITLE"]);
            // 20220414 found one case where a trailing single quote causes problems - 
            // remove leading and trailing single quotes but (for now) leave in place elsewhere 
            $searchParameters["TITLE"] = preg_replace('/^\s*\'/', '', $searchParameters["TITLE"]);
            $searchParameters["TITLE"] = preg_replace('/\'\s*$/', '', $searchParameters["TITLE"]);
        }
       
        $citation["Scopus"] = Array(); // to populate
        
        $searchStrings = Array(); // assemble search parameters into one or more search strings, in order of preference
        
        // 1st choice - DOI 
        if (isset($searchParameters["DOI"]) && $searchParameters["DOI"]) {
            $searchStrings[1] = "DOI(".$searchParameters["DOI"].")";
        }
        // 2nd choice - exact title match and author surname *and* doctype *and* isbn/issn 
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TITLE({".$searchParameters["TITLE"]."})"; // exact match
            if (isset($searchParameters["DOCTYPE"]) && count($searchParameters["DOCTYPE"])) { 
                // $searchString .= " AND DOCTYPE(".$searchParameters["DOCTYPE"].")";
                $searchString .= " AND (".implode(" OR ", array_map(function($doctype) { return "DOCTYPE(".$doctype.")"; }, $searchParameters["DOCTYPE"])).")";
            }
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"]) { $searchString .= " AND AUTH(".$searchParameters["AUTH"].")"; }
            if (isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) { $searchString .= " AND ISBN(".$searchParameters["ISBN"].")"; }
            if (isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) { $searchString .= " AND ISSN(".$searchParameters["ISSN"].")"; }
            $searchStrings[2] = $searchString;
        }
        // 3rd choice - exact title match and doctype and ( author surname *or* isbn/issn ) 
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TITLE({".$searchParameters["TITLE"]."})"; // exact match
            if (isset($searchParameters["DOCTYPE"]) && count($searchParameters["DOCTYPE"])) {
                // $searchString .= " AND DOCTYPE(".$searchParameters["DOCTYPE"].")";
                $searchString .= " AND (".implode(" OR ", array_map(function($doctype) { return "DOCTYPE(".$doctype.")"; }, $searchParameters["DOCTYPE"])).")";
            }
            $qualifyingField = FALSE;
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"] && isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND (AUTH(".$searchParameters["AUTH"].") OR ISSN(".$searchParameters["ISSN"]."))";
            } else if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"] && isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND (AUTH(".$searchParameters["AUTH"].") OR ISBN(".$searchParameters["ISBN"]."))";
            }
            if ($qualifyingField && !in_array($searchString, $searchStrings)) { $searchStrings[3] = $searchString; } // only add this one if we've made a difference
        }
        // 4th choice - exact title match and ( author surname *or* isbn/issn ) 
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TITLE({".$searchParameters["TITLE"]."})"; // exact match
            $qualifyingField = FALSE; 
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"] && isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND (AUTH(".$searchParameters["AUTH"].") OR ISSN(".$searchParameters["ISSN"]."))";
            } else if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"] && isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND (AUTH(".$searchParameters["AUTH"].") OR ISBN(".$searchParameters["ISBN"]."))";
            }
            if ($qualifyingField && !in_array($searchString, $searchStrings)) { $searchStrings[4] = $searchString; } // only add this one if we've made a difference
        }
        // 5th choice - title search terms adjacent and author surname *and* doctype *and* isbn/issn
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"] && isset($searchParameters["DOCTYPE"]) && count($searchParameters["DOCTYPE"])) {
            $searchString = "TITLE(\"".$searchParameters["TITLE"]."\")";
            $searchString .= " AND (".implode(" OR ", array_map(function($doctype) { return "DOCTYPE(".$doctype.")"; }, $searchParameters["DOCTYPE"])).")";
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"]) { $searchString .= " AND AUTH(".$searchParameters["AUTH"].")"; }
            if (isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) { $searchString .= " AND ISBN(".$searchParameters["ISBN"].")"; }
            if (isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) { $searchString .= " AND ISSN(".$searchParameters["ISSN"].")"; }
            if (!in_array($searchString, $searchStrings)) { $searchStrings[5] = $searchString; } // only add this one if we've made a difference
        }
        // 6th choice - title search terms adjacent and author surname *and* isbn/issn
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TITLE(\"".$searchParameters["TITLE"]."\")";
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"]) { $searchString .= " AND AUTH(".$searchParameters["AUTH"].")"; }
            if (isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) { $searchString .= " AND ISBN(".$searchParameters["ISBN"].")"; }
            if (isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) { $searchString .= " AND ISSN(".$searchParameters["ISSN"].")"; }
            if (!in_array($searchString, $searchStrings)) { $searchStrings[6] = $searchString; } // only add this one if we've made a difference
        }
        // 7th choice - title search terms adjacent and author surname *or* isbn/issn
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TITLE(\"".$searchParameters["TITLE"]."\")";
            $qualifyingField = FALSE;
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"] && isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND (AUTH(".$searchParameters["AUTH"].") OR ISSN(".$searchParameters["ISSN"]."))";
            } else if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"] && isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) {
                $qualifyingField = TRUE;
                $searchString .= " AND (AUTH(".$searchParameters["AUTH"].") OR ISBN(".$searchParameters["ISBN"]."))";
            }
            if ($qualifyingField && !in_array($searchString, $searchStrings)) { $searchStrings[7] = $searchString; } // only add this one if we've made a difference
        }
        
        
        foreach ($searchStrings as $searchPref=>$searchString) { 
            $scopusSearchData = scopusApiQuery("https://api.elsevier.com/content/search/scopus?query=".urlencode($searchString), $citation["Scopus"], "scopus-search", TRUE);
            if ($scopusSearchData && isset($scopusSearchData["search-results"]) && isset($scopusSearchData["search-results"]["opensearch:totalResults"]) && intval($scopusSearchData["search-results"]["opensearch:totalResults"])>0) { break; } // first successful result
            if (!isset($citation["Scopus"]["searches-no-results"])) { $citation["Scopus"]["searches-no-results"] = Array(); } 
            $citation["Scopus"]["searches-no-results"][] = $searchString; // record the one we're trying
        }
        if (!$scopusSearchData) { continue; } // move on to next citation 
            
        $citation["Scopus"]["result-count"] = intval($scopusSearchData["search-results"]["opensearch:totalResults"]);
        
        if ($citation["Scopus"]["result-count"]) {
            
            $citation["Scopus"]["search-active"] = $searchString;
            $citation["Scopus"]["search-pref"] = $searchPref;
            
            $citation["Scopus"]["first-match"] = Array(); 
            $citation["Scopus"]["results"] = Array(); 
            $summaryFields = Array("eid"=>TRUE, "dc:title"=>TRUE, "dc:creator"=>TRUE, "prism:doi"=>TRUE, "prism:publicationName"=>TRUE, "subtype"=>TRUE);
            foreach ($scopusSearchData["search-results"]["entry"] as $entry) {
                $citation["Scopus"]["results"][] = array_filter(array_intersect_key($entry, $summaryFields));
            }
            
            $entry = $scopusSearchData["search-results"]["entry"][0]; // now only interested in first result 
            $links = $entry["link"]; 
            $linkAuthorAffiliation = FALSE; 
            
            $citation["Scopus"]["first-match"]["summary"] = array_filter(array_intersect_key($entry, $summaryFields)); // repeat the summary fields we already collected 
            
            foreach ($links as $link) { 
                if ($link["@ref"]=="self") {
                    $citation["Scopus"]["first-match"]["self"] = $link["@href"]; 
                }
                if ($link["@ref"]=="author-affiliation") {
                    $linkAuthorAffiliation = $link["@href"];
                }
            }
            
            // find the similarity in title between our citation and Scopus data - 
            // we will save this both at the citation-level and the individual author-level 
            // (originally we kept citation-level similarities but I think author-level similarities may be more useful) 
            $titleSimilarity = 0;
            $foundTitleSimilarity = FALSE;
            if (isset($entry["dc:title"]) && $entry["dc:title"])  {
                // first just use the title we searched for (the Leganto title)
                if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
                    $foundTitleSimilarity = TRUE;
                    $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $searchParameters["TITLE"], "Levenshtein", FALSE));
                    $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $searchParameters["TITLE"], "Levenshtein", "colon"));
                    $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $searchParameters["TITLE"], "Levenshtein", "post-colon"));
                }
                // now try comparing with all the Alma titles
                if (isset($extraParameters["ALMA-TITLES"])) {
                    foreach ($extraParameters["ALMA-TITLES"] as $titleAlma) {
                        $foundTitleSimilarity = TRUE;
                        if (isset($titleAlma["collated"])) {
                            $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $titleAlma["collated"], "Levenshtein", FALSE));
                            $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $titleAlma["collated"], "Levenshtein", "colon"));
                            $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $titleAlma["collated"], "Levenshtein", "post-colon"));
                        }
                        if (isset($titleAlma["a"])) {
                            $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $titleAlma["a"], "Levenshtein", FALSE));
                            $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $titleAlma["a"], "Levenshtein", "colon"));
                            $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $titleAlma["a"], "Levenshtein", "post-colon"));
                        }
                    }
                }
                if (isset($extraParameters["LEGANTO-TITLE"]) && $extraParameters["LEGANTO-TITLE"]) {
                    $foundTitleSimilarity = TRUE;
                    if (isset($titleAlma["collated"])) {
                        $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $extraParameters["LEGANTO-TITLE"], "Levenshtein", FALSE));
                        $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $extraParameters["LEGANTO-TITLE"], "Levenshtein", "colon"));
                        $titleSimilarity = max($titleSimilarity, similarity($entry["dc:title"], $extraParameters["LEGANTO-TITLE"], "Levenshtein", "post-colon"));
                    }
                }
            }

            
            $collatedAuthorsSurname = Array();
            $collatedAuthorsShort = Array(); 
            $collatedAuthorsLong = Array();
            
            if ($linkAuthorAffiliation) { 
                
                $scopusAuthorAffiliationData = scopusApiQuery($linkAuthorAffiliation, $citation["Scopus"], "abstract-retrieval", TRUE);
                if (!$scopusAuthorAffiliationData) { continue; }
                if (!isset($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"])) { 
                    trigger_error("Error: Authors not present in Abstract from Scopus API: You may be running this script from a machine outside your organisation's network?", E_USER_ERROR);
                }
                
                
                $citation["Scopus"]["first-match"]["authors"] = Array(); 
                if (isset($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"]["author"])) {
                    foreach ($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"]["author"] as $author) {
                        $citationScopusAuthor = array_filter(array_intersect_key($author, Array("@auid"=>TRUE, "author-url"=>TRUE, "preferred-name"=>TRUE, "ce:indexed-name"=>TRUE, "ce:surname"=>TRUE, "ce:initials"=>TRUE, "ce:given-name"=>TRUE, "affiliation"=>TRUE)));

                        // (contemporary) affiliation from the abstract information 
                        if (isset($citationScopusAuthor["affiliation"]) && is_array($citationScopusAuthor["affiliation"])) {
                            // affiliation may be single or a list - for simplicity, *always* turn it into a list
                            // - if associative array, wrap in a numeric array
                            if (count(array_filter(array_keys($citationScopusAuthor["affiliation"]), 'is_string'))>0) { $citationScopusAuthor["affiliation"]=Array($citationScopusAuthor["affiliation"]); }
                            foreach ($citationScopusAuthor["affiliation"] as &$citationScopusAuthorAffiliation) {
                                if (isset($citationScopusAuthorAffiliation["@id"]) && isset($citationScopusAuthorAffiliation["@href"])) {
                                    // fetch affiliation details
                                    $affiliationData = scopusApiQuery($citationScopusAuthorAffiliation["@href"]."?", $citation["Scopus"], "affiliation-retrieval", TRUE, "affiliation-retrieval-response");
                                    if (!$affiliationData) { continue; }
                                    
                                    $citationScopusAuthorAffiliationExtra = array_filter(array_intersect_key($affiliationData["affiliation-retrieval-response"], Array("affiliation-name"=>TRUE, "address"=>TRUE, "city"=>TRUE, "country"=>TRUE)));
                                    $citationScopusAuthorAffiliation = array_merge($citationScopusAuthorAffiliation, $citationScopusAuthorAffiliationExtra);
                                }
                            }
                        }
                        // author current (profile) affiliation 
                        // just get data from the author retrieve - 
                        // don't bother following up with an affiliation retrieve, 
                        // even though that would put data in same format as contemporary affiliation, 
                        // because extra affiliation retrieves may cause us problems with rate-limit    
                        if (isset($citationScopusAuthor["author-url"]) && $citationScopusAuthor["author-url"]) {
                            $authorData = scopusApiQuery($citationScopusAuthor["author-url"]."?", $citation["Scopus"], "author-retrieval", TRUE, "author-retrieval-response");
                            if ($authorData) { 
                                $citationScopusAuthor["affiliation-current"] = Array();
                                foreach($authorData["author-retrieval-response"] as $authorEntry) {
                                    if (isset($authorEntry["author-profile"])
                                        && isset($authorEntry["author-profile"]["affiliation-current"])
                                        && isset($authorEntry["author-profile"]["affiliation-current"]["affiliation"])
                                        ) {
                                            $authorProfileAffiliations = $authorEntry["author-profile"]["affiliation-current"]["affiliation"]; // for convenience
                                            // affiliation may be single or a list(?) - for simplicity, *always* turn it into a list
                                            // - if associative array, wrap in a numeric array
                                            if (count(array_filter(array_keys($authorProfileAffiliations), 'is_string'))>0) { $authorProfileAffiliations=Array($authorProfileAffiliations); }
                                            foreach ($authorProfileAffiliations as $authorProfileAffiliation) {
                                                $citationScopusAuthorAffiliationProfile = array_filter(array_intersect_key($authorProfileAffiliation, Array("@affiliation-id"=>TRUE)));
                                                $citationScopusAuthorAffiliationProfile = array_merge($citationScopusAuthorAffiliationProfile, array_filter(array_intersect_key($authorProfileAffiliation["ip-doc"], Array("sort-name"=>TRUE, "address"=>TRUE))));
                                                $citationScopusAuthor["affiliation-current"][] = $citationScopusAuthorAffiliationProfile;
                                            }
                                        }
                                }
                            }
                        }
                        
                        // author-level similarities 
                        // title just duplicates the citation-level similarity 
                        if ($foundTitleSimilarity) { $citationScopusAuthor["similarity-title"] = $titleSimilarity; } 
                        
                        // now do author-level author similarity 
                        
                        
                        // assemble string list of authors for later comparison with source metadata
                        $collatedAuthorSurname = FALSE;
                        $collatedAuthorShort = FALSE;
                        $collatedAuthorLong = FALSE; 
                        if (isset($author["ce:surname"]) && $author["ce:surname"]) {
                            $collatedAuthorSurname = $author["ce:surname"];
                            $collatedAuthorsSurname[] = $collatedAuthorSurname;
                        }
                        if (isset($author["ce:indexed-name"]) && $author["ce:indexed-name"]) {
                            $collatedAuthorShort = $author["ce:indexed-name"];
                            $collatedAuthorsShort[] = $collatedAuthorShort;
                        }
                        if (isset($author["ce:surname"]) && $author["ce:surname"]) {
                            $collatedAuthorLong = $author["ce:surname"]." ";
                            if (isset($author["ce:given-name"]) && $author["ce:given-name"]) {
                                $collatedAuthorLong .= ", ".$author["ce:given-name"];
                            } else if (isset($author["ce:initials"]) && $author["ce:initials"]) {
                                $collatedAuthorLong .= ", ".$author["ce:initials"];
                            }
                            $collatedAuthorsLong[] = $collatedAuthorLong;
                        }
                        
                        
                        $thisSimilarity = 0;
                        $foundSimilarity = FALSE;
                        // try taking any Alma authors 
                        if (isset($extraParameters["ALMA-CREATORS"])) {
                            foreach ($extraParameters["ALMA-CREATORS"] as $creatorAlma) {
                                if ($creatorAlma) {
                                    if (isset($creatorAlma["collated"]) && $creatorAlma["collated"]) {
                                        if ($collatedAuthorLong) {
                                            $foundSimilarity = TRUE;
                                            $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorLong, $creatorAlma["collated"], "Levenshtein", FALSE)); 
                                            // for author-long we'll do a straigh comparison 
                                            // for author-short below we'll convert longer name to initials if the shorter name is just initials 
                                            // and we'll rearrange words in name alphabetically 
                                        } 
                                        if ($collatedAuthorShort) {
                                            $foundSimilarity = TRUE;
                                            $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorShort, $creatorAlma["collated"], "Levenshtein", "initials", TRUE));
                                        }
                                        // actually don't match on just surname - we do need some corroboration 
                                        /*
                                        if ($collatedAuthorSurname) {
                                            $foundSimilarity = TRUE;
                                            $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorSurname, $creatorAlma["collated"], "Levenshtein", FALSE));
                                        }
                                        */
                                    } 
                                    if (isset($creatorAlma["a"]) && $creatorAlma["a"]) {
                                        if ($collatedAuthorLong) {
                                            $foundSimilarity = TRUE;
                                            $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorLong, $creatorAlma["a"], "Levenshtein", FALSE));
                                        } 
                                        if ($collatedAuthorShort) {
                                            $foundSimilarity = TRUE;
                                            $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorShort, $creatorAlma["a"], "Levenshtein", "initials", TRUE));
                                        }
                                        // actually don't match on just surname - we do need some corroboration
                                        /*
                                         if ($collatedAuthorSurname) {
                                            $foundSimilarity = TRUE;
                                            $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorSurname, $creatorAlma["a"], "Levenshtein", FALSE));
                                        }
                                        */
                                    }
                                }
                            }
                        }
                        // now try splitting the Leganto author field and comparing with any individual Scopus author
                        if (isset($extraParameters["LEGANTO-AUTHOR"]) && $extraParameters["LEGANTO-AUTHOR"]) { 
                            $creatorsLeganto = preg_split('/(\s*;\s*|\s+and\s+|\s+&\s+)/', $extraParameters["LEGANTO-AUTHOR"]); // separate multiple authors
                            if ($creatorsLeganto) {
                                foreach ($creatorsLeganto as $creatorLeganto) {
                                    if ($creatorLeganto) {
                                        if ($collatedAuthorLong) {
                                            $foundSimilarity = TRUE;
                                            $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorLong, $creatorLeganto, "Levenshtein", FALSE));
                                        } 
                                        if ($collatedAuthorShort) {
                                            $foundSimilarity = TRUE;
                                            $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorShort, $creatorLeganto, "Levenshtein", "initials", TRUE));
                                        }
                                        // actually don't match on just surname - we do need some corroboration
                                        /*
                                         if ($collatedAuthorSurname) {
                                            $foundSimilarity = TRUE;
                                            $thisSimilarity = max($thisSimilarity, similarity($collatedAuthorSurname, $creatorLeganto, "Levenshtein", FALSE));
                                        }
                                        */
                                    }
                                }
                            }
                        }
                        if ($foundSimilarity) { $citationScopusAuthor["similarity-author"] = $thisSimilarity; }
                        
                        $citationScopusAuthor["search-parameters"] = $searchParameters; 
                        $citationScopusAuthor["extra-parameters"] = $extraParameters;
                        
                        
                        $citation["Scopus"]["first-match"]["authors"][] = $citationScopusAuthor;
                    }
                }
            }
            
        }
        

    }
    }
}



print json_encode($citations, JSON_PRETTY_PRINT);


/** 
 * Fetches data from Scopus API 
 * 
 * Checks the local cache first before making a call to the API 
 * 
 * @param String  $URL              API URL without httpAccept, apiKey and reqId 
 * @param Array   $citationScopus   The value of the Scopus entry in the citation - modified by this function as a side effect
 * @param String  $type             To distinguish the different individual APIs   
 * @param Boolean $checkRateLimit   Whether to check and log the rate-limit data in the response 
 * @param String  $require          Key which we require to have in the returned array, otherwise log error and return FALSE 
 */
function scopusApiQuery($URL, &$citationScopus, $type="default", $checkRateLimit=FALSE, $require=NULL) { 
     
    global $scopusCache, $http_response_header; // latter needed to allow curl_get_file_contents to mimic file_get_contents side-effect
    
    $URL = preg_replace('/^http:\/\//', "https://", $URL); // a few references in the API use http

    // Cached result?
    if (isset($scopusCache[$URL])) { 
        return $scopusCache[$URL]; 
    }
    // else 
    
    $apiKey = CONFIG["Scopus"]["apiKey"]; 
    $httpAccept = "application/json"; 
    $reqId = microtime(TRUE);
    
    $apiURL = $URL."&httpAccept=".urlencode($httpAccept)."&reqId=".urlencode($reqId)."&apiKey=".urlencode($apiKey); 
   
    usleep(500000); // because of per-second throttling rates on Scopus API 
                    // see https://dev.elsevier.com/api_key_settings.html
                    //TODO could improve performance by using a different delay for different types of request 
                    // e.g. we can only make three author retrievals per second but we can make 9 Scopus searches per second  
    
    $scopusResponse = curl_get_file_contents($apiURL);
    
    if ($checkRateLimit) { 
        if (!isset($citationScopus["rate-limit"])) {
            $citationScopus["rate-limit"] = Array();
        }
        if (!isset($citationScopus["rate-limit"][$type])) {
            $citationScopus["rate-limit"][$type] = Array();
        }
        foreach ($http_response_header as $header) {
            if (preg_match('/^X-RateLimit-(\w+):\s*(\d+)/i', $header, $matches)) {
                $citationScopus["rate-limit"][$type][$matches[1]] = $matches[2]; // limit, remaining, reset
            }
        }
    }
    
    if (!$scopusResponse) {
        trigger_error("Error: No response from Scopus API for [".$URL."]", E_USER_ERROR);
    }
    
    $scopusData = json_decode($scopusResponse,TRUE);
    
    if ($scopusData===null) {
        trigger_error("Error: Response from Scopus API annot be decoded as JSON: $scopusResponse [".$URL."]", E_USER_ERROR);
    }
    if (isset($scopusData["service-error"])) {
        $serviceError = $scopusData["service-error"];
        $errorMessage = (isset($serviceError["status"]) && isset($serviceError["status"]["statusCode"])) ? $serviceError["status"]["statusCode"] : "Unknown status code";
        $errorMessage .= " (";
        $errorMessage .= (isset($serviceError["status"]) && isset($serviceError["status"]["statusText"])) ? $serviceError["status"]["statusText"] : "Unknown status text";
        $errorMessage .= ")";
        trigger_error("Error: Service error from Scopus API: $errorMessage [".$URL."]", E_USER_ERROR);
    }
    if (isset($scopusData["error-response"])) {
        $serviceError = $scopusData["error-response"];
        $errorMessage = (isset($serviceError["error-code"])) ? $serviceError["error-code"] : "Unknown error code";
        $errorMessage .= " (";
        $errorMessage .= (isset($serviceError["error-message"])) ? $serviceError["error-message"] : "Unknown error message";
        $errorMessage .= ")";
        trigger_error("Error: Error response from Scopus API: $errorMessage [".$URL."]", E_USER_ERROR);
    }
    if ($require!==NULL) { 
        if (!isset($scopusData[$require])) { 
            trigger_error("Error: Expected data field (".$require.") not present in response from Scopus API [".$URL."]", E_USER_ERROR);
        }
    }
    
    // Cache 
    $scopusCache[$URL] = $scopusData;
    
    return $scopusData; 
    
}





?>