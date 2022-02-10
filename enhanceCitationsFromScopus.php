<?php 

/*
 * Expects JSON input from stdin
 */


error_reporting(E_ALL);                     // we want to know about all problems

$scopusCache = Array(); // because of rate limit, don't fetch unless we have to 

require_once("utils.php"); 




$citations = json_decode(file_get_contents("php://stdin"), TRUE);


foreach ($citations as &$citation) { 
    
    
    $searchTermEncoded = FALSE; 
    $searchTermDisplay = FALSE; 

    if (isset($citation["Leganto"]["metadata"]["doi"]) && $citation["Leganto"]["metadata"]["doi"]) {
        $doi = $citation["Leganto"]["metadata"]["doi"]; 
        $doi = preg_replace('/^https?:\/\/doi\.org\//', '', $doi);  
        $searchTermEncoded = "DOI(".urlencode($doi).")"; 
        $searchTermDisplay = "DOI( ".$doi." )";
    }
    if (!$searchTermEncoded && isset($citation["Leganto"]["secondary_type"]["value"]) && $citation["Leganto"]["secondary_type"]["value"]=="CR"
        && isset($citation["Leganto"]["metadata"]["article_title"]) && $citation["Leganto"]["metadata"]["article_title"]) {
        
        $searchTermEncoded = "TITLE(".urlencode('"'.$citation["Leganto"]["metadata"]["article_title"].'"').")";
        $searchTermDisplay = "TITLE( \"".$citation["Leganto"]["metadata"]["article_title"]."\" )";
        
        $additionalTerms = FALSE; 
        if (isset($citation["Leganto"]["metadata"]["issn"]) && $citation["Leganto"]["metadata"]["issn"]) {
            $searchTermEncoded .= "+AND+ISSN(".urlencode($citation["Leganto"]["metadata"]["issn"]).")";
            $searchTermDisplay .= " AND ISSN( ".$citation["Leganto"]["metadata"]["issn"]." )";
            $additionalTerms = TRUE; 
        }
        if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
            $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]); 
            $searchTermEncoded .= "+AND+AUTHOR-NAME(".urlencode($legantoAuthor).")";
            $searchTermDisplay .= " AND AUTHOR-NAME( ".$legantoAuthor." )";
            $additionalTerms = TRUE;
        }
        if ($additionalTerms) { 
            $searchTermEncoded = "($searchTermEncoded)"; 
            $searchTermDisplay = "( $searchTermDisplay )";
        }
        
        
    }
    if (!$searchTermEncoded && isset($citation["Leganto"]["secondary_type"]["value"]) && $citation["Leganto"]["secondary_type"]["value"]=="BK"
        && isset($citation["Leganto"]["metadata"]["title"]) && $citation["Leganto"]["metadata"]["title"]) {
        $searchTermEncoded = "TITLE(".urlencode('"'.$citation["Leganto"]["metadata"]["title"].'"').")";
        $searchTermDisplay = "TITLE( \"".$citation["Leganto"]["metadata"]["title"]."\" )";
        
        $additionalTerms = FALSE;
        if (isset($citation["Leganto"]["metadata"]["isbn"]) && $citation["Leganto"]["metadata"]["isbn"]) {
            $searchTermEncoded .= "+AND+ALL(".urlencode($citation["Leganto"]["metadata"]["isbn"]).")";
            $searchTermDisplay .= " AND ALL( ".$citation["Leganto"]["metadata"]["isbn"]." )";
            $additionalTerms = TRUE;
        }
        if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
            $legantoAuthor = preg_replace('/^([^,\s]*).*$/', '$1', $citation["Leganto"]["metadata"]["author"]);
            $searchTermEncoded .= "+AND+AUTHOR-NAME(".urlencode($legantoAuthor).")";
            $searchTermDisplay .= " AND AUTHOR-NAME( ".$legantoAuthor." )";
            $additionalTerms = TRUE;
        }
        if ($additionalTerms) {
            $searchTermEncoded = "($searchTermEncoded)";
            $searchTermDisplay = "( $searchTermDisplay )";
        }
        
    }
        
    
    if ($searchTermEncoded) {

        $citation["Scopus"] = Array(); // to populate
        
        $citation["Scopus"]["search"] = $searchTermDisplay;
                
        $scopusSearchData = scopusApiQuery("https://api.elsevier.com/content/search/scopus?query=".$searchTermEncoded, $citation["Scopus"], "scopus-search", TRUE);
        if (!$scopusSearchData) { continue; }
        
        $citation["Scopus"]["records"] = $scopusSearchData["search-results"]["opensearch:totalResults"];
        
        if ($citation["Scopus"]["records"]) {
            
            $citation["Scopus"]["first-match"] = Array(); 
            
            $links = $scopusSearchData["search-results"]["entry"][0]["link"];
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