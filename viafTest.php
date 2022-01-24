<?php 

error_reporting(E_ALL);                     // we want to know about all problems

require '../AlmaAPI/private/AlmaAPI/LULAlmaBibs.php';

$bib_endpoint = new LULAlmaBibs('bibs');
// $bib_endpoint->setDebug(TRUE);


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



$citations = Array(); 
$citations[] = Array("mmsid"=>"991017031009705181");

foreach ($citations as &$citation) { 
    
    $bib_record = $bib_endpoint->retrieveBib($citation["mmsid"]);
    // print_r($bib_record);
    
    
    $citation["titles"] = Array();
    $citation["creators"] = Array(); 
    
    $anies = $bib_record["anies"];
    foreach ($anies as $anie) {
        $anie = str_replace("encoding=\"UTF-16\"?>", "encoding=\"UTF-8\"?>", $anie); // kludge
        $xmlrecord = new SimpleXmlElement($anie);
        foreach ($xmlrecord->datafield as $field) {
            if (in_array($field["tag"], Array("245", "210", "240", "242", "243", "246", "247", "730", "740"))) {
                $title = Array();
                $raw = "";
                foreach ($field->subfield as $subfield) {
                    if (in_array($subfield["code"], Array("a","b"))) {
                        $raw .= $subfield->__toString()." ";
                    }
                }
                $title = trim(standardise($raw));
                if ($title) {
                    $citation["titles"][] = $title;
                }
            }
            if (in_array($field["tag"], Array("100", "700"))) {
                $creator = Array();
                $raw = "";
                foreach ($field->subfield as $subfield) {
                    if (in_array($subfield["code"], Array("a","b","c","d","q"))) {
                        $raw .= $subfield->__toString()." ";
                    }
                }
                $creator = trim(standardise($raw));
                if ($creator) {
                    $citation["creators"][] = Array("name"=>$creator);
                }
            }
        }
        
    }
    
    
     
    if (count($citation["creators"])) {
        foreach ($citation["creators"] as &$creator) {
            
            sleep(3);
            
            $creator["viaf"] = NULL; // will populate if we find one  
            
            $viafSearchURL = "http://viaf.org/viaf/search?query=local.names+exact+%22".urlencode($creator["name"])."%22&maximumRecords=10&startRecord=1&sortKeys=holdingscount&httpAccept=text/xml"; 
            $viafSearchResponse = file_get_contents($viafSearchURL);
            
            $viafSearchResponse = preg_replace('/(<\/?)ns2:/', "$1", $viafSearchResponse); // kludge - need to parse namespaced document properly 
            
            $viafSearchData = new SimpleXmlElement($viafSearchResponse);
            $viafCreator["records"] = count($viafSearchData->records->record);
            
            if ($viafCreator["records"]) { 
                
                $viafDataParsed = FALSE;
                $viafBestConfidence = FALSE; // set to integer 0-100 when we find potential match 
                
                foreach ($viafSearchData->records->record as $record) {
                    
                    $viafRecordTitles = Array(); // collect to compare with source title 
                    $viafNationalities = Array(); 
                    $viaf5xxs = Array(); 
                    
                    $viafCluster = $record->recordData->VIAFCluster; 
                    
                    $viafAboutURI = $viafCluster->Document ? $viafCluster->Document["about"]->__toString() : NULL;
                    
                    $viafTitles = $viafCluster->titles; 
                    if ($viafTitles) { 
                        foreach ($viafTitles->work as $work) {
                            $viafRecordTitle = trim($work->title->__toString()); 
                            if ($viafRecordTitle) { 
                                $viafRecordTitles[] = $viafRecordTitle;
                            }
                        }
                    }

                    $viafNatData = $viafCluster->nationalityOfEntity ? $viafCluster->nationalityOfEntity->data : Array(); 
                    foreach ($viafNatData as $viafNatDataItem) {
                        $viafNationality = trim($viafNatDataItem->text->__toString());
                        if ($viafNationality) {
                            $viafNationalities[] = $viafNationality;
                        }
                    }
                    
                    $viaf5xxData = $viafCluster->x500s ? $viafCluster->x500s->x500 : Array();
                    foreach ($viaf5xxData as $viaf5xxDataItem) {
                        $viaf5xx = ""; 
                        foreach ($viaf5xxDataItem->datafield->subfield as $subfield) {
                            $viaf5xx .= "$".$subfield["code"].trim($subfield->__toString());
                        }
                        if ($viaf5xx) {
                            $viaf5xxs[] = $viaf5xx;
                        }
                    }
                    
                    
                    $viafHeadings = $viafCluster->mainHeadings ? $viafCluster->mainHeadings->data : Array();
                    foreach ($viafHeadings as $viafHeadingObject) { 
                        $viafHeading = $viafHeadingObject->text->__toString();
                        break; 
                    }
                    
                    if ($viafTitles) {
                        foreach ($viafTitles->work as $work) {
                            $viafRecordTitle = trim($work->title->__toString());
                            if ($viafRecordTitle) {
                                $viafRecordTitles[] = $viafRecordTitle;
                            }
                        }
                    }
                    
                    // cross compare source and arget titles 
                    foreach ($citation["titles"] as $citationTitle) {
                        foreach ($viafRecordTitles as $viafTitle) { 
                        
                            $thisConfidence = FALSE; 
                            // simple case 
                            if ($viafTitle==$citationTitle) { 
                                $thisConfidence = 100; 
                            } else if (normalise($viafTitle)==normalise($citationTitle)) { 
                                $thisConfidence = 90;
                            } else if (simplify($viafTitle)==simplify($citationTitle)) {
                                $thisConfidence = 75;
                            }
                            if ($thisConfidence!==FALSE) {
                                if ($viafBestConfidence===FALSE || $thisConfidence>$viafBestConfidence) { 
                                    $viafBestConfidence = $thisConfidence;
                                    $viafDataParsed = Array(); // reset 
                                    $viafDataParsed["heading"] = $viafHeading; 
                                    $viafDataParsed["about"] = $viafAboutURI; 
                                    $viafDataParsed["confidence-title"] = $thisConfidence;
                                    // $viafDataParsed["titles"] = $viafRecordTitles; 
                                    $viafDataParsed["nationalities"] = $viafNationalities;
                                    $viafDataParsed["5xx"] = $viaf5xxs;
                                    // etc 
                                }
                            }
                            // special case 
                            if ($thisConfidence==100) { 
                                break 2; // no point in testing any more  
                            }
                        }
                    }
                    
                }
                
                if ($viafDataParsed) { 
                    $viafCreator["best-match"] = $viafDataParsed; // the best we found so far
                }
                $creator["viaf"] = $viafCreator;
                
            }

        }
    }
    
    
}
    

print_r($citations); 

?>