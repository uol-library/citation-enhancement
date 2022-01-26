<?php 

/*
 * Expects JSON input from stdin
 */


error_reporting(E_ALL);                     // we want to know about all problems


// country codes 
$iso3Map = json_decode(file_get_contents("Config/CountryCodes/iso3.json"), TRUE);
$namesMap = json_decode(file_get_contents("Config/CountryCodes/names.json"), TRUE);
$continentMap = json_decode(file_get_contents("Config/CountryCodes/continent.json"), TRUE);
$iso2Map = array_flip($iso3Map); 




$citations = json_decode(file_get_contents("php://stdin"), TRUE);

$outputRecords = Array(); 
$rowHeadings = Array("TYPE", "TITLE", "AUTHOR", "TAGS", "NATIONALITIES", "CONTINENTS");   

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
    
    if (isset($citation["VIAF"])) { 
        foreach ($citation["VIAF"] as $viafCitation) { 
            $nationCodes = Array();
            $continentCodes = Array();
            if (isset($viafCitation["best-match"]) && isset($viafCitation["best-match"]["nationalities"])) {
                foreach ($viafCitation["best-match"]["nationalities"] as $nationality) {
                    $nationalityValue = strtoupper($nationality["value"]);
                    if (strlen($nationalityValue)==2) {
                        if (!in_array($nationalityValue, Array("XX", "ZZ"))) {
                            $nationCodes[] = $nationalityValue;
                        }
                    } else if (strlen($nationalityValue)==3) {
                        if (isset($iso2Map[$nationalityValue]) && $iso2Map[$nationalityValue]) {
                            if (!in_array($iso2Map[$nationalityValue], Array("XX", "ZZ"))) { 
                                $nationCodes[] = $iso2Map[$nationalityValue];
                            }
                        }
                    } else {
                        // ignore these 
                    }
                }
            }
            $nationCodes = array_unique($nationCodes);
            foreach ($nationCodes as $nationCode) {
                if (isset($continentMap[$nationCode]) && $continentMap[$nationCode]) {
                    $continentCodes[] = $continentMap[$nationCode];
                }
            }
            $continentCodes = array_unique($continentCodes);
            
            $outputRecord["NATIONALITIES"][] = implode("+", $nationCodes);
            $outputRecord["CONTINENTS"][] = implode("+", $continentCodes);
        }
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