<?php 

/*
 * Expects JSON input from stdin
 */


error_reporting(E_ALL);                     // we want to know about all problems


require_once("utils.php"); 
require_once("vectorComparison.php");



// country codes 
$iso3Map = json_decode(file_get_contents("Config/CountryCodes/iso3.json"), TRUE);
$namesMap = json_decode(file_get_contents("Config/CountryCodes/names.json"), TRUE);
$continentMap = json_decode(file_get_contents("Config/CountryCodes/continent.json"), TRUE);
$iso2Map = array_flip($iso3Map); 
$namesToCodesMap = array_change_key_case(array_flip($namesMap));




// World Bank rankings 
$worldBankRank = Array(); 
$worldBankMaxRank = NULL; 
$worldBankRankLines = file($config["World Bank"]["RankFile"], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$columnNames = explode("\t", array_shift($worldBankRankLines));   
foreach ($worldBankRankLines as $worldBankRankLine) { 
    $entry = explode("\t", $worldBankRankLine);
    $entry = array_combine($columnNames, $entry); // turn numeric to text column ids
    if (isset($entry["Country Code [2]"]) && $entry["Country Code [2]"]) {
        $worldBankRank[$entry["Country Code [2]"]] = $entry["Rank"]; 
        if (!$worldBankMaxRank || $entry["Rank"]>$worldBankMaxRank) {
            $worldBankMaxRank = $entry["Rank"]; 
        }
    }
}

$citations = json_decode(file_get_contents("php://stdin"), TRUE);

$outputRecords = Array(); 
$rowHeadings = Array("TYPE", "TITLE", "CONTAINER-TITLE", "AUTHOR", "TAGS", "NATNS-VIAF", "NATNS-SCOPUS", "NATNS-AGREEMENT");

foreach ($citations as $citation) { 
    
    $outputRecord = Array();
    
    if (!isset($citation["Leganto"])) {
        trigger_error("Cannot export data if no Leganto data in source", E_USER_ERROR);
    } 
            
    if (isset($citation["Leganto"]["secondary_type"])) { 
        $outputRecord["TYPE"] = $citation["Leganto"]["secondary_type"]["desc"];
    }
    $outputRecord["TAGS"] = Array(); 
    if (isset($citation["Leganto"]["section_tags"])) {
        foreach ($citation["Leganto"]["section_tags"] as $tag) {
            $outputRecord["TAGS"][] = $tag["desc"];
        }
    }
    if (isset($citation["Leganto"]["citation_tags"])) {
        foreach ($citation["Leganto"]["citation_tags"] as $tag) { 
            $outputRecord["TAGS"][] = $tag["desc"];
        }
    }
    if (isset($citation["Leganto"]["metadata"]["title"])) {
        $outputRecord["TITLE"] = $citation["Leganto"]["metadata"]["title"];
    } else if (isset($citation["Leganto"]["metadata"]["journal_title"])) {
        $outputRecord["TITLE"] = $citation["Leganto"]["metadata"]["journal_title"];
    }
    
    if (isset($citation["Leganto"]["metadata"]["article_title"])) {
        if (isset($outputRecord["TITLE"])) { $outputRecord["CONTAINER-TITLE"] = $outputRecord["TITLE"]; } 
        $outputRecord["TITLE"] = $citation["Leganto"]["metadata"]["article_title"];
    } else if (isset($citation["Leganto"]["metadata"]["chapter_title"])) {
        if (isset($outputRecord["TITLE"])) { $outputRecord["CONTAINER-TITLE"] = $outputRecord["TITLE"]; }
        $outputRecord["TITLE"] = $citation["Leganto"]["metadata"]["chapter_title"];
    }
    
    if (isset($citation["Leganto"]["metadata"]["author"])) {
        $outputRecord["AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
    }
    
    $outputRecord["NATNS-VIAF"] = Array();
    $outputRecord["NATNS-SCOPUS"] = Array();
    $outputRecord["NATNS-VIAF-RAW"] = Array();
    $outputRecord["NATNS-SCOPUS-RAW"] = Array();
    $outputRecord["NATNS-VIAF-HASH"] = Array();
    $outputRecord["NATNS-SCOPUS-HASH"] = Array();
    $outputRecord["NATIONALITIES"] = Array();
    $outputRecord["CONTINENTS"] = Array();
    $outputRecord["SOURCES"] = Array();
    $outputRecord["CSI"] = "";  // default 
    $outputRecord["GNI-RANKS"] = Array();
    
    
    $gniRanks = Array();    // one value for each author - higher values for higher GNI countries 
                            // where an author has more than one affiliation entry is the mean of all the individual ranks 
                            // authors from VIAF and from Scopus count as separate authors (even though they are probably the same) 
                            // so an item with only one author and data from VIAF and from Scopus will have *two* entries 
                            // Scopus affiliation is taken from contemporary affiliation (abstract) where possible
                            // and if not from the current affiliation (profile) 
    
    
    $sources = Array(); 
    if (isset($citation["VIAF"])) { 
        
        $outputRecord["DATA"][] = "VIAF"; 
        
        foreach ($citation["VIAF"] as $viafCitation) { 
            if (isset($viafCitation["best-match"]) && isset($viafCitation["best-match"]["nationalities"])) {
                
                $gniRanksAuthorSource = Array(); // just for this author, this source 
                
                foreach ($viafCitation["best-match"]["nationalities"] as $nationality) {
                    
                    $sources["VIAF"] = TRUE; 
                    
                    $nationalityValue = strtoupper($nationality["value"]);
                    $nationalityCode = NULL; 
                    if (strlen($nationalityValue)==2) {
                        if (!in_array($nationalityValue, Array("XX", "ZZ"))) {
                            $nationalityCode = $nationalityValue;
                        }
                    } else if (strlen($nationalityValue)==3) {
                        if (isset($iso2Map[$nationalityValue]) && $iso2Map[$nationalityValue]) {
                            if (!in_array($iso2Map[$nationalityValue], Array("XX", "ZZ"))) { 
                                $nationalityCode = $iso2Map[$nationalityValue];
                            }
                        }
                    }
                    if ($nationalityCode==NULL && isset($namesToCodesMap[strtolower($nationality["value"])])) {
                        // is a country name
                        $nationalityValue = strtolower($nationality["value"]);
                        if (isset($namesToCodesMap[$nationalityValue])) { 
                            $nationalityCode = $namesToCodesMap[$nationalityValue];
                        }
                    }
                    if ($nationalityCode!==NULL) { 
                        if (isset($worldBankRank[$nationalityCode])) {
                            $gniRanksAuthorSource[] = $worldBankRank[$nationalityCode];
                        } else { 
                            trigger_error("No World Bank ranking for ".$nationalityCode, E_USER_ERROR);
                        }
                        $outputRecord["NATIONALITIES"][] = $nationalityCode;
                        $outputRecord["NATNS-VIAF-RAW"][] = $nationalityCode;
                    } else { 
                        // trigger_error("Can't derive nation code for ".$nationality["value"], E_USER_NOTICE);
                    }
                }
                if (count($gniRanksAuthorSource)) { 
                    $gniRanks[] = array_sum($gniRanksAuthorSource)/count($gniRanksAuthorSource);
                }
            }
        }
    }
    if (isset($citation["Scopus"])) {
        
        
        if (isset($citation["Scopus"]["first-match"]) && isset($citation["Scopus"]["first-match"]["authors"])) {
        
            $outputRecord["DATA"][] = "Scopus";
            
            foreach ($citation["Scopus"]["first-match"]["authors"] as $author) {
                
                $gniRanksAuthorSource = Array(); // just for this author, this source
                $contemporaryAffiliation = FALSE; // set to TRUE if we find one 
                
                if (isset($author["affiliation"]) && is_array($author["affiliation"])) {
                    foreach ($author["affiliation"] as $authorAffiliation) {
                        
                        $sources["Scopus-contemporary"] = TRUE;
                        
                        if (isset($authorAffiliation["country"])) {
                            if (isset($namesToCodesMap[strtolower($authorAffiliation["country"])])) {
                                $nationalityCode = $namesToCodesMap[strtolower($authorAffiliation["country"])];
                                if (isset($worldBankRank[$nationalityCode])) {
                                    $gniRanksAuthorSource[] = $worldBankRank[$nationalityCode];
                                } else {
                                    trigger_error("No World Bank ranking for ".$nationalityCode, E_USER_ERROR);
                                }
                                $outputRecord["NATIONALITIES"][] = $nationalityCode;
                                $outputRecord["NATNS-SCOPUS-RAW"][] = $nationalityCode;
                                $contemporaryAffiliation = TRUE;
                            } else {
                                // trigger_error("Can't derive nation code for ".$authorAffiliation["country"], E_USER_NOTICE);
                            }
                        }
                    }
                }
                if (!$contemporaryAffiliation) { // no contemporary affiliation, try current instead 
                    if (isset($author["affiliation-current"]) && is_array($author["affiliation-current"])) {
                        foreach ($author["affiliation-current"] as $authorAffiliation) {
                            
                            $sources["Scopus-current"] = TRUE;

                            if (isset($authorAffiliation["address"])) {
                                
                                $nationalityCode = NULL;
                                if (isset($authorAffiliation["address"]["@country"])) {
                                    // 3-digit code
                                    $nationalityValue = strtoupper($authorAffiliation["address"]["@country"]); 
                                    if (isset($iso2Map[$nationalityValue]) && $iso2Map[$nationalityValue]) {
                                        if (!in_array($iso2Map[$nationalityValue], Array("XX", "ZZ"))) {
                                            $nationalityCode = $iso2Map[$nationalityValue];
                                        }
                                    }
                                }
                                if ($nationalityCode==NULL && isset($authorAffiliation["address"]["country"])) {
                                    // country name
                                    $nationalityValue = strtolower($authorAffiliation["address"]["country"]);
                                    if (isset($namesToCodesMap[$nationalityValue])) {
                                        $nationalityCode = $namesToCodesMap[$nationalityValue];
                                    }
                                }
                                if ($nationalityCode!==NULL) {
                                    if (isset($worldBankRank[$nationalityCode])) {
                                        $gniRanksAuthorSource[] = $worldBankRank[$nationalityCode];
                                    } else {
                                        trigger_error("No World Bank ranking for ".$nationalityCode, E_USER_ERROR);
                                    }
                                    $outputRecord["NATIONALITIES"][] = $nationalityCode;
                                    $outputRecord["NATNS-SCOPUS-RAW"][] = $nationalityCode;
                                    
                                } else {
                                    // trigger_error("Can't derive nation code for ".$authorAffiliation["address"]["@country"].":".$authorAffiliation["address"]["country"], E_USER_NOTICE);
                                }
                                
                            }
                            
                        }
                    }
                }
                if (count($gniRanksAuthorSource)) {
                    $gniRanks[] = array_sum($gniRanksAuthorSource)/count($gniRanksAuthorSource);
                }
            }
        }
    }
    
    $outputRecord["SOURCES"] = array_keys($sources);
    
    $outputRecord["NATIONALITIES"] = array_unique($outputRecord["NATIONALITIES"]);
    foreach ($outputRecord["NATIONALITIES"] as $nationCode) {
        if (isset($continentMap[$nationCode]) && $continentMap[$nationCode]) {
            $outputRecord["CONTINENTS"][] = $continentMap[$nationCode];
        }
    }
    $outputRecord["CONTINENTS"] = array_unique($outputRecord["CONTINENTS"]);

    foreach (Array("VIAF", "SCOPUS") as $natSource) {
        $nations = array_unique($outputRecord["NATNS-$natSource-RAW"]); 
        sort($nations); 
        foreach ($nations as $nationInstance) { 
            $outputRecord["NATNS-$natSource-HASH"][$nationInstance] = 0;             
        }
        foreach ($outputRecord["NATNS-$natSource-RAW"] as $nationInstance) { 
            $outputRecord["NATNS-$natSource-HASH"][$nationInstance]++; 
        }
        $outputRecord["NATNS-$natSource"] = Array(); 
        foreach ($outputRecord["NATNS-$natSource-HASH"] as $nationInstance=>$count) { 
            $outputRecord["NATNS-$natSource"][] = $nationInstance."[".$count."]"; 
        }
    }
    
    if (count($outputRecord["NATNS-VIAF-RAW"]) && count($outputRecord["NATNS-SCOPUS-RAW"])) { 
        $dictionary = array_unique(array_merge($outputRecord["NATNS-VIAF-RAW"], $outputRecord["NATNS-SCOPUS-RAW"]));
        sort($dictionary);
        $vectorViaf = hashToVector($dictionary, $outputRecord["NATNS-VIAF-HASH"]);
        $vectorScopus = hashToVector($dictionary, $outputRecord["NATNS-SCOPUS-HASH"]);
        $vectorViaf = normaliseVector($vectorViaf);
        $vectorScopus = normaliseVector($vectorScopus);
        $agreement = floor(100*cosineDifference($vectorViaf, $vectorScopus));
        $outputRecord["NATNS-AGREEMENT"] = $agreement;
    } else { 
        $outputRecord["NATNS-AGREEMENT"] = "";
    }
    
    
    
    if (count($gniRanks) && $worldBankMaxRank) {
        $outputRecord["CSI"] = array_sum($gniRanks)/($worldBankMaxRank*count($gniRanks));
        $outputRecord["GNI-RANKS"] = $gniRanks; 
    }
    
    $outputRecords[] = $outputRecord; 
    
}





$out = fopen('php://output', 'w');
fputcsv($out, $rowHeadings);
foreach ($outputRecords as $outputRecord) {
    $outputRow = Array(); 
    foreach ($rowHeadings as $rowHeading) {
        if (!isset($outputRecord[$rowHeading])) {
            $outputRow[] = "";
        } else if (is_array($outputRecord[$rowHeading])) { 
            $outputRow[] = implode("|", $outputRecord[$rowHeading]); 
        } else {
            $outputRow[] = $outputRecord[$rowHeading]; 
        }
    }
    fputcsv($out, $outputRow);
}
fclose($out);





?>