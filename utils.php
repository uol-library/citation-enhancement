<?php 


/**
 * Helper utilties for scripts in this project 
 * e.g. string normalisation and comparison 
 *  
 * and a function curl_get_file_contents() to replace the standard PHP file_get_contents() 
 * because lib5hv does not have the http wrappers enabled for file_get_contents  
 * 
 */



define('CONFIG', parse_ini_file("config.ini", true));       // from now on, keep as much config in this ini file as possible - 
                                                            // all scripts include utils.php so all will have access to 
                                                            // config through CONFIG



function standardise($string) {
    
    if ($string===null) { return $string; }
    
    // https://stackoverflow.com/questions/3635511/remove-diacritics-from-a-string
    // removes diacritics from a string
    $regexp = '/&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml|caron);/i';
    $string = html_entity_decode(preg_replace($regexp, '$1', htmlentities($string)));
    
    $dashes = Array("\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x80\x95");
    $dblQuotes = Array("\xe2\x80\x9c", "\xe2\x80\x9d", "\xc2\xab}", "\xc2\xbb}");
    $quotes = Array("\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\xb9", "\xe2\x80\xba");
    $whitespace = Array("\xc2\xa0}", "\xe2\x80\xa8", "\xe2\x80\xa9", "\xe3\x80\x80"); // any whitespace we need to know about apart from \n\r\t\x20
    
    foreach ($dashes as $character) {
        $string = preg_replace('/'.$character.'/', '-', $string);
    }
    foreach ($dblQuotes as $character) {
        $string = preg_replace('/'.$character.'/', '"', $string);
    }
    foreach ($quotes as $character) {
        $string = preg_replace('/'.$character.'/', '"', $string);
    }
    foreach ($whitespace as $character) {
        $string = preg_replace('/'.$character.'/', '"', $string);
    }
    
    $string = preg_replace('/\xe2\x9c\xb1/', '', $string); // remove heavy asterisks 
  
    $string = preg_replace('/[\t\r\n]/', ' ', $string); // whitespace
    
    $string = preg_replace('/\s*\/\s*$/', "", $string); // trailing /
    $string = trim($string);
    return $string;
}

function normalise($string) {
    
    if ($string===null) { return FALSE; }
    
    $string = standardise($string);
    $string = strtolower($string);
    $string = preg_replace('/[\.,\-_;:\/\\\'"\?!\+\&]/', " ", $string);
    $string = preg_replace('/\s+/', " ", $string);
    $string = trim($string);
    return $string ? $string : FALSE;
}

function simplify($string) {

    if ($string===null) { return FALSE; }
    
    $string = normalise($string);
    if ($string!==FALSE) {
        $parts = explode(" ", $string);
        sort($parts);
        $string = implode(" ", $parts);
    }
    return $string ? $string : FALSE;
}

function similarity($string1, $string2, $type="Levenshtein", $crop=FALSE, $alphabeticise=FALSE) {

    if ($string1===null || $string1===FALSE) { return 0; }
    if ($string2===null || $string2===FALSE) { return 0; }
    
    if ($string1==$string2) {
        return 100; 
    }

    if ($crop && strlen($string1)!=strlen($string2)) {
        if (strlen($string1)>strlen($string2)) {
            $string1 = cropto($string1, $string2, $crop); // $crop may indicate the cropping method
        } else {
            $string2 = cropto($string2, $string1, $crop);
        }
    }
    
    $string1 = normalise($string1);
    $string2 = normalise($string2);

    if (!$string1 || !$string2) { 
        return 0; 
    }
    
    if ($alphabeticise) { 
        $stringArray = explode(" ", $string1); 
        sort($stringArray); 
        $string1 = implode(" ", $stringArray); 
        $stringArray = explode(" ", $string2);
        sort($stringArray);
        $string2 = implode(" ", $stringArray);
    }
    
    if ($type=="Levenshtein") {
        if (strlen($string1)>255 || strlen($string2)>255) {
            // can't do anything about this 
            $type="similar_text"; 
        }
    }
        
    
    if ($type=="Levenshtein") { 
        $lev = levenshtein($string1, $string2);
        // $pc = 100 * (1 - 2*$lev/(strlen($string1)+strlen($string2)));
        $pc = 100 * (1 - $lev/max(strlen($string1),strlen($string2)));
        if ($pc<0) { $pc = 0; }
        if ($pc>100) { $pc = 100; }
        return floor($pc);
    } else if ($type=="similar_text") { 
        $pc = 0;
        similar_text($string1, $string2, $pc); 
        return floor($pc);
    } else if ($type=="metaphone") { 
        $string1Mod = implode(' ', array_map('metaphone', explode(' ', $string1)));
        $string2Mod = implode(' ', array_map('metaphone', explode(' ', $string2)));
        $lev = levenshtein($string1Mod, $string2Mod);
        // $pc = 100 * (1 - 2*$lev/(strlen($string1Mod)+strlen($string2Mod)));
        $pc = 100 * (1 - $lev/max(strlen($string1Mod), strlen($string2Mod)));
        if ($pc<0) { $pc = 0; }
        if ($pc>100) { $pc = 100; }
        return floor($pc);
    } else if ($type=="lcms") { 
        $lcms = getLongestMatchingSubstring($string1, $string2);
        $pc = 100 * (2*strlen($lcms)/(strlen($string1)+strlen($string2)));
        if ($pc<0) { $pc = 0; }
        if ($pc>100) { $pc = 100; }
        return floor($pc);
    }
    
    throw new Exception("Similarity type $type nort implemented"); 
    
}


