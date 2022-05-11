<?php 

/**
 * 
 * =======================================================================
 * 
 * Script to process multiple modules and reading lists using the 
 * individual scripts in this folder  
 * 
 * 
 * 
 * The original reason for creating this script was to allow the processing 
 * of reading lists for multiple modules without running into memory problems. 
 * 
 * This script uses "system()" to call all the individual scripts rather than 
 * the more natural "include" so as to avoid memory leaks building up. 
 * 
 * It allows individual steps to be specified e.g. to allow reports to be 
 * re-exported without re-running the whole process.     
 * 
 * =======================================================================
 * 
 * Input: 
 * 
 * List of modcodes in -m option 
 * or in file specified by -f option 
 * 
 * Optional list of steps to run 
 * 
 * Output: 
 * 
 * Progress report to console 
 * Intermediate .json files in Data and Data/tmp folders 
 * Final output summary.csv, LISTCODE.csv and LISTCODE_LONG.csv files in Data folder 
 * 
 * =======================================================================
 *
 * Typical usages:
 *  
 * php batch.php -m "PSYC3505,"HPSC2400" 
 * php batch.php -f Config/Modules/modcodes.txt 
 * php batch.php -f Config/Modules/modcodes.txt -s get,alma 
 * php batch.php -f Config/Modules/modcodes.txt -s scopus,wos 
 * php batch.php -f Config/Modules/modcodes.txt -s viaf
 * php batch.php -f Config/Modules/modcodes.txt -s export  
 *  
 *  
 * NB 
 *  - the steps (-s option) are: get,alma,scopus,wos,viaf,export
 *  - if both -m and -f are specified, -f is ignored   
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
 * longExport.php 
 * 
 * 
 * =======================================================================
 * 
 * 
 * 
 * 
 * !! Gotchas !!  
 * 
 * system() must be enabled in php.ini 
 * (check "system" does not appear in entry "disable_functions") 
 * 
 * When individual steps are specified, starting point for each module is the 
 * contents of the Data/MODCODE.json file. In some cases this might not be appropriate 
 * e.g. 
 *  - If picking up after a failed step, these files may be missing or contain 
 * invalid JSON. 
 *  - If going back and re-running an earlier step the files may already contain 
 *    unwanted data that is not overwritten and pollutes the final result. 
 * In general, unless running the steps in the normal order without encountering 
 * problems, it may be safer to start over from the "get" step, which will regenerate 
 * the Data/MODCODE.json file. 
 *   
 *   
 *   
 *   
 *   
 *   
 */


error_reporting(E_ALL);                     // we want to know about all problems

require_once("utils.php");                  // Helper functions 



// OPTIONS 

$shortopts = 'm:f:s:';
$options = getopt($shortopts);
// defaults
$modcodes = FALSE;
$modulesToInclude = Array(); 
$steps = FALSE; 
$stepsToInclude = Array();
$allSteps = Array("get", "alma", "scopus", "wos", "viaf", "export");

if (isset($options['m']) && $options['m']) {
    $modcodes = $options['m'];
} else if (isset($options['f']) && $options['f']) {
    $modcodes = file_get_contents($options['f']);
}
if (isset($options['s']) && $options['s']) {
    $steps = $options['s'];
}
if ($modcodes) {
    foreach (preg_split('/\s*[,;:\.\s]+\s*/', $modcodes) as $modcode) {
        if (preg_match('/\w+/', $modcode)) {
            $modulesToInclude[] = $modcode;
        }
    }
}
if (!count($modulesToInclude)) {
    trigger_error("Error: Must specify module codes in -m option, or in file in -f option", E_USER_ERROR);
    exit;
}
if ($steps) {
    // by default, every step is not happening 
    foreach ($allSteps as $step) { 
        $stepsToInclude[strtolower($step)] = FALSE;
    }
    // unless we ask it to 
    foreach (preg_split('/\s*[,;:\.\s]+\s*/', $steps) as $step) {
        if (preg_match('/\w+/', $steps)) {
            $stepsToInclude[strtolower($step)] = TRUE;
        }
    }
} else { 
    // every step *is* happening
    foreach ($allSteps as $step) {
        $stepsToInclude[strtolower($step)] = TRUE;
    }
}






// MAIN PROCESSING 

print "\nProcessing: ".implode(",", $modulesToInclude)."\n\n\n"; 


// ACTIONS BEFORE STARTING INDIVIDUAL MODULES 

