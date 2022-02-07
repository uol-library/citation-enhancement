<?php 

$worldBankRankFile = "Config/WorldBank/rankings.txt"; 

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

function similarity($string1, $string2) {
    if ($string1==$string2) { return 100; }
    $string1 = normalise($string1);
    $string2 = normalise($string2);
    if (!$string1 || !$string2) { return 0; }
    $lev = levenshtein($string1, $string2);
    
    $pc = 100 * (1 - $lev/(strlen($string1)+strlen($string2)));
    
    if ($pc<0) { $pc = 0; }
    if ($pc>100) { $pc = 100; }
    
    return floor($pc);
}



/**
 * file_get_contents lookalike function using cURL
 *
 * @param unknown $URL
 * @return unknown|boolean
 */
function curl_get_file_contents($URL) {
    
    global $http_response_header; // to mimit file_get_contents side-effect
    
    $c = curl_init();
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_HEADER, 1);
    curl_setopt($c, CURLOPT_URL, $URL);
    
    $response = curl_exec($c);
    
    $header_size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    $http_response_header = preg_split('/[\r\n]+/', substr($response, 0, $header_size));
    $body = substr($response, $header_size);
    
    curl_close($c);
    
    if ($body) return $body;
    else return FALSE;
    
}



?>