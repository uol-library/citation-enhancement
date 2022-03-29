<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to export reading list author affiliation data to a CSV file 
 * for Library staff to do further processing   
 * 
 * =======================================================================
 * 
 * Input: 
 * JSON-encoded list of citations on STDIN 
 * 
 * Output: 
 * CSV-format table of citations with affiliation data 
 * 
 * =======================================================================
 *
 * Typical usage: 
 * php simpleCsvExport.php <Data/4.json >Data/5.csv 
 * 
 * The input citation data is assumed to already contain data from Leganto, Alma, Scopus and VIAF  
 * 
 * See getCitationsByCourseAndList.php, enhanceCitationsFromAlma.php 
 * enhanceCitationsFrom Scopus.php and enhanceCitationsFromViaf.php for how this data is prepared  
 * 
 * The script also assumes a World Bank ranking file is present which has been generated by 
 * makeWorldBankRankings.php  
 * 
 * 
 * =======================================================================
 * 
 * General process: 
 * 
 * Make an empty result set to popuplate 
 * 
 * Loop over citations - for each citation:  
 *  - Assemble the relevant data from the different sources
 *  - Calculate a numeric CSI for the citation
 *  - Add the resulting record to the result set   
 *    
 * Export the result set as JSON 
 * 
 * =======================================================================
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * The output CSV file is (like all the other data in this project) UTF-8-encoded
 * But Excel expects ANSI-encoded CSV files and will not open files as UTF-8 
 * So special characters hash in Excel 
 * This does not matter in development but 
 * TODO: We need a robust way to export UTF-8 data ina form that Excel will open 
 * e.g. use a software library to export an .xlsx file directly  
 * 
 * Different sources variously use ISO-2-letter country codes, ISO-3-letter country codes and country names 
 * During the earlier stages of the process (enhanceCitations...) we simply take the data exactly as provided 
 * During this CSV-export process we have to convert everything to a single standard format 
 * Currently we are using ISO-2-letter codes but this could change 
 * For conversion we use JSON mapping tables downloaded from http://country.io/data/ and saved in Config/CountryCodes 
 * Some sources have some records with error or placeholder codes (e.g. "XX") and some have free-text country names in 
 * other languages - currently we ignore anything we cannot recognise  
 * TODO: For a production service we may want to build config data with e.g. translations of country names from other languages
 * 
 * Some affiliation data does not contain country codes or names e.g. the 5xx fields in VIAF might contain a city or an institution 
 * We currently are not using it but 
 * TODO: in a production system we might want to look at whether we can lookup institutions and find their countries 
 *    
 *    
 * 
 * 
 * 
 */


error_reporting(E_ALL);                     // we want to know about all problems


require_once("utils.php"); 

$outFormat = "TXT"; 

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
// World Bank country code aliases 
$worldBankAlias = json_decode(file_get_contents("Config/WorldBank/alias.json"), TRUE); 
foreach ($worldBankAlias as $source=>$target) {
    if (!isset($worldBankRank[$source])) { // only alias if we really don't have it  
        if ($target===FALSE) { 
            $worldBankRank[$source] = FALSE; // special case - FALSE in alias file means we know we don't have it 
        } else { 
            $worldBankRank[$source] = $worldBankRank[$target];
        }
    }
} 



$citations = json_decode(file_get_contents("php://stdin"), TRUE);

$outputRecords = Array(); 
// $rowHeadings = Array("TYPE", "TITLE", "AUTHOR", "TAGS", "NATIONALITIES", "CONTINENTS", "SOURCES", "CSI", "CSI-AUTHORS", "CSI-SUM");   
// $rowHeadings = Array("CIT-TYPE", "CIT-TITLE", "CIT-CONTAINER", "CIT-AUTHOR", "EXT-SOURCE", "EXT-SEARCH-USING", "SIMILARITY", "EXT-AUTHOR", "EXT-AUTHOR-LOC-TYPE", "EXT-AUTHOR-LOC");
$rowHeadings = Array("CIT-TYPE", "CIT-TITLE", "CIT-CONTAINER", "CIT-AUTHOR", "EXT-SOURCE", "EXT-SEARCH-USING", "EXT-SEARCH-FIELD", "EXT-AUTHOR", "SIMILARITY", "EXT-AUTHOR-LOC-TYPE", "EXT-AUTHOR-LOC");
// $rowHeadings = Array("TYPE", "TITLE", "CONTAINER-TITLE", "AUTHOR", "TAGS", "NATIONALITIES", "CSI");

