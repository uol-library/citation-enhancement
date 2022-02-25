<?php 


/**
 * Helper utilties for scripts in this project 
 * e.g. string normalisation and comparison 
 *  
 * and a function curl_get_file_contents() to replace the standard PHP file_get_contents() 
 * because lib5hv does not have the http wrappers enabled for file_get_contents  
 * 
 */



$config = parse_ini_file("config.ini", true);       // from now on, keep as much config in this ini file as possible - 
                                                    // all scripts include utils.php so all will have access to 
                                                    // config through $config 



function standardise($string) {
    
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
    $string = standardise($string);
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

function similarity($string1, $string2, $type="Levenshtein", $crop=FALSE, $alphabeticise=FALSE) {

    if ($string1==$string2) { return 100; }
    $string1 = normalise($string1);
    $string2 = normalise($string2);
    if (!$string1 || !$string2) { return 0; }
    
    if ($crop && strlen($string1)!=strlen($string2)) { 
        if (strlen($string1)>strlen($string2)) { 
            $string1 = cropto($string1, strlen($string2)); 
        } else { 
            $string2 = cropto($string2, strlen($string1));
        }
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


function cropto($string, $length) { 
    // can't just use substr because we only want to split at a space or punctuation 
    $left = substr($string, 0, $length); 
    $right = substr($string, $length);
    $right = preg_replace('/^([^\s\.,:;\!\?\-&]*).*$/', '$1', $right);
    return $left.$right; 
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
    
    if ($body) return $body;
    else return FALSE;
    
}



?>