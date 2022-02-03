<?php 

/*
 * Expects JSON input from stdin
 */


error_reporting(E_ALL);                     // we want to know about all problems

$key = "ace0da58224b7aafa53389ebb4b157ad"; //TODO replace this 


require_once("utils.php"); 




$scopusAffiliationCache = Array(); // we won't call the API every time, because affiliations are often repeated



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
    }
    
    if ($searchTermEncoded) {

        $citation["Scopus"] = Array(); // to populate
        
        usleep(100000);
        
        $citationScopus = Array();
        
        $citationScopus["search"] = $searchTermDisplay;
        
        $scopusSearchURL = "https://api.elsevier.com/content/search/scopus?httpAccept=application/json&apiKey=$key&reqId=".microtime(TRUE)."&query=".$searchTermEncoded;
        
        $scopusSearchResponse = curl_get_file_contents($scopusSearchURL);
        
        // rate limit and error checks 
        foreach ($http_response_header as $header) {
            if (preg_match('/^X-RateLimit-(\w+):\s*(\d+)/', $header, $matches)) {
                if (!isset($citationScopus["rate-limit"])) { 
                    $citationScopus["rate-limit"] = Array(); 
                }
                $citationScopus["rate-limit"][$matches[1]] = $matches[2]; // limit, remaining, reset 
            }
        }
        if (!$scopusSearchResponse) { 
            $citationScopus["search-error"] = "No response from API"; 
            break; 
        }
        $scopusSearchData = json_decode($scopusSearchResponse,TRUE);
        if ($scopusSearchData===null) { 
            $citationScopus["search-error"] = "Response from API cannot be decoded as JSON";
            break;
        }
        if (isset($scopusSearchData["service-error"])) {
            $serviceError = $scopusSearchData["service-error"]; 
            $citationScopus["search-error"] = (isset($serviceError["status"]) && isset($serviceError["status"]["statusCode"])) ? $serviceError["status"]["statusCode"] : "Unknown error code";
            $citationScopus["search-error"] .= " ("; 
            $citationScopus["search-error"] .= (isset($serviceError["status"]) && isset($serviceError["status"]["statusText"])) ? $serviceError["status"]["statusText"] : "Unknown error text";
            $citationScopus["search-error"] .= ")";
            break;
        }
        
        $citationScopus["records"] = $scopusSearchData["search-results"]["opensearch:totalResults"];
        
        if ($citationScopus["records"]) {
            
            $citationScopus["first-match"] = Array(); 
            
            $links = $scopusSearchData["search-results"]["entry"][0]["link"];
            $linkAuthorAffiliation = FALSE; 
            
            foreach ($links as $link) { 
                if ($link["@ref"]=="self") {
                    $citationScopus["first-match"]["self"] = $link["@href"]; 
                }
                if ($link["@ref"]=="author-affiliation") {
                    $linkAuthorAffiliation = $link["@href"];
                }
            }
            
            if ($linkAuthorAffiliation) { 
                
                usleep(100000);
                
                $linkAuthorAffiliationURL = $linkAuthorAffiliation."&httpAccept=application/json&apiKey=$key"; 
                
                $scopusAuthorAffiliationResponse = curl_get_file_contents($linkAuthorAffiliationURL);
                
                // error checks
                if (!$scopusAuthorAffiliationResponse) {
                    if (!isset($citationScopus["abstract-errors"])) { $citationScopus["abstract-errors"] = Array(); }
                    $citationScopus["abstract-errors"][] = Array("link"=>$linkAuthorAffiliation, "error"=>"No response from API"); 
                    break;
                }
                $scopusAuthorAffiliationData = json_decode($scopusAuthorAffiliationResponse,TRUE);
                if ($scopusAuthorAffiliationData===null) {
                    if (!isset($citationScopus["abstract-errors"])) { $citationScopus["abstract-errors"] = Array(); }
                    $citationScopus["abstract-errors"][] = Array("link"=>$linkAuthorAffiliation, "error"=>"Response from API cannot be decoded as JSON");
                    break;
                }
                if (isset($scopusAuthorAffiliationData["service-error"])) {
                    if (!isset($citationScopus["abstract-errors"])) { $citationScopus["abstract-errors"] = Array(); }
                    $serviceError = $scopusAuthorAffiliationData["service-error"];
                    $errorMessage = (isset($serviceError["status"]) && isset($serviceError["status"]["statusCode"])) ? $serviceError["status"]["statusCode"] : "Unknown error code";
                    $errorMessage .= " (";
                    $errorMessage .= (isset($serviceError["status"]) && isset($serviceError["status"]["statusText"])) ? $serviceError["status"]["statusText"] : "Unknown error text";
                    $errorMessage .= ")";
                    $citationScopus["abstract-errors"][] = Array("link"=>$linkAuthorAffiliation, "error"=>$errorMessage);
                    break;
                }
                if (!isset($scopusAuthorAffiliationData["abstracts-retrieval-response"]) || !isset($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"])) {
                    if (!isset($citationScopus["abstract-errors"])) { $citationScopus["abstract-errors"] = Array(); }
                    $citationScopus["abstract-errors"][] = Array("link"=>$linkAuthorAffiliation, "error"=>"No authors in response from API");
                    break;
                }
                
                
                
                $citationScopus["first-match"]["authors"] = Array(); 
                if (isset($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"]["author"])) {
                    foreach ($scopusAuthorAffiliationData["abstracts-retrieval-response"]["authors"]["author"] as $author) {
                        $citationScopusAuthor = array_filter(array_intersect_key($author, Array("@auid"=>TRUE, "preferred-name"=>TRUE, "ce:indexed-name"=>TRUE, "affiliation"=>TRUE)));
                        if (isset($author["affiliation"]) && isset($author["affiliation"]["@id"]) && isset($author["affiliation"]["@href"])) {
                            // fetch affiliation details 
                            // try cache 
                            if (isset($scopusAffiliationCache[$author["affiliation"]["@id"]])) {
                                $citationScopusAuthor["affiliation"] = array_merge($citationScopusAuthor["affiliation"], $scopusAffiliationCache[$author["affiliation"]["@id"]]);
                            } else {
                                usleep(100000);
                                
                                $affiliationURL = $author["affiliation"]["@href"]."?httpAccept=application/json&apiKey=$key";
                                
                                $affiliationResponse = curl_get_file_contents($affiliationURL);
                                
                                // error checks
                                if (!$affiliationResponse) {
                                    if (!isset($citationScopus["affiliation-errors"])) { $citationScopus["affiliation-errors"] = Array(); }
                                    $citationScopus["affiliation-errors"][] = Array("link"=>$author["affiliation"]["@href"], "error"=>"No response from API");
                                    break;
                                }
                                $affiliationData = json_decode($affiliationResponse,TRUE);
                                if ($affiliationData===null) {
                                    if (!isset($citationScopus["affiliation-errors"])) { $citationScopus["affiliation-errors"] = Array(); }
                                    $citationScopus["affiliation-errors"][] = Array("link"=>$author["affiliation"]["@href"], "error"=>"Response from API cannot be decoded as JSON");
                                    break;
                                }
                                if (isset($affiliationData["service-error"])) {
                                    if (!isset($citationScopus["affiliation-errors"])) { $citationScopus["affiliation-errors"] = Array(); }
                                    $serviceError = $affiliationData["service-error"];
                                    $errorMessage = (isset($serviceError["status"]) && isset($serviceError["status"]["statusCode"])) ? $serviceError["status"]["statusCode"] : "Unknown error code";
                                    $errorMessage .= " (";
                                    $errorMessage .= (isset($serviceError["status"]) && isset($serviceError["status"]["statusText"])) ? $serviceError["status"]["statusText"] : "Unknown error text";
                                    $errorMessage .= ")";
                                    $citationScopus["affiliation-errors"][] = Array("link"=>$author["affiliation"]["@href"], "error"=>$errorMessage);
                                    break;
                                }
                                if (!isset($affiliationData["affiliation-retrieval-response"])) {
                                    if (!isset($citationScopus["affiliation-errors"])) { $citationScopus["affiliation-errors"] = Array(); }
                                    $citationScopus["affiliation-errors"][] = Array("link"=>$author["affiliation"]["@href"], "error"=>"No authors in response from API");
                                    break;
                                }
                                $citationScopusAuthorAffiliation = array_filter(array_intersect_key($affiliationData["affiliation-retrieval-response"], Array("affiliation-name"=>TRUE, "address"=>TRUE, "city"=>TRUE, "country"=>TRUE)));
                                $scopusAffiliationCache[$author["affiliation"]["@id"]] = $citationScopusAuthorAffiliation; // cache this 
                                $citationScopusAuthor["affiliation"] = array_merge($citationScopusAuthor["affiliation"], $citationScopusAuthorAffiliation); 
                            }
                        }
                    }
                    $citationScopus["first-match"]["authors"][] = $citationScopusAuthor; 
                }
            }
            
            $citation["Scopus"] = $citationScopus; // add it
            
        }
        
        
    }
    
    
    
}




print json_encode($citations, JSON_PRETTY_PRINT);






?>