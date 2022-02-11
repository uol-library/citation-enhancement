<?php 

/*
 * Expects JSON input from stdin
 */


error_reporting(E_ALL);                     // we want to know about all problems

$scopusCache = Array(); // because of rate limit, don't fetch unless we have to 

require_once("utils.php"); 




$citations = json_decode(file_get_contents("php://stdin"), TRUE);


foreach ($citations as &$citation) { 
    
    $searchParameters = Array(); // collect things here 
    
    if (isset($citation["Leganto"]["metadata"]["doi"]) && $citation["Leganto"]["metadata"]["doi"]) {
        $doi = $citation["Leganto"]["metadata"]["doi"];
        $doi = preg_replace('/^https?:\/\/doi\.org\//', '', $doi);
        $searchParameters["DOI"] = $doi; 
    }
    if (isset($citation["Leganto"]["secondary_type"]["value"]) && $citation["Leganto"]["secondary_type"]["value"]=="CR"
        && isset($citation["Leganto"]["metadata"]["article_title"]) && $citation["Leganto"]["metadata"]["article_title"]) {
            $searchParameters["TITLE"] = $citation["Leganto"]["metadata"]["article_title"];
            if (isset($citation["Leganto"]["metadata"]["issn"]) && $citation["Leganto"]["metadata"]["issn"]) {
                $searchParameters["ISSN"] = $citation["Leganto"]["metadata"]["issn"];
            }
            if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
            }
            $searchParameters["DOCTYPE"] = "ar";
    }
    if (isset($citation["Leganto"]["secondary_type"]["value"]) && $citation["Leganto"]["secondary_type"]["value"]=="BK"
        && isset($citation["Leganto"]["metadata"]["title"]) && $citation["Leganto"]["metadata"]["title"]) {
            $searchParameters["TITLE"] = $citation["Leganto"]["metadata"]["title"];
            if (isset($citation["Leganto"]["metadata"]["isbn"]) && $citation["Leganto"]["metadata"]["isbn"]) {
                $searchParameters["ISBN"] = $citation["Leganto"]["metadata"]["isbn"];
            }
            if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
                $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]);
                $searchParameters["AUTH"] = $legantoAuthor;
            }
            $searchParameters["DOCTYPE"] = "bk";
    }
        
    
    
    // if ($searchTermEncoded) {
    if (count($searchParameters)) { 

        $citation["Scopus"] = Array(); // to populate
        
        $searchStrings = Array(); // assemble search parameters into one or more search strings, in order of preference
        
        if (isset($searchParameters["DOI"]) && $searchParameters["DOI"]) {
            $searchStrings[] = "DOI(".$searchParameters["DOI"].")";
        }
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TITLE({".$searchParameters["TITLE"]."})"; // exact match
            if (isset($searchParameters["DOCTYPE"]) && $searchParameters["DOCTYPE"]) { $searchString .= " AND DOCTYPE(".$searchParameters["DOCTYPE"].")"; }
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"]) { $searchString .= " AND AUTH(".$searchParameters["AUTH"].")"; }
            if (isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) { $searchString .= " AND ISBN(".$searchParameters["ISBN"].")"; }
            if (isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) { $searchString .= " AND ISSN(".$searchParameters["ISSN"].")"; }
            $searchStrings[] = $searchString;
            
        }
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TITLE(\"".str_replace('"', '\"', $searchParameters["TITLE"])."\")"; // slightly looser match
            $extraParams = FALSE;
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"]) { $extraParams=TRUE; $searchString .= " AND AUTH(".$searchParameters["AUTH"].")"; }
            if (isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) { $extraParams=TRUE; $searchString .= " AND ISBN(".$searchParameters["ISBN"].")"; }
            if (isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) { $extraParams=TRUE; $searchString .= " AND ISSN(".$searchParameters["ISSN"].")"; }
            if (!$extraParams && isset($searchParameters["DOCTYPE"]) && $searchParameters["DOCTYPE"]) { $searchString .= " AND DOCTYPE(".$searchParameters["DOCTYPE"].")"; }
            if (!in_array($searchString, $searchStrings)) { $searchStrings[] = $searchString; } // only add this one if we've made a difference
            
        }
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TITLE(\"".str_replace('"', '\"', $searchParameters["TITLE"])."\")";
            // even looser match
            if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"] && isset($searchParameters["ISSN"]) && $searchParameters["ISSN"]) {
                $searchString .= " AND (AUTH(".$searchParameters["AUTH"].") OR ISSN(".$searchParameters["ISSN"]."))";
            } else if (isset($searchParameters["AUTH"]) && $searchParameters["AUTH"] && isset($searchParameters["ISBN"]) && $searchParameters["ISBN"]) {
                $searchString .= " AND (AUTH(".$searchParameters["AUTH"].") OR ISBN(".$searchParameters["ISBN"]."))";
            }
            if (!in_array($searchString, $searchStrings)) { $searchStrings[] = $searchString; } // only add this one if we've made a difference
            
        }
        if (isset($searchParameters["TITLE"]) && $searchParameters["TITLE"]) {
            $searchString = "TITLE({".$searchParameters["TITLE"]."})";
            // sort-of looser match
            if (isset($searchParameters["DOCTYPE"]) && $searchParameters["DOCTYPE"]) { $searchString .= " AND DOCTYPE(".$searchParameters["DOCTYPE"].")"; }
            if (!in_array($searchString, $searchStrings)) { $searchStrings[] = $searchString; } // only add this one if we've made a difference
            
        }

        foreach ($searchStrings as $searchString) { 
            $scopusSearchData = scopusApiQuery("https://api.elsevier.com/content/search/scopus?query=".urlencode($searchString), $citation["Scopus"], "scopus-search", TRUE);
            if ($scopusSearchData && $scopusSearchData["search-results"]["opensearch:totalResults"]>0) { break; } // first successful result
            if (!isset($citation["Scopus"]["searches-no-results"])) { $citation["Scopus"]["searches-no-results"] = Array(); } 
            $citation["Scopus"]["searches-no-results"][] = $searchString; // record the one we're trying
        }
        if (!$scopusSearchData) { continue; } // move on to next citation 
            
        $citation["Scopus"]["result-count"] = $scopusSearchData["search-results"]["opensearch:totalResults"];
        
        if ($citation["Scopus"]["result-count"]) {
            
            $citation["Scopus"]["search-active"] = $searchString;
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
            
            if ($linkAuthorAffiliation) { 
                
                $scopusAuthorAffiliationData = scopusApiQuery($linkAuthorAffiliation, $citation["Scopus"], "abstract-retrieval", TRUE);
                if (!$scopusAuthorAffiliationData) { continue; }
                
                
                $citation["Scopus"]["first-match"]["authors"] = Array(); 
                if (isset($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"]["author"])) {
                    foreach ($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"]["author"] as $author) {
                        $citationScopusAuthor = array_filter(array_intersect_key($author, Array("@auid"=>TRUE, "author-url"=>TRUE, "preferred-name"=>TRUE, "ce:indexed-name"=>TRUE, "affiliation"=>TRUE)));
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
        }
    }
}



print json_encode($citations, JSON_PRETTY_PRINT);


/** 
 * Fetches 
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