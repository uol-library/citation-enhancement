<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to prepare a file ranking countries by GNI, 
 * to use in later stages of the process   
 * 
 * =======================================================================
 * 
 * Input: 
 * None from STDIN 
 * Country code and World Bank GNI data from files 
 * 
 * Output: 
 * None to STDOUT 
 * Ranking file written to Config/WorldBank folder 
 * 
 * =======================================================================
 *
 * Typical usage: 
 * php makeWorldBankRankings.php 
 * 
 * This script should be run before simpleCsvExport.php is run for the first time 
 * But it does not have to be run every time data is processed - only when the 
 * source World Bank data changes 
 * 
 * =======================================================================
 * 
 * General process: 
 * 
 * Fetch ISO-2- and ISO-3-letter country mappings from config files 
 * Fetch World Bank country summaries from file (location of source file in config.ini) 
 * Enhance this country data with GNI data from file (location of source file in config.ini)
 * Sort the countries by GNI (low to high) and assign each a rank 
 * Export the ranked country data as a tab-delimited text file (location of export file in config.ini) 
 * 
 * =======================================================================
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * World Bank data does not include data for each year for each country - we take the latest data available for each 
 * This may introduce inaccuraies in ranking where a country has no recent data 
 * 
 * To simplify parsing this country-year data, we specify the earliest and latest year we will consider 
 * in config.php 
 * 
 * There may be countries/territories which exist in our bibliographic data but not in the World Bank data 
 * e.g. NF (Norfolk Island) 
 * For now, we have a manually-maintained file Config/WorldBank/alias.json 
 * Which maps codes absent from World Bank data onto alternative codes which *are* present 
 *
 * 
 * 
 */


require_once("utils.php"); 

$iso3Map = json_decode(file_get_contents("Config/CountryCodes/iso3.json"), TRUE);
$iso2Map = array_flip($iso3Map);

$worldBankData = Array(); // Associative array of 3-letter code:data  

// Collect data from summary file  
$worldBankSummaryLines = file($config["World Bank"]["SummaryFile"], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$worldBankSummaryColumnNames = str_getcsv(preg_replace('/^\xef\xbb\xbf/', '', array_shift($worldBankSummaryLines)));
foreach ($worldBankSummaryLines as $worldBankSummaryLine) { 
    $entry = str_getcsv($worldBankSummaryLine);
    if (count($entry)== count($worldBankSummaryColumnNames)) { // ignore comment rows 
        $entry = array_combine($worldBankSummaryColumnNames, $entry); // turn numeric to text column ids
        $worldBankData[$entry["Country Code"]] = $entry;
    }
}

// Now enhance this data with data from the GNI file 

$worldBankGNILines = file($config["World Bank"]["GNIFile"], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// throw away top-matter
$worldBankGNILines[0] = preg_replace('/^\xef\xbb\xbf/', '', $worldBankGNILines[0]);
// Loop through GNI country data 
while (!preg_match('/^"?Country Name\b/', $worldBankGNILines[0])) { 
    $junk = array_shift($worldBankGNILines); 
}
$worldBankGNIColumnNames = str_getcsv(array_shift($worldBankGNILines));
foreach ($worldBankGNILines as $worldBankGNILine) {
    $entry = str_getcsv($worldBankGNILine);
    $entry = array_combine($worldBankGNIColumnNames, $entry); // turn numeric to text column ids
    if (!isset($worldBankData[$entry["Country Code"]])) { $worldBankData[$entry["Country Code"]] = Array(); } 
    $worldBankData[$entry["Country Code"]]["Country Name"] = $entry["Country Name"];
    for ($year=$config["World Bank"]["MaxYear"]; $year>=$config["World Bank"]["MinYear"]; $year--) { 
        $yearStr = strval($year); 
        if (isset($entry[$yearStr]) && is_numeric($entry[$yearStr])) { 
            $worldBankData[$entry["Country Code"]]["GNI"] = floatval($entry[$yearStr]); 
            $worldBankData[$entry["Country Code"]]["GNI-year"] = $yearStr;
            break; 
        }
    }
}

// Sort the data by GNI  
uasort($worldBankData, function ($a, $b) {
    if (!isset($a["GNI"])) { 
        if (!isset($b["GNI"])) { return 0; } 
        return -1;     
    }
    if (!isset($b["GNI"])) {
        return 1;
    }
    return $a["GNI"] < $b["GNI"] ? -1 : ( $a["GNI"] > $b["GNI"] ? 1 : 0 );
});

// Output the data 
$output = "Rank\tCountry Name\tCountry Code [3]\tCountry Code [2]\tIncome Group\tGNI/capita\tLog(GNI/capita)\tGNI Year\n";
$rank = 1; 
foreach ($worldBankData as $countryCode=>$countryWorldBankData) {
    if (isset($countryWorldBankData["GNI"]) && isset($iso2Map[$countryCode])) { 
        $outRow = Array($rank); 
        $outRow[] = isset($countryWorldBankData["Country Name"]) ? $countryWorldBankData["Country Name"] : ""; 
        $outRow[] = $countryCode; 
        $outRow[] = isset($iso2Map[$countryCode]) ? $iso2Map[$countryCode] : "";
        $outRow[] = isset($countryWorldBankData["IncomeGroup"]) ? $countryWorldBankData["IncomeGroup"] : "";
        $outRow[] = $countryWorldBankData["GNI"]; 
        $outRow[] = log10($countryWorldBankData["GNI"]);
        $outRow[] = isset($countryWorldBankData["GNI-year"]) ? $countryWorldBankData["GNI-year"] : "";
        $output .= implode("\t", $outRow)."\n"; 
        $rank++; 
    }
}
file_put_contents($config["World Bank"]["RankFile"], $output); 

?>