function cropto($longer, $shorter, $type) { 

    $length = strlen($shorter); 
    
    if ($type=="initials") {
        // e.g. compare "Smith, John David" with "Smith, J.D." 
        if (isSurnameInitials($shorter) && !isSurnameInitials($longer)) {
            $converted = convertSurnameInitials($longer);
            return $converted ? $converted : $longer; 
        } else {
            return $longer; 
        }
    }
        
    
    if ($type=="colon") {
        $stringParts = explode(":", $longer);
        $cropped = $stringParts[0];
        $originalLengthDiff = abs(strlen($longer) - $length); 
        $croppedLengthDiff = abs(strlen($cropped) - $length);
        if ($croppedLengthDiff<$originalLengthDiff) { 
            return $cropped; 
        } else { 
            return $longer;
        }
    }
    
    // else 
    
    // can't just use substr because we only want to split at a space or punctuation 
    $left = substr($longer, 0, $length); 
    $right = substr($longer, $length);
    $right = preg_replace('/^([^\s\.,:;\!\?\-&]*).*$/', '$1', $right);
    return $left.$right; 
}


function isSurnameInitials($name) { 
    // true for "Smith, J.D.", "J D Smith" etc 
    // false for "Davids, John", "O'Connor, Bo" etc 
    
    $name = standardise($name); // varieties of apostrophe, dash, whitespace etc 
    
    if (preg_match('/^[A-Z][^\s,]+[,\s]+([A-Z][,\-\s\.]+)+$/', $name)) {
        // Smith, J.D. 
        return TRUE;
    } else if (preg_match('/^[A-Z][^\s,]+[,\s]+([A-Z][,\-\s\.]+)*[A-Z]$/', $name)) {
        // Smith, J or Smith, J.D
        return TRUE;
    } else if (preg_match('/^([A-Z][,\-\s\.]+)+[A-Z][^\.\s,]+$/', $name)) {
        // J.D.Smith 
        return TRUE;
    }
    
    // else 
    return FALSE; 
}


function convertSurnameInitials($name) {
    // "Smith, John David" => "Smith, J D" 
    // "John David Smith-Jones" => "J D Smith-Jones"
    // unconvertable => FALSE 
    
    $name = standardise($name); // varieties of apostrophe, dash, whitespace etc
    
    $surname = FALSE; 
    $forenames = FALSE; 
    $order = FALSE; 
    if (preg_match('/^([A-Z][^,]+),\s*(.+)$/', $name, $matches)) {
        // Smith-Jones, John David 
        $surname = $matches[1];
        $forenames = $matches[2]; 
        $order = "surname"; 
    } else if (preg_match('/^(.+)\s+([A-Z][^\.,\s]+)$/', $name, $matches)) {
        // John David Smith-Jones
        $surname = $matches[2];
        $forenames = $matches[1];
        $order = "forenames"; 
    }
    if ($surname && $forenames) { 
        $individualForenames = preg_split('/[\s\-\.,]+/', $forenames);
        $individualForeInitials = Array(); 
        foreach ($individualForenames as $individualForename) {
            $individualForeInitials[] = preg_replace('/^([A-Z]).*$/', '$1', $individualForename);  
        }
        $foreInitials = implode(" ", $individualForeInitials);
        if ($order=="surname") { 
            return "$surname, $foreInitials"; 
        } else { 
            return "$foreInitials $surname";
        }
    }
    
    // else
    return FALSE;
}


