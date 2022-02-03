<?php 

/*
 * Expects JSON input from stdin
 */





error_reporting(E_ALL);                     // we want to know about all problems


require_once("utils.php"); 



$citations = json_decode(file_get_contents("php://stdin"), TRUE);


foreach ($citations as &$citation) { 
    
    if (isset($citation["Leganto"]["secondary_type"]["value"]) && $citation["Leganto"]["secondary_type"]["value"]=="BK") {

        if (isset($citation["Alma"]) && isset($citation["Alma"]["creators"]) && isset($citation["Alma"]["titles"]) && count($citation["Alma"]["creators"]) && count($citation["Alma"]["titles"])) {

            $citation["VIAF"] = Array(); // to populate 
            
            foreach ($citation["Alma"]["creators"] as &$creator) {
                
                usleep(250000);
                
                $citationViaf = Array();
                
                $citationViaf["search"] = $creator;
                
                $viafSearchURL = "http://viaf.org/viaf/search?query=local.names+exact+%22".urlencode($creator)."%22&maximumRecords=10&startRecord=1&sortKeys=holdingscount&httpAccept=text/xml";
                $viafSearchResponse = curl_get_file_contents($viafSearchURL);
                
                //TODO error checking
                
                $viafSearchResponse = preg_replace('/(<\/?)ns2:/', "$1", $viafSearchResponse); // kludge - need to parse namespaced document properly
                
                $viafSearchData = new SimpleXmlElement($viafSearchResponse);
                $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                
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
                            
                            foreach ($citation["Alma"]["titles"] as $citationTitle) {
                                foreach ($viafDataParsedItem["titles"] as $viafTitle) {
                                    
                                    $thisConfidence = similarity($viafTitle, $citationTitle);
                                    
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






?>