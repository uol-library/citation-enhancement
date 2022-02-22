<?php 

/*
 * Expects JSON input from stdin
 */





error_reporting(E_ALL);                     // we want to know about all problems


require_once("utils.php"); 



$citations = json_decode(file_get_contents("php://stdin"), TRUE);


foreach ($citations as &$citation) { 
    
    $searchDataSource = FALSE; 
    $creators = Array();
    $titles = Array();
    $creatorsSeen = Array();
    $titlesSeen = Array();
    
    
    // first choice - data from Alma Marc record 
    if (isset($citation["Leganto"]["secondary_type"]["value"]) && $citation["Leganto"]["secondary_type"]["value"]=="BK"
        && isset($citation["Leganto"]["metadata"]["mms_id"]) && $citation["Leganto"]["metadata"]["mms_id"]
        ) {
        
        $searchDataSource = "Alma";
        
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

        
        
        // second choice - data already parsed from Scopus
        } else if (isset($citation["Scopus"]["result-count"]) && $citation["Scopus"]["result-count"]
            && isset($citation["Scopus"]["first-match"]) && isset($citation["Scopus"]["first-match"]["authors"]) && count($citation["Scopus"]["first-match"]["authors"])
            && isset($citation["Scopus"]["results"]) && isset($citation["Scopus"]["results"][0]) && isset($citation["Scopus"]["results"][0]["dc:title"]) && $citation["Scopus"]["results"][0]["dc:title"]
            ) {
                
                $searchDataSource = "Scopus";
                
                $creatorsScopus = $citation["Scopus"]["first-match"]["authors"];
                
                if ($creatorsScopus) {
                    foreach ($creatorsScopus as $creatorScopus) {
                        $creator = Array();
                        if (isset($creatorScopus["ce:surname"]) && $creatorScopus["ce:surname"]) {
                            if (isset($creatorScopus["ce:given-name"]) && $creatorScopus["ce:given-name"]) {
                                $creator["collated"] = $creatorScopus["ce:surname"].", ".$creatorScopus["ce:given-name"];
                                if (isset($creatorScopus["ce:indexed-name"]) && $creatorScopus["ce:indexed-name"]) { 
                                    $creator["a"] = $creatorScopus["ce:indexed-name"];
                                } else if (isset($creatorScopus["ce:initials"]) && $creatorScopus["ce:initials"]) { 
                                    $creator["a"] = $creatorScopus["ce:surname"].", ".$creatorScopus["ce:initials"];
                                } else {
                                    // won't try setting "a" version of name in this case 
                                }
                            } else if (isset($creatorScopus["ce:initials"]) && $creatorScopus["ce:initials"]) { 
                                $creator["collated"] = $creatorScopus["ce:surname"].", ".$creatorScopus["ce:initials"];
                                if (isset($creatorScopus["ce:indexed-name"]) && $creatorScopus["ce:indexed-name"]) {
                                    $creator["a"] = $creatorScopus["ce:indexed-name"];
                                } else {
                                    // won't try setting "a" version of name in this case
                                }
                            } else {
                                if (isset($creatorScopus["ce:indexed-name"]) && $creatorScopus["ce:indexed-name"]) {
                                    $creator["collated"] = $creatorScopus["ce:indexed-name"];
                                    // won't try setting "a" version of name in this case
                                } else {
                                    //TODO we are assuming that at least one of the above conditions will apply at least once for each citation
                                }
                            }
                        } else if (isset($creatorScopus["ce:indexed-name"]) && $creatorScopus["ce:indexed-name"]) {
                            $creator["collated"] = $creatorScopus["ce:indexed-name"];
                            // won't try setting "a" version of name in this case
                        } else {
                            //TODO we are assuming that at least one of the above conditions will apply at least once for each citation
                        }
                            
                        if (count($creator)>0) {
                            $creatorScopusSerialised = print_r($creator, TRUE);
                            if (!in_array($creatorScopusSerialised, $creatorsSeen)) {
                                $creators[] = $creator;
                                $creatorsSeen[] = $creatorScopusSerialised;
                            }
                        }
                    }
                }
                
                $titleLeganto = Array("collated"=>$citation["Scopus"]["results"][0]["dc:title"]);
                $titlerLegantoSerialised = print_r($titleLeganto, TRUE);
                if (isset($titleLeganto["collated"]) && $titleLeganto["collated"] && !in_array($titlerLegantoSerialised, $titlesSeen)) {
                    $titles[] = $titleLeganto;
                    $titlesSeen[] = $titlerLegantoSerialised;
                }

                
                
        
    // third choice - data from Leganto
        } else if (isset($citation["Leganto"]["secondary_type"]["value"]) && in_array($citation["Leganto"]["secondary_type"]["value"], Array("CR", "BK", "WS", "CONFERENCE", "E_BK", "E_CR", "OTHER"))
        && isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) {
            
        $searchDataSource = "Leganto";
                
        $creatorsLeganto = preg_split('/(\s*;\s*|\s+and\s+|\s+&\s+)/', $citation["Leganto"]["metadata"]["author"]); // separate multiple authors 
                
        if ($creatorsLeganto) {
            foreach ($creatorsLeganto as $creatorLeganto) {
                $creatorLeganto = Array("collated"=>$creatorLeganto);
                $creatorLegantoSerialised = print_r($creatorLeganto, TRUE);
                if (isset($creatorLeganto["collated"]) && $creatorLeganto["collated"] && !in_array($creatorLegantoSerialised, $creatorsSeen)) {
                    $creators[] = $creatorLeganto;
                    $creatorsSeen[] = $creatorLegantoSerialised;
                }
            }
        }
                
        if (in_array($citation["Leganto"]["secondary_type"]["value"], Array("CR", "E_CR"))) {
            $legantoTitleField = "article_title";
        } else {
            $legantoTitleField = "title";
        }
        if (isset($citation["Leganto"]["metadata"][$legantoTitleField]) && $citation["Leganto"]["metadata"][$legantoTitleField]) {
            $titleLeganto = Array("collated"=>$citation["Leganto"]["metadata"][$legantoTitleField]);
            $titlerLegantoSerialised = print_r($titleLeganto, TRUE);
            if (isset($titleLeganto["collated"]) && $titleLeganto["collated"] && !in_array($titlerLegantoSerialised, $titlesSeen)) {
                $titles[] = $titleLeganto;
                $titlesSeen[] = $titlerLegantoSerialised;
            }
        }
                

                
    }

    if ($searchDataSource && count($creators) && count($titles)) {
        
        $citation["VIAF"] = Array(); // to populate
        
        foreach ($creators as &$creator) {
            
            usleep(250000);
            
            $citationViaf = Array();
            
            $citationViaf["search-fields"] = "local.names+exact";
            $citationViaf["search-pref"] = 1; 
            $citationViaf["data-source"] = $searchDataSource;
            $citationViaf["search-term-source"] = "collated";
            
            $citationViaf["search-term"] = $creator[$citationViaf["search-term-source"]];
            
               
            try { 
                $viafSearchData = viafApiQuery($citationViaf["search-fields"], $citationViaf["search-term"]);
                $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
            } catch (Exception $e) { 
                if (!isset($citationViaf["errors"])) {
                    $citationViaf["errors"] = Array(); 
                }
                $citationViaf["records"] = FALSE; 
                $citationViaf["errors"][] = Array("search-fields"=>$citationViaf["search-fields"], "search-term"=>$citationViaf["search-term"], "message"=>$e->getMessage()); 
            }
        
            if (!$citationViaf["records"]) {
                // try again with a slightly more generous search
                $citationViaf["search-fields"] = "local.names+all";
                $citationViaf["search-pref"] = 2;
                try {
                    $viafSearchData = viafApiQuery($citationViaf["search-fields"], $citationViaf["search-term"]);
                    $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                } catch (Exception $e) {
                    if (!isset($citationViaf["errors"])) {
                        $citationViaf["errors"] = Array();
                    }
                    $citationViaf["records"] = FALSE;
                    $citationViaf["errors"][] = Array("search-fields"=>$citationViaf["search-fields"], "search-term"=>$citationViaf["search-term"], "message"=>$e->getMessage());
                }
            }
            if (!$citationViaf["records"]) {
                // try again but only look in the $a for the author
                $citationViaf["search-term-source"] = "a";
                if (isset($creator[$citationViaf["search-term-source"]])) { 
                    $citationViaf["search-term"] = $creator[$citationViaf["search-term-source"]];
                    $citationViaf["search-fields"] = "local.names+exact";
                    $citationViaf["search-pref"] = 3;
                    
                    $viafSearchData = viafApiQuery($citationViaf["search-fields"], $citationViaf["search-term"]);
                    $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                }
            }
            if (!$citationViaf["records"]) {
                // try again with a slightly more generous search
                if (isset($creator[$citationViaf["search-term-source"]])) {
                    $citationViaf["search-fields"] = "local.names+all";
                    $citationViaf["search-pref"] = 4;
                    
                    $viafSearchData = viafApiQuery($citationViaf["search-fields"], $citationViaf["search-term"]);
                    $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                }
            }
            
            
            if ($citationViaf["records"]) {
                
                $viafDataParsed = FALSE;
                $viafBestSimilarity = FALSE; // set to integer 0-100 when we find potential match
                
                $citationViaf["results"] = Array(); 
                
                foreach ($viafSearchData->records->record as $record) {
                    
                    $viafDataParsedItem = Array();
                    
                    $viafCluster = $record->recordData->VIAFCluster;
                    
                    // fetch the first main heading, 
                    // and calculate a similarity score for the best match between main heading and source authors 
                    $authorSimilarity = 0;
                    $foundMainHeading = FALSE; 
                    if ($viafCluster->mainHeadings) {
                        foreach ($viafCluster->mainHeadings->data as $viafHeadingObject) {
                            //TODO is there a better way to identify the most authoritative form of the name?
                            if (!$foundMainHeading) { $viafDataParsedItem["heading"] = $viafHeadingObject->text->__toString(); } 
                            $foundMainHeading = TRUE; 
                            $authorSimilarity = max($authorSimilarity, similarity($viafHeadingObject->text->__toString(), $creator["collated"], "Levenshtein", FALSE, TRUE)); 
                            $authorSimilarity = max($authorSimilarity, similarity($viafHeadingObject->text->__toString(), $creator["collated"], "Levenshtein", FALSE, TRUE));
                        }
                    }
                    // we'll add the author similarity a little lower, so it is close to the title similarity 
                    
                    $citationViafResult = Array(); 
                    if (isset($viafDataParsedItem["heading"])) { 
                        $citationViafResult["heading"] = $viafDataParsedItem["heading"]; 
                    }
                    $citationViaf["results"][] = $citationViafResult;
                    
                    if ($viafCluster->Document) {
                        $viafDataParsedItem["about"] = $viafCluster->Document["about"]->__toString();
                    }
                    
                    $viafDataParsedItem["similarity-title"] = FALSE; // will populate below
                    if ($foundMainHeading) {
                        $viafDataParsedItem["similarity-author"] = $authorSimilarity; 
                    }
                    
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
                    $viafCountryData = $viafCluster->countries ? $viafCluster->countries->data : Array();
                    foreach ($viafCountryData as $viafCountryDataItem) {
                        $viafCountry = trim($viafCountryDataItem->text->__toString());
                        if ($viafCountry) {
                            if (!isset($viafDataParsedItem["countriesOfPublication"])) { $viafDataParsedItem["countriesOfPublication"] = Array(); }
                            $viafDataParsedItem["countriesOfPublication"][] = Array("value"=>$viafCountry);
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
                    
                    // cross compare source and target titles and calculate a siilarity score 
                    if (isset($viafDataParsedItem["titles"]) && count($viafDataParsedItem["titles"])) {
                        foreach ($titles as $citationTitle) {
                            if (isset($citationTitle["collated"])) {
                                foreach ($viafDataParsedItem["titles"] as $viafTitle) {
                                    /* 
                                     * possible measures of similarity between source and VIAF title 
                                    $thisSimilarity = similarity($viafTitle, $citationTitle["collated"], "Levenshtein"); 
                                    $thisSimilarity = similarity($viafTitle, $citationTitle["collated"], "similar_text");
                                    $thisSimilarity = similarity($citationTitle["collated"], $viafTitle, "similar_text");
                                    $thisSimilarity = similarity($viafTitle, $citationTitle["collated"], "metaphone");
                                    $thisSimilarity = similarity($viafTitle, $citationTitle["collated"], "Levenshtein", TRUE);
                                    $thisSimilarity = similarity($viafTitle, $citationTitle["collated"], "lcms");
                                    $thisSimilarity = similarity($viafTitle, $citationTitle["collated"], "lcms", TRUE);
                                    */ 
                                    $thisSimilarity = 0; 
                                    if (isset($citationTitle["collated"])) { 
                                        // first try comparing the full source title with the full VIAF title 
                                        $thisSimilarity = similarity($viafTitle, $citationTitle["collated"], "Levenshtein", FALSE);
                                    }
                                    if (isset($citationTitle["a"])) {
                                        // now compare the shorter ($a) source title with the full VIAF title 
                                        $thisSimilarity = max($thisSimilarity, similarity($viafTitle, $citationTitle["a"], "Levenshtein", FALSE));
                                    }
                                    if ($viafBestSimilarity===FALSE || $thisSimilarity>$viafBestSimilarity) {
                                        if ($thisSimilarity>0 || $citationViaf["records"]==1) { // set a higher threshold? 
                                                                                                // and always keep the data if there's only one match 
                                            $viafBestSimilarity = $thisSimilarity;
                                            $viafDataParsedItem["similarity-title"] = $thisSimilarity;
                                            $viafDataParsed = $viafDataParsedItem;
                                        }
                                    }
                                    // special case
                                    if ($thisSimilarity==100) {
                                        break 2; // no point in testing any more, we have a perfect match 
                                    }
                                }
                            }
                        }
                    } else if ($citationViaf["records"]==1) {   // special case - only one result, which has no titles 
                                                                // no choice but to load this though title similarity will zero  
                        $viafDataParsedItem["similarity-title"] = 0;  
                        $viafDataParsed = $viafDataParsedItem;
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




print json_encode($citations, JSON_PRETTY_PRINT);



function viafApiQuery($fields, $term) { 
    
    global $config, $http_response_header; // latter needed to allow curl_get_file_contents to mimic file_get_contents side-effect
    
    $viafSearchURL = "http://viaf.org/viaf/search?query=".$fields."+%22".urlencode($term)."%22&maximumRecords=10&startRecord=1&sortKeys=holdingscount&httpAccept=text/xml";
    $viafSearchResponse = curl_get_file_contents($viafSearchURL);

    
    //TODO error checking
    $viafSearchResponse = preg_replace('/(<\/?)ns\d+:/', "$1", $viafSearchResponse); // kludge - need to parse namespaced document properly
    try { 
        return new SimpleXmlElement($viafSearchResponse);
    }
    catch (Exception $e) { 
        throw new Exception("Could not parse response: $viafSearchResponse"); 
    }
}




?>