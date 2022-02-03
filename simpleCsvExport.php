<?php 

/*
 * Expects JSON input from stdin
 */


error_reporting(E_ALL);                     // we want to know about all problems


require_once("utils.php"); 



// country codes 
$iso3Map = json_decode(file_get_contents("Config/CountryCodes/iso3.json"), TRUE);
$namesMap = json_decode(file_get_contents("Config/CountryCodes/names.json"), TRUE);
$continentMap = json_decode(file_get_contents("Config/CountryCodes/continent.json"), TRUE);
$iso2Map = array_flip($iso3Map); 
$namesToCodesMap = array_change_key_case(array_flip($namesMap));




$citations = json_decode(file_get_contents("php://stdin"), TRUE);

$outputRecords = Array(); 
$rowHeadings = Array("TYPE", "TITLE", "AUTHOR", "TAGS", "NATIONALITIES", "CONTINENTS", "SOURCES");   

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
    if (isset($citation["Leganto"]["metadata"]["author"])) {
        $outputRecord["AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
    }
    
    $outputRecord["NATIONALITIES"] = Array();
    $outputRecord["CONTINENTS"] = Array();
    $outputRecord["SOURCES"] = Array();
    
    if (isset($citation["VIAF"])) { 
        $outputRecord["SOURCES"][] = "VIAF"; 
        foreach ($citation["VIAF"] as $viafCitation) { 
            if (isset($viafCitation["best-match"]) && isset($viafCitation["best-match"]["nationalities"])) {
                foreach ($viafCitation["best-match"]["nationalities"] as $nationality) {
                    $nationalityValue = strtoupper($nationality["value"]);
                    if (strlen($nationalityValue)==2) {
                        if (!in_array($nationalityValue, Array("XX", "ZZ"))) {
                            $outputRecord["NATIONALITIES"][] = $nationalityValue;
                        }
                    } else if (strlen($nationalityValue)==3) {
                        if (isset($iso2Map[$nationalityValue]) && $iso2Map[$nationalityValue]) {
                            if (!in_array($iso2Map[$nationalityValue], Array("XX", "ZZ"))) { 
                                $outputRecord["NATIONALITIES"][] = $iso2Map[$nationalityValue];
                            }
                        }
                    } else {
                        // ignore these 
                    }
                }
            }
        }
    }
    if (isset($citation["Scopus"])) {
        $outputRecord["SOURCES"][] = "Scopus";
        if (isset($citation["Scopus"]["first-match"]) && isset($citation["Scopus"]["first-match"]["authors"])) {
            foreach ($citation["Scopus"]["first-match"]["authors"] as $author) {
                if (isset($author["affiliation"]) && isset($author["affiliation"]["country"])) {
                    if (isset($namesToCodesMap[strtolower($author["affiliation"]["country"])])) {
                        $outputRecord["NATIONALITIES"][] = $namesToCodesMap[strtolower($author["affiliation"]["country"])];
                    } else {
                        trigger_error("No country name:code mapping for ".$author["affiliation"]["country"], E_USER_ERROR);
                    }
                }
            }
        }
    }
    
    
    $outputRecord["NATIONALITIES"] = array_unique($outputRecord["NATIONALITIES"]);
    foreach ($outputRecord["NATIONALITIES"] as $nationCode) {
        if (isset($continentMap[$nationCode]) && $continentMap[$nationCode]) {
            $outputRecord["CONTINENTS"][] = $continentMap[$nationCode];
        }
    }
    $outputRecord["CONTINENTS"] = array_unique($outputRecord["CONTINENTS"]);
    
    
    
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