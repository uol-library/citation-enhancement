<?php 

require_once 'utils.php';

$string1 = "FRENCH, D.A.V.I.D."; 
$string2 = "FRENCH, D"; 

print_r(similarity($string1, $string2, "Levenshtein", "initials")); 





?>