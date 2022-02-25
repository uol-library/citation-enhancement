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
 * php enhanceCitationsFromScopus.php <Data/2.json >Data/3.json 
 * 
 * The input citation data is assumed to already contain data from Leganto and Alma 
 * 
 * See getCitationsByCourseAndList.php and enhanceCitationsFromAlma.php for how this data is prepared  
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
 *  - Save all the data (including any Scopus rate-limit data and errors) in the citation object 
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
 * Double-quote characters should to be encoded within phrase searches {like \"this\"} or "like \"this\"" 
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

$scopusCache = Array();                     // because of rate limit, don't fetch unless we have to 

require_once("utils.php");                  // contains helper functions  


// fetch the data from STDIN  
$citations = json_decode(file_get_contents("php://stdin"), TRUE);


// main loop: process each citation 
foreach ($citations as &$citation) { 
    
    $searchParameters = Array();    // collect things in here for the Scopus search
    $extraParameters = Array();     // not using in search query but may use e.g. to calculate result to source similarity 
    
    if (isset($citation["Leganto"]["metadata"]["doi"]) && $citation["Leganto"]["metadata"]["doi"]) {
        $doi = $citation["Leganto"]["metadata"]["doi"];
        $doi = preg_replace('/^https?:\/\/doi\.org\//', '', $doi);
        $searchParameters["DOI"] = $doi; 
    }
    if (isset($citation["Leganto"]["secondary_type"]["value"]) && in_array($citation["Leganto"]["secondary_type"]["value"], Array("CR", "E_CR"))
        && isset($citation["Leganto"]["metadata"]["article_title"]) && $citation["Leganto"]["metadata"]["article_title"]) {
            $searchParameters["TITLE"] = $citation["Leganto"]["metadata"]["article_title"];
            if (isset($citation["Leganto"]["metadata"]["issn"]) && $citation["Leganto"]["metadata"]["issn"]) {
                $searchParameters["ISSN"] = $citation["Leganto"]["metadata"]["issn"];
            }
            if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
            }
            $searchParameters["DOCTYPE"] = Array("ar", "re"); // this parameter may have multiple possible values (to join with "or") 
    } else if (isset($citation["Leganto"]["secondary_type"]["value"]) && $citation["Leganto"]["secondary_type"]["value"]=="BK"
        && isset($citation["Leganto"]["metadata"]["title"]) && $citation["Leganto"]["metadata"]["title"]) {
            $searchParameters["TITLE"] = $citation["Leganto"]["metadata"]["title"];
            if (isset($citation["Leganto"]["metadata"]["isbn"]) && $citation["Leganto"]["metadata"]["isbn"]) {
                $searchParameters["ISBN"] = $citation["Leganto"]["metadata"]["isbn"];
            }
            if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
            }
            $searchParameters["DOCTYPE"] = Array("bk");
    } else if (isset($citation["Leganto"]["secondary_type"]["value"]) && in_array($citation["Leganto"]["secondary_type"]["value"], Array("WS", "CONFERENCE", "E_BK", "OTHER"))
        && isset($citation["Leganto"]["metadata"]["title"]) && $citation["Leganto"]["metadata"]["title"]
        && isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
            $searchParameters["TITLE"] = $citation["Leganto"]["metadata"]["title"];
            if (isset($citation["Leganto"]["metadata"]["isbn"]) && $citation["Leganto"]["metadata"]["isbn"]) {
                $searchParameters["ISBN"] = $citation["Leganto"]["metadata"]["isbn"];
            }
            if (isset($citation["Leganto"]["metadata"]["issn"]) && $citation["Leganto"]["metadata"]["issn"]) {
                $searchParameters["ISSN"] = $citation["Leganto"]["metadata"]["issn"];
            }
            if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
                $extraParameters["LEGANTO-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
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
        // because the search terms are not wrapped in {} or "" 
        //TODO need to properly deal with special characters (",*,?) in *any* field?
        // NB for now removing double-quotes altogether because of problems 
        if (isset($searchParameters["TITLE"])) { 
            // $searchParameters["TITLE"] = str_replace('"', '\"', $searchParameters["TITLE"]); 
            $searchParameters["TITLE"] = str_replace('"', '', $searchParameters["TITLE"]);
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
            $summaryFields = Array("eid"=>TRUE, "dc:title"=>TRUE, "dc:creator"=>TRUE, "prism:publicationName"=>TRUE, "subtype"=>TRUE);
            foreach ($scopusSearchData["search-results"]["entry"] as $entry) {
                $citation["Scopus"]["results"][] = array_filter(array_intersect_key($entry, $summaryFields));
            }
            
            $entry = $scopusSearchData["search-results"]["entry"][0]; // now only interested in first result 
            $links = $entry["link"]; 
            $linkAuthorAffiliation = FALSE; 
            
            foreach ($links as $link) { 
                if ($link["@ref"]=="self") {
                    $citation["Scopus"]["first-match"]["self"] = $link["@href"]; 
                }
                if ($link["@ref"]=="author-affiliation") {
                    $linkAuthorAffiliation = $link["@href"];
                }
            }
            
            
            $collatedAuthorsShort = Array(); 
            $collatedAuthorsLong = Array();
            
            if ($linkAuthorAffiliation) { 
                
                $scopusAuthorAffiliationData = scopusApiQuery($linkAuthorAffiliation, $citation["Scopus"], "abstract-retrieval", TRUE);
                if (!$scopusAuthorAffiliationData) { continue; }
                
                
                $citation["Scopus"]["first-match"]["authors"] = Array(); 
                if (isset($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"]["author"])) {
                    foreach ($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"]["author"] as $author) {
                        $citationScopusAuthor = array_filter(array_intersect_key($author, Array("@auid"=>TRUE, "author-url"=>TRUE, "preferred-name"=>TRUE, "ce:indexed-name"=>TRUE, "ce:surname"=>TRUE, "ce:initials"=>TRUE, "ce:given-name"=>TRUE, "affiliation"=>TRUE)));
                        
                        // assemble string list of authors for later comparison with source metadata 
                        if (isset($author["ce:indexed-name"]) && $author["ce:indexed-name"]) { 
                            $collatedAuthorsShort[] = $author["ce:indexed-name"];
                        }
                        if (isset($author["ce:surname"]) && $author["ce:surname"]) {
                            $collatedAuthorLong = $author["ce:surname"]." ";
                            if (isset($author["ce:given-name"]) && $author["ce:given-name"]) {
                                $collatedAuthorLong .= $author["ce:given-name"];
                            }
                            $collatedAuthorsLong[] = $collatedAuthorLong; 
                        }
                        
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
                        $citation["Scopus"]["first-match"]["authors"][] = $citationScopusAuthor;
                    }
                }
            }
            
            // try to quantify the similarity of title and authors between source and Scopus 
            // titles first 
            $thisSimilarity = 0;
            $foundSimilarity = FALSE;
            if (isset($entry["dc:title"]) && $entry["dc:title"])  {
                // first just use the title we searched for (the Leganto title) 
                if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
                    $foundSimilarity = TRUE;
                    $thisSimilarity = max($thisSimilarity, similarity($entry["dc:title"], $searchParameters["TITLE"], "Levenshtein", FALSE));
                }
                // now try comparing with all the Alma titles
                foreach ($extraParameters["ALMA-TITLES"] as $titleAlma) {
                    $foundSimilarity = TRUE;
                    if (isset($titleAlma["collated"])) {
                        $thisSimilarity = max($thisSimilarity, similarity($entry["dc:title"], $titleAlma["collated"], "Levenshtein", FALSE));
                    }
                    if (isset($titleAlma["a"])) {
                        $thisSimilarity = max($thisSimilarity, similarity($entry["dc:title"], $titleAlma["a"], "Levenshtein", FALSE));
                    }
                }
            }
            // now save the best match we found by any means
            if ($foundSimilarity) {
                $citation["Scopus"]["first-match"]["similarity-title"] = $thisSimilarity;
            }
            
            // now authors 
            $thisSimilarity = 0;
            $foundSimilarity = FALSE; 
            // first try comparing the Leganto author field with any individual Scopus author as well as with them all together 
            if (isset($extraParameters["LEGANTO-AUTHOR"]) && $extraParameters["LEGANTO-AUTHOR"]) {
                foreach ($collatedAuthorsShort as $collatedAuthor) { 
                    $foundSimilarity = TRUE;
                    $thisSimilarity = max($thisSimilarity, similarity($collatedAuthor, $extraParameters["LEGANTO-AUTHOR"], "Levenshtein", FALSE));
                }
                foreach ($collatedAuthorsLong as $collatedAuthor) {
                    $foundSimilarity = TRUE;
                    $thisSimilarity = max($thisSimilarity, similarity($collatedAuthor, $extraParameters["LEGANTO-AUTHOR"], "Levenshtein", FALSE));
                }
                if (count($collatedAuthorsShort)) {
                    $foundSimilarity = TRUE;
                    $thisSimilarity = max($thisSimilarity, similarity(implode("", $collatedAuthorsShort), $extraParameters["LEGANTO-AUTHOR"], "Levenshtein", FALSE, TRUE)); // final TRUE means sort all words in strings into alphabetical order first
                }
                if (count($collatedAuthorsLong)) {
                    $foundSimilarity = TRUE;
                    $thisSimilarity = max($thisSimilarity, similarity(implode("", $collatedAuthorsLong), $extraParameters["LEGANTO-AUTHOR"], "Levenshtein", FALSE, TRUE)); // final TRUE means sort all words in strings into alphabetical order first
                }
            }
            // now try comparing the set of Alma creators with the set of Scopus authors 
            $totalAuthorSimilarity = 0; 
            foreach ($extraParameters["ALMA-CREATORS"] as $creatorAlma) { 
                $thisAuthorSimilarity = 0; 
                foreach ($collatedAuthorsShort as $collatedAuthor) {
                    $foundSimilarity = TRUE;
                    if (isset($creatorAlma["collated"])) { 
                        $thisAuthorSimilarity = max($thisAuthorSimilarity, similarity($collatedAuthor, $creatorAlma["collated"], "Levenshtein", FALSE)); 
                    }
                    if (isset($creatorAlma["a"])) {
                        $thisAuthorSimilarity = max($thisAuthorSimilarity, similarity($collatedAuthor, $creatorAlma["a"], "Levenshtein", FALSE));
                    }
                }
                foreach ($collatedAuthorsLong as $collatedAuthor) {
                    $foundSimilarity = TRUE;
                    if (isset($creatorAlma["collated"])) {
                        $thisAuthorSimilarity = max($thisAuthorSimilarity, similarity($collatedAuthor, $creatorAlma["collated"], "Levenshtein", FALSE));
                    }
                    if (isset($creatorAlma["a"])) {
                        $thisAuthorSimilarity = max($thisAuthorSimilarity, similarity($collatedAuthor, $creatorAlma["a"], "Levenshtein", FALSE));
                    }
                }
                $totalAuthorSimilarity += $thisAuthorSimilarity; 
            }
            if (count($extraParameters["ALMA-CREATORS"])) { 
                $thisSimilarity = max($thisSimilarity, $totalAuthorSimilarity/count($extraParameters["ALMA-CREATORS"])); 
            }
            // now save the best match we found by any means  
            if ($foundSimilarity) {
                $citation["Scopus"]["first-match"]["similarity-authors"] = $thisSimilarity;
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
 * @param String  $type             Used e.g. to identify the source of any errors  
 * @param Boolean $checkRateLimit   Whether to check and log the rate-limit data in the response 
 * @param String  $require          Key which we require to have in the returned array, otehrwise log error and return FALSE 
 */
function scopusApiQuery($URL, &$citationScopus, $type="default", $checkRateLimit=FALSE, $require=NULL) { 
     
    global $scopusCache, $config, $http_response_header; // latter needed to allow curl_get_file_contents to mimic file_get_contents side-effect
    
    $URL = preg_replace('/^http:\/\//', "https://", $URL); // a few references in the API use http

    // Cached result?
    if (isset($scopusCache[$URL])) { 
        return $scopusCache[$URL]; 
    }
    // else 
    
    $apiKey = $config["Scopus"]["apiKey"]; 
    $httpAccept = "application/json"; 
    $reqId = microtime(TRUE);
    
    $apiURL = $URL."&httpAccept=".urlencode($httpAccept)."&reqId=".urlencode($reqId)."&apiKey=".urlencode($apiKey); 
   
    usleep(100000); // so as not to hammer the API 
    
    $scopusResponse = curl_get_file_contents($apiURL);
    
    if ($checkRateLimit) { 
        if (!isset($citationScopus["rate-limit"])) {
            $citationScopus["rate-limit"] = Array();
        }
        if (!isset($citationScopus["rate-limit"][$type])) {
            $citationScopus["rate-limit"][$type] = Array();
        }
        foreach ($http_response_header as $header) {
            if (preg_match('/^X-RateLimit-(\w+):\s*(\d+)/', $header, $matches)) {
                $citationScopus["rate-limit"][$type][$matches[1]] = $matches[2]; // limit, remaining, reset
            }
        }
    }
    
    if (!$scopusResponse) {
        if (!isset($citationScopus["errors"])) {
            $citationScopus["errors"] = Array();
        }
        if (!isset($citationScopus["errors"][$type])) {
            $citationScopus["errors"][$type] = Array();
        }
        $citationScopus["errors"][$type][] = Array("link"=>$URL, "error"=>"No response from API");
        $scopusCache[$URL] = FALSE; 
        return FALSE;
    }
    
    $scopusData = json_decode($scopusResponse,TRUE);
    
    if ($scopusData===null) {
        if (!isset($citationScopus["errors"])) {
            $citationScopus["errors"] = Array();
        }
        if (!isset($citationScopus["errors"][$type])) {
            $citationScopus["errors"][$type] = Array();
        }
        $citationScopus["errors"][$type][] = Array("link"=>$URL, "error"=>"Response from API cannot be decoded as JSON");
        $scopusCache[$URL] = FALSE;
        return FALSE;
    }
    if (isset($scopusData["service-error"])) {
        if (!isset($citationScopus["errors"])) {
            $citationScopus["errors"] = Array();
        }
        if (!isset($citationScopus["errors"][$type])) {
            $citationScopus["errors"][$type] = Array();
        }
        $serviceError = $scopusData["service-error"];
        $errorMessage = (isset($serviceError["status"]) && isset($serviceError["status"]["statusCode"])) ? $serviceError["status"]["statusCode"] : "Unknown error code";
        $errorMessage .= " (";
        $errorMessage .= (isset($serviceError["status"]) && isset($serviceError["status"]["statusText"])) ? $serviceError["status"]["statusText"] : "Unknown error text";
        $errorMessage .= ")";
        $citationScopus["errors"][$type][] = Array("link"=>$URL, "error"=>$errorMessage);
        $scopusCache[$URL] = FALSE;
        return FALSE;
    }
    
    if ($require!==NULL) { 
        if (!isset($scopusData[$require])) { 
            if (!isset($citationScopus["errors"])) {
                $citationScopus["errors"] = Array();
            }
            if (!isset($citationScopus["errors"][$type])) {
                $citationScopus["errors"][$type] = Array();
            }
            $citationScopus["errors"][$type][] = Array("link"=>$URL, "error"=>"Required key missing from response: $require");
            $scopusCache[$URL] = FALSE;
            return FALSE;
        }
    }
    
    // Cache 
    $scopusCache[$URL] = $scopusData;
    
    return $scopusData; 
    
}





?>