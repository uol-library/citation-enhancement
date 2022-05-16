<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to enhance reading list citations using data from the VIAF API 
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
 * php enhanceCitationsFromViaf.php <Data/1ASW.json >Data/1ASWV.json 
 * 
 * The input citation data is assumed to already contain data from Leganto and Alma *and Scopus* 
 * 
 * See getCitationsByModule.php and enhanceCitationsFromAlma.php 
 * and enhanceCitationsFrom Scopus.php for how this data is prepared  
 * 
 * =======================================================================
 * 
 * General process: 
 * 
 * Loop over citations - for each citation: 
 * 
 *  - Collect metadata that might potentially be useful in a VIAF search, from either Alma or Scopus or Leganto metadata
 *    (Alma is our first-choice source, then Scopus, then Leganto)  
 *  - For some of the sources (Alma and Scopus) we have two forms of each author name 
 *    (e.g. from Alma we have the 100$a as well as the (more qualified) 100$abcdq)  
 *    These two forms are labelled "a" and "collated" in the code
 *  - Search the VIAF API using a names-exact search for the "collated" author 
 *    - If no results, search again, using a (looser) names-all search for the "collated" author
 *    - If still no results, search again a names-exact search for the "a" author
 *    - If still no results, search again a names-all search for the "a" author
 *  - If any results, then go through them: 
 *    - Fetching relevant data including affiliation data from 5xx, Nationalities and Countries of publication 
 *    - Calculate the best similarity between the author name in our citation, and the main headings in the result 
 *    - Calculate the best similarity between the titles in our citation, and the works-by-this-author in the result
 *    - If this title similarity is better than the best so far for this search, save the details to our citation 
 *      (including any error messages from the API, and the similarity scores we have calculated)  
 *    - If this title similarity is 100%, don't check any more results
 *    - NB Unlike with Scopus, we do not just take the first result - we try to find the best (by title)     
 *  
 * Export the enhanced citations 
 * 
 * =======================================================================
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * 
 * The code below includes a small delay (usleep(250000)) between API calls to avoid overloading the service
 * 
 * API calls use the function utils.php:curl_get_file_contents() rather than the more natural file_get_contents  
 * Because the http wrappers for the latter are not enabled on lib5hv, where development has been carried out 
 * 
 * The VIAF API returns data in XML 
 * TODO: can we get data in JSON? 
 * This XML is namespaced e.g. <ns2:VIAFCluster>... 
 * The PHP tool we are using below to parse the data (SimpleXmlElement) cannot handle namespaces 
 * and so the code below strips out "ns2:" etc using a preg_replace 
 * Even though this is fairly safe, we may want a better solution long-term  
 * 
 * NB unlike the Scopus API, this API is *not* limited by IP address and does *not* require a key 
 * So testing during development can be done on any local machine 
 * 
 * 
 * 
 */




error_reporting(E_ALL);                     // we want to know about all problems


//TODO implement a batch-wide cache to reduce unnecessary API calls


require_once("utils.php"); 



// fetch the data from STDIN
$citations = json_decode(file_get_contents("php://stdin"), TRUE);
if ($citations===NULL) {
    trigger_error("Error: No valid data passed to enhacement script: Something may have gone wrong in a previous step?", E_USER_ERROR);
}