function getLongestMatchingSubstring($str1, $str2) {
    $len_1 = strlen($str1);
    $longest = '';
    for($i = 0; $i < $len_1; $i++){
        for($j = $len_1 - $i; $j > 0; $j--){
            $sub = substr($str1, $i, $j);
            if (strpos($str2, $sub) !== false && strlen($sub) > strlen($longest)){
                $longest = $sub;
                break;
            }
        }
    }
    return $longest;
}

/**
 * file_get_contents lookalike function using cURL
 *
 * @param unknown $URL
 * @return unknown|boolean
 */
function curl_get_file_contents($URL) {
    
    global $http_response_header; // to mimic file_get_contents side-effect
    
    $c = curl_init();
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_HEADER, 1);
    curl_setopt($c, CURLOPT_URL, $URL);
    
    $response = curl_exec($c);
    
    $header_size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    $http_response_header = preg_split('/[\r\n]+/', substr($response, 0, $header_size));
    $body = substr($response, $header_size);
    
    curl_close($c);
    
    return $body ? $body : FALSE; 
    
}


/**
 * Calculate a CSI (Citation Source Index) from
 * an array of country codes 
 * 
 * Uses essentially the same algorithm as Imperial in their study 
 * except that where an author has multiple affiliations they are 
 * averaged (Imperial only took the first) 
 * 
 * Expects an array of arrays of country codes 
 * e.g. [ [ "GB", "IT"], ["US"] ] means two authors, 
 * first one has dual affiliation (GB and IT) and the second has single affiliation (US)    
 *   
 * Accepts 2-letter country codes only   
 * 
 * Second parameter is mapping from 2-letter codes to GNI ranks 
 *   
 */
function csi($authorAffiliations, $worldBankRank) { 
    
    $allAuthorAffiliationCount = 0; 
    $allAuthorAffiliationRankSum = 0; 
    
    foreach ($authorAffiliations as $authorAffiliation) { 
        $authorAffiliationCount = 0; 
        $authorAffiliationRankSum = 0; 
        foreach ($authorAffiliation as $authorAffiliationInstance) {
            if ($authorAffiliationInstance) {
                if (isset($worldBankRank[$authorAffiliationInstance])) {
                    if ($worldBankRank[$authorAffiliationInstance]!==FALSE) {   // false is a special case - we don't have data but don't want an error 
                        $authorAffiliationCount++; 
                        $authorAffiliationRankSum += $worldBankRank[$authorAffiliationInstance];
                    }
                } else {
                    trigger_error("No World Bank ranking for ".$authorAffiliationInstance, E_USER_ERROR);
                }
                
            }
        }
        if ($authorAffiliationCount) { 
            $allAuthorAffiliationCount++; 
            $allAuthorAffiliationRankSum += ($authorAffiliationRankSum/$authorAffiliationCount); 
        }
    }
    if ($allAuthorAffiliationCount) {
        $allAuthorAffiliationRankAvg = ($allAuthorAffiliationRankSum/$allAuthorAffiliationCount);
        return $allAuthorAffiliationRankAvg/max($worldBankRank); 
    } else {
        return NULL; 
    }
    
    
    
}


function scopusDocType($code) { 
    $scopusDocTypes = Array("ar" => "Article", "ab" => "Abstract Report", "bk" => "Book", "bz" => "Business Article", "ch" => "Book Chapter", "cp" => "Conference Paper", "cr" => "Conference Review", "ed" => "Editorial", "er" => "Erratum", "le" => "Letter", "no" => "Note", "pr" => "Press Release", "re" => "Review", "sh" => "Short Survey");
    $code = strtolower($code); // just in case 
    if (isset($scopusDocTypes[$code])) {
        return $scopusDocTypes[$code]; 
    } else {
        return $code; 
    }
}


?>