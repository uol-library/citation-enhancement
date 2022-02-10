<?php 

/*
 * Expects JSON input from stdin
 */





error_reporting(E_ALL);                     // we want to know about all problems


require_once("utils.php"); 



$citations = json_decode(file_get_contents("php://stdin"), TRUE);


foreach ($citations as &$citation) { 
    
    if (isset($citation["Leganto"]["secondary_type"]["value"]) && $citation["Leganto"]["secondary_type"]["value"]=="BK") {
        
        $creators = Array(); 
        $titles = Array(); 
        $creatorsSeen = Array(); 
        $titlesSeen = Array();
        // assemble from Alma data 
        if (isset($citation["Alma"]) && isset($citation["Alma"]["creators"])) { 
            foreach ($citation["Alma"]["creators"] as $creatorAlma) { 
                $creatorAlmaSerialised = print_r($creatorAlma, TRUE); 
                if (isset($creatorAlma["collated"]) && $creatorAlma["collated"] && !in_array($creatorAlmaSerialised, $creatorsSeen)) {
                    $creators[] = $creatorAlma; 
                    $creatorsSeen[] = $creatorAlmaSerialised; 
                }
            }
        }
        if (isset($citation["Alma"]) && isset($citation["Alma"]["titles"])) {
            foreach ($citation["Alma"]["titles"] as $titleAlma) {
                $titleAlmaSerialised = print_r($titleAlma, TRUE);
                if (isset($titleAlma["collated"]) && $titleAlma["collated"] && !in_array($titleAlmaSerialised, $titlesSeen)) {
                    $titles[] = $titleAlma;
                    $titlesSeen[] = $titleAlmaSerialised;
                }
            }
        }

        if (count($creators) && count($titles)) {

            $citation["VIAF"] = Array(); // to populate 
            
            foreach ($creators as &$creator) {
                
                usleep(250000);
                
                $citationViaf = Array();
                
                $citationViaf["search-fields"] = "local.names+exact";
                $citationViaf["search-term-source"] = "collated";
                $citationViaf["search-term"] = $creator[$citationViaf["search-term-source"]];
                $viafSearchData = viafApiQuery($citationViaf["search-fields"], $citationViaf["search-term"]); 
                
                /* 
                $viafSearchURL = "http://viaf.org/viaf/search?query=".$citationViaf["search-fields"]."+%22".urlencode($creator)."%22&maximumRecords=10&startRecord=1&sortKeys=holdingscount&httpAccept=text/xml";
                $viafSearchResponse = curl_get_file_contents($viafSearchURL);
                
                //TODO error checking
                
                $viafSearchResponse = preg_replace('/(<\/?)ns2:/', "$1", $viafSearchResponse); // kludge - need to parse namespaced document properly
                
                $viafSearchData = new SimpleXmlElement($viafSearchResponse);
                */ 
                
                $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                
                if (!$citationViaf["records"]) {
                    // try again with a slightly more generous search 
                    $citationViaf["search-fields"] = "local.names+all";
                    $viafSearchData = viafApiQuery($citationViaf["search-fields"], $citationViaf["search-term"]);
                    $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                }
                // don't do this next one - generating too many false matches 
                /*
                if (!$citationViaf["records"]) {
                    // try again with an even more generous search
                    $citationViaf["search-fields"] = "local.names+any";
                    $viafSearchData = viafApiQuery($citationViaf["search-fields"], $citationViaf["search-term"]);
                    $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                }
                */
                if (!$citationViaf["records"]) {
                    // try again but only look in the $a for the author 
                    $citationViaf["search-term-source"] = "a";
                    $citationViaf["search-term"] = $creator[$citationViaf["search-term-source"]];
                    $citationViaf["search-fields"] = "local.names+exact";
                    $viafSearchData = viafApiQuery($citationViaf["search-fields"], $citationViaf["search-term"]);
                    $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                }
                if (!$citationViaf["records"]) {
                    // try again with a slightly more generous search 
                    $citationViaf["search-fields"] = "local.names+all";
                    $viafSearchData = viafApiQuery($citationViaf["search-fields"], $citationViaf["search-term"]);
                    $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                }
                
                
                if ($citationViaf["records"]) {
                    
                    $viafDataParsed = FALSE;
                    $viafBestConfidence = FALSE; // set to integer 0-100 when we find potential match
                    
                    foreach ($viafSearchData->records->record as $record) {
                        
                        $viafRecordTitles = Array(); // collect to compare with source title
                        $viafNationalities = Array();
                        $viafLocations = Array();
                        $viafAffiliations = Array();
                        
                        $viafDataParsedItem = Array();
                        
                        $viafCluster = $record->recordData->VIAFCluster;
                        
                        if ($viafCluster->mainHeadings) {
                            foreach ($viafCluster->mainHeadings->data as $viafHeadingObject) {
                                $viafDataParsedItem["heading"] = $viafHeadingObject->text->__toString();
                                break;
                            }
                        }
                        
                        if ($viafCluster->Document) {
                            $viafDataParsedItem["about"] = $viafCluster->Document["about"]->__toString();
                        }
                        
                        $viafDataParsedItem["confidence-title"] = FALSE; // will populate below
                        
                        $viafTitles = $viafCluster->titles;
                        if ($viafTitles) {
                            foreach ($viafTitles->work as $work) {
                                $viafRecordTitle = trim($work->title->__toString());
                                if ($viafRecordTitle) {
                                    if (!isset($viafDataParsedItem["titles"])) { $viafDataParsedItem["titles"] = Array(); }
                                    $viafDataParsedItem["titles"][] = $viafRecordTitle;
                                }
                            }
                        }
                        
                        $viafNatData = $viafCluster->nationalityOfEntity ? $viafCluster->nationalityOfEntity->data : Array();
                        foreach ($viafNatData as $viafNatDataItem) {
                            $viafNationality = trim($viafNatDataItem->text->__toString());
                            if ($viafNationality) {
                                if (!isset($viafDataParsedItem["nationalities"])) { $viafDataParsedItem["nationalities"] = Array(); }
                                $viafDataParsedItem["nationalities"][] = Array("value"=>$viafNationality);
                            }
                        }
                        
                        $viaf5xxData = $viafCluster->x500s ? $viafCluster->x500s->x500 : Array();
                        foreach ($viaf5xxData as $viaf5xxDataItem) {
                            
                            $dataField = $viaf5xxDataItem->datafield;
                            
                            if ($dataField["tag"]=="551") {
                                foreach ($dataField->subfield as $subfield) {
                                    if ($subfield["code"]=="a") {
                                        if (!isset($viafDataParsedItem["locations"])) { $viafDataParsedItem["locations"] = Array(); }
                                        $viafDataParsedItem["locations"][] = Array("value"=>trim($subfield->__toString()));
                                        break;
                                    }
                                }
                            }
                            if ($dataField["tag"]=="510" && $dataField["ind1"]=="2" && $dataField["ind2"]==" ") {
                                $viafDataParsedItemAffiliation = Array();
                                foreach ($dataField->subfield as $subfield) {
                                    if ($subfield["code"]=="a" && !isset($viafDataParsedItemAffiliation["value"])) {
                                        $viafDataParsedItemAffiliation["value"] = trim($subfield->__toString());
                                    }
                                    if ($subfield["code"]=="e" && !isset($viafDataParsedItemAffiliation["\$e"])) {
                                        $viafDataParsedItemAffiliation["\$e"] = trim($subfield->__toString());
                                    }
                                }
                                if (count($viafDataParsedItemAffiliation)) {
                                    if (!isset($viafDataParsedItem["affiliations"])) { $viafDataParsedItem["affiliations"] = Array(); }
                                    $viafDataParsedItem["affiliations"][] = $viafDataParsedItemAffiliation;
                                }
                            }
                        }
                        
                        
                        // cross compare source and arget titles
                        if (isset($viafDataParsedItem["titles"]) && count($viafDataParsedItem["titles"])) {
                            
                            foreach ($titles as $citationTitle) {
                                
                                if (isset($citationTitle["collated"])) { 

                                    foreach ($viafDataParsedItem["titles"] as $viafTitle) {
                                        
                                        $thisConfidence = similarity($viafTitle, $citationTitle["collated"]);
                                        
                                        if ($viafBestConfidence===FALSE || $thisConfidence>$viafBestConfidence) {
                                            
                                            $viafBestConfidence = $thisConfidence;
                                            
                                            $viafDataParsedItem["confidence-title"] = $thisConfidence;
                                            // unset($viafDataParsedItem["titles"]); // we won't keep titles for now
                                            $viafDataParsed = $viafDataParsedItem;
                                            
                                        }
                                        
                                        // special case
                                        if ($thisConfidence==100) {
                                            break 2; // no point in testing any more
                                        }
                                    }
                                }
                            }
                            
                            
                        }
                        
                    }
                    
                    if ($viafDataParsed) {
                        $citationViaf["best-match"] = $viafDataParsed; // the best we found so far
                    }
                    
                }
                
                $citation["VIAF"][] = $citationViaf; // add it
                
            }
            
            
            
            
            
            
        }
        
        
    }
    
    
    
}




print json_encode($citations, JSON_PRETTY_PRINT);



function viafApiQuery($fields, $term) { 
    
    global $config, $http_response_header; // latter needed to allow curl_get_file_contents to mimic file_get_contents side-effect
    
    $viafSearchURL = "http://viaf.org/viaf/search?query=".$fields."+%22".urlencode($term)."%22&maximumRecords=10&startRecord=1&sortKeys=holdingscount&httpAccept=text/xml";
    $viafSearchResponse = curl_get_file_contents($viafSearchURL);

    
    //TODO error checking
    $viafSearchResponse = preg_replace('/(<\/?)ns2:/', "$1", $viafSearchResponse); // kludge - need to parse namespaced document properly
    return new SimpleXmlElement($viafSearchResponse);
}




?>