if ($stepsToInclude["export"]) {  
    print "Initialising summary file\n\n"; 
    $resultCode = 0; 
    system("php simpleExport.php -i", $resultCode); // initialise a summary file with header row (will contain a row-per-reading-list)  
    if ($resultCode) { print "\nScript simpleExport.php ended with error\n"; exit; }
}


foreach ($modulesToInclude as $modcode) { 

    // ACTIONS FOR AN INDIVIDUAL MODULE 
    
    print "Starting module $modcode\n"; 
    $resultCode = 0;
    
    if ($stepsToInclude["get"]) {
        print "Getting citations by module code\n"; 
        system("php getCitationsByModule.php -m {$modcode} >Data/tmp/{$modcode}_L.json", $resultCode);      // save data in temporary "Leganto" JSON file 
        if ($resultCode) { print "\nScript getCitationsByModule.php ended with error\n"; exit; }
        copy("Data/tmp/{$modcode}_L.json", "Data/{$modcode}.json");                                         // if no errors, copy the "Leganto" JSON file to the current working file for this module  
    }
    
    if ($stepsToInclude["alma"]) {
        print "Enhancing citations from Alma\n";
        if (!file_exists("Data/{$modcode}.json")) {
            print "\nError: input file Data/{$modcode}.json missing: Something may have gone wrong in a previous step?\n";
            exit; 
        }
        system("php enhanceCitationsFromAlma.php <Data/{$modcode}.json >Data/tmp/{$modcode}_A.json", $resultCode);
                                                                                                            // source is current working file, target is temporary "Alma" file 
        if ($resultCode) { print "\nScript enhanceCitationsFromAlma.php ended with error\n"; exit; }
        copy("Data/tmp/{$modcode}_A.json", "Data/{$modcode}.json");                                         // if no errors, copy the "Alma" JSON file over the current working file for this module
    }
    
    if ($stepsToInclude["scopus"]) {
        print "Enhancing citations from Scopus\n";
        if (!file_exists("Data/{$modcode}.json")) {
            print "\nError: input file Data/{$modcode}.json missing: Something may have gone wrong in a previous step?\n";
            exit;
        }
        system("php enhanceCitationsFromScopus.php <Data/{$modcode}.json >Data/tmp/{$modcode}_S.json", $resultCode);
        if ($resultCode) { print "\nScript enhanceCitationsFromScopus.php ended with error\n"; exit; }
        copy("Data/tmp/{$modcode}_S.json", "Data/{$modcode}.json");
    }
    
    if ($stepsToInclude["wos"]) {
        print "Enhancing citations from WoS\n";
        if (!file_exists("Data/{$modcode}.json")) {
            print "\nError: input file Data/{$modcode}.json missing: Something may have gone wrong in a previous step?\n";
            exit;
        }
        system("php enhanceCitationsFromWoS.php <Data/{$modcode}.json >Data/tmp/{$modcode}_W.json", $resultCode);
        if ($resultCode) { print "\nScript enhanceCitationsFromWoS.php ended with error\n"; exit; }
        copy("Data/tmp/{$modcode}_W.json", "Data/{$modcode}.json");
    }
    
    if ($stepsToInclude["viaf"]) {
        print "Enhancing citations from VIAF\n";
        if (!file_exists("Data/{$modcode}.json")) {
            print "\nError: input file Data/{$modcode}.json missing: Something may have gone wrong in a previous step?\n";
            exit;
        }
        system("php enhanceCitationsFromViaf.php <Data/{$modcode}.json >Data/tmp/{$modcode}_V.json", $resultCode);
        if ($resultCode) { print "\nScript enhanceCitationsFromViaf.php ended with error\n"; exit; }
        copy("Data/tmp/{$modcode}_V.json", "Data/{$modcode}.json");
    }
    
    if ($stepsToInclude["export"]) {
        print "Exporting shorter digested data\n";
        if (!file_exists("Data/{$modcode}.json")) {
            print "\nError: input file Data/{$modcode}.json missing: Something may have gone wrong in a previous step?\n";
            exit;
        }
        system("php simpleExport.php -a <Data/{$modcode}.json", $resultCode);   // -a option appends rows to summary file for this module's reading lists  
        if ($resultCode) { print "\nScript simpleExport.php ended with error\n"; exit; }
        print "Exporting longer digested data\n";
        system("php longExport.php <Data/{$modcode}.json", $resultCode);        // no -a option for this script 
        if ($resultCode) { print "\nScript longExport.php ended with error\n"; exit; }
    }
    
    print "Done module $modcode\n\n"; 
    
}


print "All done\n\n\n";





?>