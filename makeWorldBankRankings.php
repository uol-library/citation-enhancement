<?php 

/**
 * Attempts to replicate the World bank ranking file used by 
 * Imperial, from the GNI data downloaded from the World Bank 
 * Downloaded from https://data.worldbank.org/indicator/NY.GNP.PCAP.CD 
 * 2022-02-07
 * 
 * Some open questions e.g. did Imperial use the latest available year by default? 
 * and what did it do if there was no figure for the latest year?     
 * 
 * 
 * 
 */

require_once("utils.php"); 



$worldBankGNIFile = "Config/WorldBank/API_NY.GNP.PCAP.CD_DS2_en_csv_v2_3470973.csv";
$worldBankSummaryFile = "Config/WorldBank/Metadata_Country_API_NY.GNP.PCAP.CD_DS2_en_csv_v2_3470973.csv";

$worldBankGNIYears = Array(1960,2020); // range available in file  

$iso3Map = json_decode(file_get_contents("Config/CountryCodes/iso3.json"), TRUE);
$iso2Map = array_flip($iso3Map);

$worldBankData = Array(); // Associative array of 3-letter code:data  

// Summary 
$worldBankSummaryLines = file($worldBankSummaryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$worldBankSummaryColumnNames = str_getcsv(preg_replace('/^\xef\xbb\xbf/', '', array_shift($worldBankSummaryLines)));



foreach ($worldBankSummaryLines as $worldBankSummaryLine) { 
    $entry = str_getcsv($worldBankSummaryLine);
    if (count($entry)== count($worldBankSummaryColumnNames)) { // ignore comment rows 
        $entry = array_combine($worldBankSummaryColumnNames, $entry); // turn numeric to text column ids
        $worldBankData[$entry["Country Code"]] = $entry;
    }
}

//GNI data 
$worldBankGNILines = file($worldBankGNIFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// throw away top-matter
$worldBankGNILines[0] = preg_replace('/^\xef\xbb\xbf/', '', $worldBankGNILines[0]);


while (!preg_match('/^"?Country Name\b/', $worldBankGNILines[0])) { 
    $junk = array_shift($worldBankGNILines); 
}
$worldBankGNIColumnNames = str_getcsv(array_shift($worldBankGNILines));
foreach ($worldBankGNILines as $worldBankGNILine) {
    $entry = str_getcsv($worldBankGNILine);
    $entry = array_combine($worldBankGNIColumnNames, $entry); // turn numeric to text column ids
    if (!isset($worldBankData[$entry["Country Code"]])) { $worldBankData[$entry["Country Code"]] = Array(); } 
    $worldBankData[$entry["Country Code"]]["Country Name"] = $entry["Country Name"];
    for ($year=$worldBankGNIYears[1]; $year>=$worldBankGNIYears[0]; $year--) { 
        $yearStr = strval($year); 
        if (isset($entry[$yearStr]) && is_numeric($entry[$yearStr])) { 
            $worldBankData[$entry["Country Code"]]["GNI"] = floatval($entry[$yearStr]); 
            $worldBankData[$entry["Country Code"]]["GNI-year"] = $yearStr;
            break; 
        }
    }
}

uasort($worldBankData, function ($a, $b) {
    if (!isset($a["GNI"])) { 
        if (!isset($b["GNI"])) { return 0; } 
        return -1;     
    }
    if (!isset($b["GNI"])) {
        return 1;
    }
    return $a["GNI"] <=> $b["GNI"];
});

// print_r($worldBankData);

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
file_put_contents($worldBankRankFile, $output); // filename set in utils.php

?>