foreach ($citations as $citation) { 
    
    if (isset($citation["Leganto"]) && $citation["Leganto"]["secondary_type"]["value"]!="NOTE") {
        
    $outputRecordBase = Array();    // base information used by all lines  
    $outputRecordBaseEmpty = Array(); 
    
    if (!isset($citation["Leganto"])) {
        trigger_error("Cannot export data if no Leganto data in source", E_USER_ERROR);
    } 
    if (isset($citation["Leganto"]["secondary_type"])) { 
        $outputRecordBase["CIT-TYPE"] = $citation["Leganto"]["secondary_type"]["desc"];
        $outputRecordBaseEmpty["CIT-TYPE"] = ""; 
    }
    if (isset($citation["Leganto"]["metadata"]["title"])) {
        $outputRecordBase["CIT-TITLE"] = $citation["Leganto"]["metadata"]["title"];
        $outputRecordBaseEmpty["CIT-TITLE"] = "";
    } else if (isset($citation["Leganto"]["metadata"]["journal_title"])) {
        $outputRecordBase["CIT-TITLE"] = $citation["Leganto"]["metadata"]["journal_title"];
        $outputRecordBaseEmpty["CIT-TITLE"] = "";
    }
    if (isset($citation["Leganto"]["metadata"]["article_title"])) {
        if (isset($outputRecordBase["CIT-TITLE"])) { 
            $outputRecordBase["CIT-CONTAINER"] = $outputRecordBase["CIT-TITLE"]; 
            $outputRecordBaseEmpty["CIT-CONTAINER"] = "";
        } 
        $outputRecordBase["CIT-TITLE"] = $citation["Leganto"]["metadata"]["article_title"];
        $outputRecordBaseEmpty["CIT-TITLE"] = "";
    } else if (isset($citation["Leganto"]["metadata"]["chapter_title"])) {
        if (isset($outputRecordBase["CIT-TITLE"])) { 
            $outputRecordBase["CIT-CONTAINER"] = $outputRecordBase["CIT-TITLE"]; 
            $outputRecordBaseEmpty["CIT-CONTAINER"] = "";
        }
        $outputRecordBase["CIT-TITLE"] = $citation["Leganto"]["metadata"]["chapter_title"];
        $outputRecordBaseEmpty["CIT-TITLE"] = "";
    }
    if (isset($citation["Leganto"]["metadata"]["author"])) {
        $outputRecordBase["CIT-AUTHOR"] = $citation["Leganto"]["metadata"]["author"];
        $outputRecordBaseEmpty["CIT-AUTHOR"] = "";
    }
    
    $generatedScopus = FALSE; 

    if (isset($citation["Scopus"])) {
        
        if (isset($citation["Scopus"]["first-match"]) && isset($citation["Scopus"]["first-match"]["authors"])) {
            
            foreach ($citation["Scopus"]["first-match"]["authors"] as $author) {
                
                if (!$generatedScopus) { 
                    $outputRecord = $outputRecordBase; // we'll assemble author-instance line here
                } else { 
                    $outputRecord = $outputRecordBaseEmpty; // we'll assemble author-instance line here
                }
                $generatedScopus = TRUE; 
                
                $outputRecord["EXT-SOURCE"] = "Scopus";
                $outputRecord["EXT-SEARCH-USING"] = "Leganto";
                $outputRecord["EXT-SEARCH-FIELD"] = preg_replace('/^(\w+).*$/', '$1', $citation["Scopus"]["search-active"]);
                
                // $outputRecord["SIMILARITY"] = floor($citation["Scopus"]["first-match"]["similarity-title"]*$citation["Scopus"]["first-match"]["similarity-authors"]/100);
                if (isset($author["similarity-title"]) && isset($author["similarity-author"])) { 
                    $outputRecord["SIMILARITY"] = floor($author["similarity-title"]*$author["similarity-author"]/100);
                } else {
                    $outputRecord["SIMILARITY"] = 0;
                }
                
                
                $outputRecord["EXT-AUTHOR"] = $author["ce:indexed-name"];
                $outputRecord["EXT-AUTHOR-LOC-TYPE"] = "Contemporary affiliation";
                
                
                $outputRecord["EXT-AUTHOR-LOC"] = Array();
                if (isset($author["affiliation"]) && is_array($author["affiliation"])) {
                    foreach ($author["affiliation"] as $authorAffiliation) {
                        if (isset($authorAffiliation["country"])) { 
                            $outputRecord["EXT-AUTHOR-LOC"][] = $authorAffiliation["country"];
                        }
                    }
                }
                $outputRecords[] = $outputRecord;
                // current affiliation
                $outputRecord = $outputRecordBaseEmpty; // we'll assemble author-instance line here
                $outputRecord = $outputRecordBase; // we'll assemble author-instance line here
                $outputRecord = $outputRecordBaseEmpty; // we'll assemble author-instance line here
                $outputRecord["EXT-SOURCE"] = "";
                $outputRecord["EXT-SEARCH-USING"] = "";
                $outputRecord["EXT-AUTHOR"] = "";
                $outputRecord["EXT-AUTHOR-LOC-TYPE"] = "Current affiliation";
                $outputRecord["EXT-AUTHOR-LOC"] = Array();
                if (isset($author["affiliation-current"]) && is_array($author["affiliation-current"])) {
                    foreach ($author["affiliation-current"] as $authorAffiliation) {
                        if (isset($authorAffiliation["address"]) && $authorAffiliation["address"] && isset($authorAffiliation["address"]["@country"]) && is_array($authorAffiliation["address"]["@country"])) {
                            $outputRecord["EXT-AUTHOR-LOC"][] = $authorAffiliation["address"]["@country"];
                        }
                    }
                }
                $outputRecords[] = $outputRecord;
            }
        }
    }
    }
    
    if (isset($citation["VIAF"])) { 
        foreach ($citation["VIAF"] as $viafCitation) {
            if (isset($viafCitation["best-match"])) {
                // nationality 
                if (!$generatedScopus) {
                    $outputRecord = $outputRecordBase; // we'll assemble author-instance line here
                } else {
                    $outputRecord = $outputRecordBaseEmpty; // we'll assemble author-instance line here
                }
                $outputRecord["EXT-SOURCE"] = "VIAF";
                $outputRecord["EXT-SEARCH-USING"] = $viafCitation["data-source"];
                $outputRecord["EXT-SEARCH-FIELD"] = "AUTHOR";
                
                $outputRecord["SIMILARITY"] = floor($viafCitation["best-match"]["similarity-title"]*$viafCitation["best-match"]["similarity-author"]/100);
                
                
                $outputRecord["EXT-AUTHOR"] = $viafCitation["best-match"]["heading"];
                $outputRecord["EXT-AUTHOR-LOC-TYPE"] = "Nationality";
                $outputRecord["EXT-AUTHOR-LOC"] = Array();
                if (isset($viafCitation["best-match"]["nationalities"]) && is_array($viafCitation["best-match"]["nationalities"])) {
                    foreach ($viafCitation["best-match"]["nationalities"] as $nationality) {
                        $outputRecord["EXT-AUTHOR-LOC"][] = $nationality["value"];
                    }
                }
                $outputRecords[] = $outputRecord; 
                // country-of-publication 
                $outputRecord = $outputRecordBaseEmpty; // we'll assemble author-instance line here
                $outputRecord["EXT-SOURCE"] = "";
                $outputRecord["EXT-SEARCH-USING"] = "";
                $outputRecord["EXT-AUTHOR"] = "";
                $outputRecord["EXT-AUTHOR-LOC-TYPE"] = "Country of publication";
                $outputRecord["EXT-AUTHOR-LOC"] = Array();
                if (isset($viafCitation["best-match"]["countriesOfPublication"]) && is_array($viafCitation["best-match"]["countriesOfPublication"])) {
                    foreach ($viafCitation["best-match"]["countriesOfPublication"] as $nationality) {
                        $outputRecord["EXT-AUTHOR-LOC"][] = $nationality["value"];
                    }
                }
                $outputRecords[] = $outputRecord;
                // 5xx 
                $outputRecord = $outputRecordBase; // we'll assemble author-instance line here
                $outputRecord = $outputRecordBaseEmpty; // we'll assemble author-instance line here
                $outputRecord["EXT-SOURCE"] = "";
                $outputRecord["EXT-SEARCH-USING"] = "";
                $outputRecord["EXT-AUTHOR"] = "";
                $outputRecord["EXT-AUTHOR-LOC-TYPE"] = "Location (551)";
                $outputRecord["EXT-AUTHOR-LOC"] = Array();
                if (isset($viafCitation["best-match"]["locations"]) && is_array($viafCitation["best-match"]["locations"])) {
                    foreach ($viafCitation["best-match"]["locations"] as $affil) {
                        $outputRecord["EXT-AUTHOR-LOC"][] = $affil["value"];
                    }
                }
                $outputRecords[] = $outputRecord;
                // 5xx
                $outputRecord = $outputRecordBase; // we'll assemble author-instance line here
                $outputRecord = $outputRecordBaseEmpty; // we'll assemble author-instance line here
                $outputRecord["EXT-SOURCE"] = "";
                $outputRecord["EXT-SEARCH-USING"] = "";
                $outputRecord["EXT-AUTHOR"] = "";
                $outputRecord["EXT-AUTHOR-LOC-TYPE"] = "Affiliation (510)";
                $outputRecord["EXT-AUTHOR-LOC"] = Array();
                if (isset($viafCitation["best-match"]["affiliations"]) && is_array($viafCitation["best-match"]["affiliations"])) {
                    foreach ($viafCitation["best-match"]["affiliations"] as $affil) {
                        $affilValue = $affil["value"];
                        if (isset($affil['$e']) && $affil['$e']) { 
                            $affilValue .= " [".$affil['$e']."]";
                        }
                        $outputRecord["EXT-AUTHOR-LOC"][] = $affil["value"];
                    }
                }
                $outputRecords[] = $outputRecord;
                
            }
        }
        
    }
    
}





if ($outFormat == "CSV") { 
    $out = fopen('php://output', 'w');
    fputcsv($out, $rowHeadings);
} else if ($outFormat == "TXT") {
    print implode("\t", $rowHeadings)."\n"; 
}

foreach ($outputRecords as $outputRecord) {
    $outputRow = Array(); 
    foreach ($rowHeadings as $rowHeading) {
        $outputField = FALSE; 
        if (!isset($outputRecord[$rowHeading])) {
            $outputField = "";
        } else if (is_array($outputRecord[$rowHeading])) { 
            $outputField = implode("|", $outputRecord[$rowHeading]); 
        } else {
            $outputField = $outputRecord[$rowHeading]; 
        }
        $outputRow[] = $outputField;
    }
    if ($outFormat == "CSV") {
        fputcsv($out, $outputRow);
    } else if ($outFormat == "TXT") {
        print implode("\t", $outputRow)."\n"; 
    }
}

if ($outFormat == "CSV") {
    fclose($out);
} else if ($outFormat == "TXT") {
}





?>