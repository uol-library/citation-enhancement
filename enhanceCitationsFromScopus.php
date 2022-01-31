<?php 

/*
 * Expects JSON input from stdin
 */


error_reporting(E_ALL);                     // we want to know about all problems

$key = "ace0da58224b7aafa53389ebb4b157ad"; //TODO replace this 



function standardise($string) {
    $string = preg_replace('/\s*\/\s*$/', "", $string);
    $string = trim($string);
    return $string;
}

function normalise($string) {
    $string = strtolower($string);
    $string = preg_replace('/[\.,\-_;:\/\\\'"\?!\+\&]/', " ", $string);
    $string = preg_replace('/\s+/', " ", $string);
    $string = trim($string);
    return $string ? $string : FALSE;
}

function simplify($string) {
    $string = normalise($string);
    if ($string!==FALSE) {
        $parts = explode(" ", $string);
        sort($parts);
        $string = implode(" ", $parts);
    }
    return $string ? $string : FALSE;
}

function similarity($string1, $string2) {
    if ($string1==$string2) { return 100; }
    $string1 = normalise($string1);
    $string2 = normalise($string2);
    if (!$string1 || !$string2) { return 0; }
    $lev = levenshtein($string1, $string2);
    
    $pc = 100 * (1 - $lev/(strlen($string1)+strlen($string2)));
    
    if ($pc<0) { $pc = 0; }
    if ($pc>100) { $pc = 100; }
    
    return floor($pc);
}




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
        
        usleep(250000);
        
        $citationScopus = Array();
        
        $citationScopus["search"] = $searchTermDisplay;
        
        $scopusSearchURL = "https://api.elsevier.com/content/search/scopus?httpAccept=application/json&apiKey=$key&reqId=".hrtime(TRUE)."&query=".$searchTermEncoded;
        
        $scopusSearchResponse = file_get_contents($scopusSearchURL);
        $scopusSearchData = json_decode($scopusSearchResponse,TRUE);
        
        $citationScopus["records"] = $scopusSearchData["search-results"]["opensearch:totalResults"];
        
        if ($citationScopus["records"]) {
            
            $citationScopus["first-match"] = Array(); 
            
            $links = $scopusSearchData["search-results"]["entry"][0]["link"];
            $linkAffiliation = FALSE; 
            
            foreach ($links as $link) { 
                if ($link["@ref"]=="self") {
                    $citationScopus["first-match"]["self"] = $link["@href"]; 
                }
                if ($link["@ref"]=="author-affiliation") {
                    $linkAffiliation = $link["@href"];
                }
            }
            
            if ($linkAffiliation) { 
                
                usleep(500000);
                
                $linkAffiliation = $linkAffiliation."&httpAccept=application/json&apiKey=$key"; 
                
                $scopusAffiliationData = json_decode(file_get_contents($linkAffiliation), TRUE);
                
                if (isset($scopusAffiliationData["abstracts-retrieval-response"]) && isset($scopusAffiliationData["abstracts-retrieval-response"]["affiliation"])) { 
                    $scopusAffiliations = $scopusAffiliationData["abstracts-retrieval-response"]["affiliation"]; 
                    if (count($scopusAffiliations)) { 
                        $citationScopus["first-match"]["affiliations"] = $scopusAffiliations; 
                    }
                }
            }
            
            $citation["Scopus"] = $citationScopus; // add it
            
        }
        
        
    }
    
    
    
}




print json_encode($citations, JSON_PRETTY_PRINT);






?>