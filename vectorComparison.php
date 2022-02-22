<?php 
/**
 * Tools for converting string data (e.g. lists of countries) to numeric vectors, 
 * and comparing vectors with each other 
 * 
 * 
 */


/**
 * e.g. 
 * 
 * $dictionary = Array("GB", "US", "IT"); // expected country codes 
 * $hash = Array("GB"=>2, "US"=>1, "DK"=>2); 
 * 
 * hashToVector($dictionary, $hash) => Array(2,1,0); // 2 x GB, 1 x US, 0 x IT; DK ignored 
 * 
 * this does not normalise the vector 
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

function cosineDifference($vector1, $vector2) { 
    if (count($vector1) != count($vector2)) { throw new Exception("Cannot compute cosine difference between vectors with different dimensions"); }
    $result = 0; 
    for ($i=0; $i<count($vector1); $i++) { 
        $result += $vector1[$i]*$vector2[$i];
    }
    return $result; 
}




?>