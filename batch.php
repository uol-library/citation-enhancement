<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to process multiple modules and reading lists using the 
 * individual scripts in this folder  
 * 
 * =======================================================================
 * 
 * Input: 
 * List of modcodes in -m or --modcode option 
 * or in file specified by -f or --file option 
 * 
 * Output: 
 * Progress report to console 
 * Intermediate .json files in Data folder 
 * Final output summary.txt and LISTCODE.txt files in Data folder 
 * 
 * =======================================================================
 *
 * Typical usage: 
 * php batch.php -m "PSYC3505,"HPSC2400" 
 * php batch.php -f MODCODES.txt 
 *  
 * 
 * =======================================================================
 * 
 * General process: 
 * 
 * For each supplied module code, calls in order the scripts: 
 * getCitationsByModule.php 
 * enhanceCitationsFromAlma.php
 * enhanceCitationsFromScopus.php
 * enhanceCitationsFromWoS.php
 * enhanceCitationsFromVIAF.php
 * simpleExport.php 
 * 
 * 
 * =======================================================================
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * 
 * 
 * 
 */


error_reporting(E_ALL);                     // we want to know about all problems

require_once("utils.php");                  // Helper functions 


$shortopts = 'm:f:';
$longopts = array('modcode:file:');
$options = getopt($shortopts,$longopts);
// defaults
$modcodes = FALSE;
$modulesToInclude = Array(); 
// set options
if (isset($options['m']) && $options['m']) {
    $modcodes = $options['m'];
} else if (isset($options['modcode']) && $options['modcode']) {
    $modcodes = $options['modcode'];
} if (isset($options['f']) && $options['f']) {
    $modcodes = file_get_contents($options['f']);
} else if (isset($options['file']) && $options['file']) {
    $modcodes = file_get_contents($options['file']);
}
if ($modcodes) {
    foreach (preg_split('/\s*[,;:\.\s]+\s*/', $modcodes) as $modcode) {
        if (preg_match('/\w+/', $modcode)) {
            $modulesToInclude[] = $modcode;
        }
    }
}
if (!count($modulesToInclude)) {
    trigger_error("Error: Must specify module codes in -m or --modcode option, or in file in -f or --f option", E_USER_ERROR);
    exit;
}


print "\nProcessing: ".implode(",", $modulesToInclude)."\n\n\n"; 

print "Initialising summary file\n\n"; 
$resultCode = 0; 
system("php simpleExport.php -i", $resultCode);
if ($resultCode) { print "Script ended with error\n"; exit; } 

foreach ($modulesToInclude as $modcode) { 
    
    print "Starting module $modcode\n"; 
    $resultCode = 0;
    
    print "Getting citations by module code\n"; 
    system("php getCitationsByModule.php -m $modcode >Data/$modcode.json", $resultCode);
    if ($resultCode) { print "Script ended with error\n"; exit; }
    
    print "Enhancing citations from Alma\n";
    system("php enhanceCitationsFromAlma.php <Data/$modcode.json >Data/$modcode"."_A.json", $resultCode);
    if ($resultCode) { print "Script ended with error\n"; exit; }
    
    print "Enhancing citations from WoS\n";
    system("php enhanceCitationsFromWoS.php <Data/$modcode"."_A.json >Data/$modcode"."_AW.json", $resultCode);
    if ($resultCode) { print "Script ended with error\n"; exit; }
    
    print "Enhancing citations from VIAF\n";
    system("php enhanceCitationsFromViaf.php <Data/$modcode"."_AW.json >Data/$modcode"."_AWV.json", $resultCode);
    if ($resultCode) { print "Script ended with error\n"; exit; }
    
    print "Exporting digested data\n";
    system("php simpleExport.php -a <Data/$modcode"."_AWV.json", $resultCode);
    if ($resultCode) { print "Script ended with error\n"; exit; }
    
    print "Done module $modcode\n\n"; 
    
}

print "All done\n\n\n";





?>