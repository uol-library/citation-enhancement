<?php 
/**
 * Tools for converting sets of arbitrary data (e.g. lists of countries) into numeric vectors, 
 * and comparing vectors with each other 
 * 
 * e.g. to allow numeric a measure of similarity between the country-affiliations for a citation 
 * derived from Scopus, with those from WoS or VIAF 
 * 
 * 
 */


/**
 * e.g. 
 * 
 * $dictionary = Array("GB", "US", "IT");               // expected country codes 
 * $hash = Array("GB"=>2, "US"=>1, "DK"=>2);            // actual country codes and counts 
 * 
 * hashToVector($dictionary, $hash) => Array(2,1,0);    // 2 x GB + 1 x US + 0 x IT [ DK is ignored ]  
 * 
 * this does *not* normalise the vector - normaliseVector($vector) can accomplish this 
 *   
 */
function hashToVector($dictionary, $hash) { 
    $result = Array(); 
    foreach ($dictionary as $index=>$entry) { 
        if (isset($hash[$entry])) {
            $result[$index] = $hash[$entry]; 
        } else {
            $result[$index] = 0;
        }
    }
    return $result; 
}

/**
 * Turn any vector into one of unit length 
 * 
 * e.g. normaliseVector(Array(3,4)) => Array(0.6,0.8) 
 * 
 * Useful e.g. for using cosineSimilarity() on vectors created with hashToVector()  
 * 
 */
function normaliseVector($vector) { 
    $sumsq = 0;
    foreach ($vector as $component) { 
        $sumsq += $component*$component; 
    }
    if ($sumsq==0) { throw new Exception("Cannot normalise a zero vector"); }
    
    foreach ($vector as &$component) {
        $component = $component/sqrt($sumsq); 
    }
    
    return $vector; 
    
}

/**
 * Computes cosine simiarity between two vectors
 * to allow us to say how similar two lists are to each other 
 * 
 * Assumes input vectors have already been normalised (e.g. with normaliseVector())  
 * 
 */
function cosineSimilarity($vector1, $vector2) { 
    if (count($vector1) != count($vector2)) { throw new Exception("Cannot compute cosine difference between vectors with different dimensions"); }
    $result = 0; 
    for ($i=0; $i<count($vector1); $i++) { 
        $result += $vector1[$i]*$vector2[$i];
    }
    return $result; 
}




?>