// main loop: process each citation
foreach ($citations as &$citation) { 
    
    if (isset($citation["Leganto"])) { // only do any enhancement for entries in the citations file that have an actual list
        
    
    $searchDataSource = FALSE; 
    $creators = Array();
    $titles = Array();
    $primaryTitle = null; 
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
            $firstTitle = null; 
            foreach ($citation["Alma"]["titles"] as $titleAlma) {
                $titleAlmaSerialised = print_r($titleAlma, TRUE);
                if (isset($titleAlma["collated"]) && $titleAlma["collated"] && !in_array($titleAlmaSerialised, $titlesSeen)) {
                    $titles[] = $titleAlma;
                    $titlesSeen[] = $titleAlmaSerialised;
                    if ($firstTitle===null) { $firstTitle = $titleAlma; } 
                    if ($titleAlma["originalTag"]=="245" && $primaryTitle===null) { 
                        $primaryTitle = $titleAlma; 
                    }
                }
            }
            if ($primaryTitle===null) { $primaryTitle = $firstTitle; } 
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
                
                $titleScopus = Array("collated"=>$citation["Scopus"]["results"][0]["dc:title"]);
                $titleScopusSerialised = print_r($titleScopus, TRUE);
                if (isset($titleScopus["collated"]) && $titleScopus["collated"] && !in_array($titleScopusSerialised, $titlesSeen)) {
                    $titles[] = $titleScopus;
                    $titlesSeen[] = $titleScopusSerialised;
                    $primaryTitle = $titleScopus; 
                }

            // third choice - data already parsed from WoS
            } else if (isset($citation["WoS"]["result-count"]) && $citation["WoS"]["result-count"]
                && isset($citation["WoS"]["first-match"]) && isset($citation["WoS"]["first-match"]["metadata"]["authors"]) && count($citation["WoS"]["first-match"]["metadata"]["authors"])
                && isset($citation["WoS"]["first-match"]["metadata"]["title"])
                ) {
                    
                    $searchDataSource = "WoS";
                    
                    $creatorsWoS = $citation["WoS"]["first-match"]["metadata"]["authors"];
                    
                    if ($creatorsWoS) {
                        foreach ($creatorsWoS as $creatorWoS) {
                            $creator = Array();
                            if (isset($creatorWoS["display_name"]) && $creatorWoS["display_name"]) {
                                $creator["collated"] = $creatorWoS["display_name"];
                                if (isset($creatorWoS["wos_standard"]) && $creatorWoS["wos_standard"]) {
                                    $creator["a"] = $creatorWoS["wos_standard"];
                                } 
                            } else if (isset($creatorWoS["full_name"]) && $creatorWoS["full_name"]) {
                                $creator["collated"] = $creatorWoS["full_name"];
                                if (isset($creatorWoS["wos_standard"]) && $creatorWoS["wos_standard"]) {
                                    $creator["a"] = $creatorWoS["wos_standard"];
                                }
                            } else if (isset($creatorWoS["last_name"]) && $creatorWoS["last_name"] && isset($creatorWoS["first_name"]) && $creatorWoS["first_name"]) {
                                $creator["collated"] = $creatorWoS["last_name"].", ".$creatorWoS["first_name"];
                                if (isset($creatorWoS["wos_standard"]) && $creatorWoS["wos_standard"]) {
                                    $creator["a"] = $creatorWoS["wos_standard"];
                                }
                            }
                            
                            if (count($creator)>0) {
                                $creatorWoSSerialised = print_r($creator, TRUE);
                                if (!in_array($creatorWoSSerialised, $creatorsSeen)) {
                                    $creators[] = $creator;
                                    $creatorsSeen[] = $creatorWoSSerialised;
                                }
                            }
                        }
                    }
                    
                    $titleWoS = Array("collated"=>$citation["WoS"]["first-match"]["metadata"]["title"]);
                    $titleWoSSerialised = print_r($titleWoS, TRUE);
                    if (isset($titleWoS["collated"]) && $titleWoS["collated"] && !in_array($titleWoSSerialised, $titlesSeen)) {
                        $titles[] = $titleWoS;
                        $titlesSeen[] = $titleWoSSerialised;
                        $primaryTitle = $titleWoS;
                    }
                    
                
        
    // fourth choice - data from Leganto
        } else if (isset($citation["Leganto"]["secondary_type"]["value"]) && in_array($citation["Leganto"]["secondary_type"]["value"], Array("CR", "BK", "WS", "CONFERENCE", "E_BK", "E_CR", "OTHER"))
        && (
            ( isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"] ) 
            ||
            ( isset($citation["Leganto"]["metadata"]["editor"]) && $citation["Leganto"]["metadata"]["editor"] )
            )
        ) {
            
        $searchDataSource = "Leganto";
            
        $rawCreatorsLeganto = NULL; 
        if (isset($citation["Leganto"]["metadata"]["author"]) && $citation["Leganto"]["metadata"]["author"]) { 
            $rawCreatorsLeganto = $citation["Leganto"]["metadata"]["author"]; 
        } else if (isset($citation["Leganto"]["metadata"]["editor"]) && $citation["Leganto"]["metadata"]["editor"]) {
            $rawCreatorsLeganto = $citation["Leganto"]["metadata"]["editor"];
        }
        $creatorsLeganto = preg_split('/(\s*;\s*|\s+and\s+|\s+&\s+)/', $rawCreatorsLeganto); // separate multiple authors 
                
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
            $titleLegantoSerialised = print_r($titleLeganto, TRUE);
            if (isset($titleLeganto["collated"]) && $titleLeganto["collated"] && !in_array($titleLegantoSerialised, $titlesSeen)) {
                $titles[] = $titleLeganto;
                $titlesSeen[] = $titleLegantoSerialised;
                $primaryTitle = $titleLeganto; 
            }
        }
                

                
    }

    if ($searchDataSource && count($creators) && count($titles)) {
        
        $citation["VIAF"] = Array(); // to populate
        
        
        $searchStrategies = Array(); 
        
        $searchStrategies[] = Array(
            "searches"=>array(
                "AU"=>Array("search-fields"=>"local.mainHeadingEl", "search-relation"=>"all", "search-term-source"=>"collated"),
                "TI"=>Array("search-fields"=>"local.title", "search-relation"=>"all", "search-term-source"=>"collated"),
            ), 
            "search-pref"=>1, 
            "data-source"=>$searchDataSource 
        );
        $searchStrategies[] = Array(
            "searches"=>array(
                "AU"=>Array("search-fields"=>"local.mainHeadingEl", "search-relation"=>"all", "search-term-source"=>"a"),
                "TI"=>Array("search-fields"=>"local.title", "search-relation"=>"all", "search-term-source"=>"a"),
            ),
            "search-pref"=>2,
            "data-source"=>$searchDataSource
        );
        $searchStrategies[] = Array(
            "searches"=>array(
                "AU"=>Array("search-fields"=>"local.mainHeadingEl", "search-relation"=>"all", "search-term-source"=>"collated"),
                "TI"=>Array("search-fields"=>"local.title", "search-relation"=>"any", "search-term-source"=>"collated"),
            ),
            "search-pref"=>3,
            "data-source"=>$searchDataSource
        );
        $searchStrategies[] = Array(
            "searches"=>array(
                "AU"=>Array("search-fields"=>"local.mainHeadingEl", "search-relation"=>"all", "search-term-source"=>"a"),
                "TI"=>Array("search-fields"=>"local.title", "search-relation"=>"any", "search-term-source"=>"collated"),
            ),
            "search-pref"=>4,
            "data-source"=>$searchDataSource
        );
        $searchStrategies[] = Array(
            "searches"=>array(
                "AU"=>Array("search-fields"=>"local.personalNames", "search-relation"=>"all", "search-term-source"=>"collated"),
                "TI"=>Array("search-fields"=>"local.title", "search-relation"=>"any", "search-term-source"=>"collated"),
            ),
            "search-pref"=>5,
            "data-source"=>$searchDataSource
        );
        $searchStrategies[] = Array(
            "searches"=>array(
                "AU"=>Array("search-fields"=>"local.personalNames", "search-relation"=>"all", "search-term-source"=>"a"),
                "TI"=>Array("search-fields"=>"local.title", "search-relation"=>"any", "search-term-source"=>"collated"),
            ),
            "search-pref"=>6,
            "data-source"=>$searchDataSource
        );
        
        
        
        foreach ($creators as &$creator) {

            foreach ($searchStrategies as $searchStrategy) {
                
                $searchStrategyAU = $searchStrategy["searches"]["AU"]; 
                $searchStrategyTI = $searchStrategy["searches"]["TI"];
                
                if (!isset($creator[$searchStrategyAU["search-term-source"]]) || !$creator[$searchStrategyAU["search-term-source"]]) { continue; } // we can't do anything if this strategy uses a field that isn't there 
                
                $citationViaf = $searchStrategy; 
                $citationViaf["search-term-au"] = $creator[$searchStrategyAU["search-term-source"]];
                if ($searchStrategyTI) { 
                    if (!isset($primaryTitle[$searchStrategyTI["search-term-source"]])) {
                        continue; // move to the next search 
                    }
                    $citationViaf["search-term-ti"] = $primaryTitle[$searchStrategyTI["search-term-source"]];
                } else {
                    $citationViaf["search-term-ti"] = null; 
                }
                usleep(150000);
                $viafSearchData = viafApiQuery($searchStrategyAU, $citationViaf["search-term-au"], $searchStrategyTI, $citationViaf["search-term-ti"]);
                $citationViaf["records"] = $viafSearchData->records->record ? count($viafSearchData->records->record) : FALSE;
                if ($citationViaf["records"]) {
                    // first, we need to double-check whether there is at least one personal name record here
                    foreach ($viafSearchData->records->record as $record) {
                        $viafCluster = $record->recordData->VIAFCluster;
                        $viafType = $viafCluster->nameType->__toString();
                        if ($viafType=="Personal") { 
                            break 2;  
                        }
                    }
                }
                
            }
            // we either found a result using one of the search strategies, or they all failed 
            
            if ($citationViaf["records"]) {
                
                // we *did* find something 
                
                $viafDataParsed = FALSE;
                $viafBestSimilarity = FALSE; // set to integer 0-100 when we find potential match
                
                $citationViaf["results"] = Array(); 
                
                foreach ($viafSearchData->records->record as $record) {
                    
                    $viafDataParsedItem = Array();
                    
                    $viafCluster = $record->recordData->VIAFCluster;
                    
                    $viafDataParsedItem["type"] = $viafCluster->nameType->__toString();
                    // fetch the first main heading, 
                    // and calculate a similarity score for the best match between main heading and source authors 
                    $authorSimilarity = 0;
                    $foundMainHeading = FALSE; 
                    if ($viafCluster->mainHeadings) {
                        
                        // Main headings structured 
                        $firstMainHeading = NULL; 
                        $lcMainHeading = NULL; 
                        $allHeadings = Array(); 
                        foreach ($viafCluster->mainHeadings->mainHeadingEl as $viafHeadingObject) {
                            $lcSource = FALSE; // unless we find differently 
                            $thisMainHeadingA = FALSE; 
                            $thisMainHeadingCollated = "";
                            foreach ($viafHeadingObject->sources->s as $source) {
                                if (trim($source->__toString())=="LC") {
                                    $lcSource = TRUE;  
                                }
                            }
                            foreach ($viafHeadingObject->datafield->subfield as $subfield) {
                                $acceptedSubfields = Array("a","b","d","q");
                                if (in_array($subfield["code"], $acceptedSubfields)) {
                                    $thisMainHeadingCollated .= trim($subfield->__toString())." "; 
                                }
                                if ($subfield["code"]=="a") {
                                    $thisMainHeadingA = trim($subfield->__toString()); 
                                    if ($firstMainHeading==NULL) {
                                        $firstMainHeading = $thisMainHeadingA; 
                                    }
                                    if ($lcSource && $lcMainHeading==NULL) { 
                                        $lcMainHeading = $thisMainHeadingA; 
                                    }
                                }
                            }
                            $thisMainHeadingCollated = preg_replace('/^\s+/', '', $thisMainHeadingCollated); 
                            $thisMainHeadingCollated = preg_replace('/\s+$/', '', $thisMainHeadingCollated);
                            $thisMainHeadingCollated = preg_replace('/\s+/', ' ', $thisMainHeadingCollated);
                            
                            $allHeadings[] = $thisMainHeadingCollated; 
                            
                            if (isset($creator["collated"]) && $creator["collated"] && $thisMainHeadingCollated) {
                                $authorSimilarity = max($authorSimilarity, similarity($thisMainHeadingCollated, $creator["collated"], "Levenshtein", FALSE));
                                // for this one we'll do a straight compare but we'll back it up below 
                                // with a comparison cropping to initials and sorting words alphabetically  
                            } 
                            if (isset($creator["a"]) && $creator["a"] && $thisMainHeadingA) {
                                $authorSimilarity = max($authorSimilarity, similarity($thisMainHeadingA, $creator["a"], "Levenshtein", "initials", TRUE));
                            }
                        }
                        if ($lcMainHeading) {
                            // prefer an LC-sourced heading 
                            $foundMainHeading = TRUE; 
                            $viafDataParsedItem["heading"] = $lcMainHeading; 
                        } else if ($firstMainHeading) { 
                            // take the first one in the list 
                            $foundMainHeading = TRUE;
                            $viafDataParsedItem["heading"] = $firstMainHeading; 
                        }
                        $viafDataParsedItem["headings-all"] = $allHeadings; 
                        
                    }
                    // we'll add the author similarity a little lower, so it appears close to the title similarity in the resulting object  
                    
                    $citationViafResult = Array(); 
                    if (isset($viafDataParsedItem["type"])) {
                        $citationViafResult["type"] = $viafDataParsedItem["type"];
                    }
                    if (isset($viafDataParsedItem["heading"])) { 
                        $citationViafResult["heading"] = $viafDataParsedItem["heading"]; 
                    }
                    $citationViaf["results"][] = $citationViafResult;
                    
                    // check it is a person (mainHeadingEl search finds other things as well)
                    if ($viafDataParsedItem["type"]!="Personal") { continue; }
                    
                    
                    if ($viafCluster->Document) {
                        $viafDataParsedItem["about"] = $viafCluster->Document["about"]->__toString();
                    }
                    
                    // placeholder for title similarity and add the author similarity we fetched earlier 
                    $viafDataParsedItem["similarity-title"] = FALSE; // will populate below
                    $viafDataParsedItem["best-matching-title"] = FALSE; // will populate below
                    if ($foundMainHeading) { $viafDataParsedItem["similarity-author"] = $authorSimilarity; } 
                    
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
                                        // try two different comparisons - without and with truncation of longer title at colon 
                                        $thisSimilarity = similarity($viafTitle, $citationTitle["collated"], "Levenshtein");
                                        $thisSimilarity = max($thisSimilarity, similarity($viafTitle, $citationTitle["collated"], "Levenshtein", "colon"));
                                        $thisSimilarity = max($thisSimilarity, similarity($viafTitle, $citationTitle["collated"], "Levenshtein", "post-colon"));
                                    }
                                    if (isset($citationTitle["a"])) {
                                        // now compare the shorter ($a) source title with the full VIAF title 
                                        $thisSimilarity = max($thisSimilarity, similarity($viafTitle, $citationTitle["a"], "Levenshtein"));
                                        $thisSimilarity = max($thisSimilarity, similarity($viafTitle, $citationTitle["a"], "Levenshtein", "colon"));
                                        $thisSimilarity = max($thisSimilarity, similarity($viafTitle, $citationTitle["a"], "Levenshtein", "post-colon"));
                                    }
                                    if ($viafBestSimilarity===FALSE || $thisSimilarity>$viafBestSimilarity) {
                                        if ($thisSimilarity>0 || $citationViaf["records"]==1) { // set a higher threshold? 
                                                                                                // and always keep the data if there's only one match 
                                            $viafBestSimilarity = $thisSimilarity;
                                            $viafDataParsedItem["best-matching-title"] = $viafTitle; 
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
                                                                // provisionally load this though title similarity will zero  
                        $viafDataParsedItem["similarity-title"] = 0;  
                        $viafDataParsed = $viafDataParsedItem;
                    }
                    
                    // filter on similarities?
                    /* 
                    if ( ($viafDataParsedItem["similarity-title"]*$viafDataParsedItem["similarity-author"]/100) < 30 ) { continue; }
                    */ 
                    
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




function viafApiQuery($searchStrategyAU, $searchTermAU, $searchStrategyTI, $searchTermTI) { 

    global $http_response_header; // latter needed to allow curl_get_file_contents to mimic file_get_contents side-effect
    
    $subQuery = $searchStrategyAU["search-fields"]."+".$searchStrategyAU["search-relation"]."+%22".urlencode(str_replace('"', '', $searchTermAU))."%22";
    if ($searchStrategyTI && $searchTermTI) {  
        $subQuery .= "+and+".$searchStrategyTI["search-fields"]."+".$searchStrategyTI["search-relation"]."+%22".urlencode(str_replace('"', '', $searchTermTI))."%22";
    }
    
    $viafSearchURL = "http://viaf.org/viaf/search?query=".$subQuery."&maximumRecords=10&startRecord=1&sortKeys=holdingscount&httpAccept=text/xml";
    
    usleep(50000); // to avoid hitting API too hard 
    
    $viafSearchResponse = curl_get_file_contents($viafSearchURL);
    if(!$viafSearchResponse) { 
        trigger_error("Error: Empty response from VIAF API [".$viafSearchURL."]", E_USER_ERROR);
    }
    
    //TODO more error checking
    
    $viafSearchResponse = preg_replace('/(<\/?)ns\d+:/', "$1", $viafSearchResponse); // kludge - need to parse namespaced document properly
    try {
        return new SimpleXmlElement($viafSearchResponse);
    }
    catch (Exception $e) {
        trigger_error("Error: Could not parse response from VIAF API: ".$viafSearchResponse." [".$viafSearchURL."]", E_USER_ERROR);
    }
    
    
    
}



?>