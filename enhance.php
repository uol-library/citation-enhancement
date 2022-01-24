<?php 

// Import the data from the Leganto Analytics Excel file

// libjmh 20220117 
// need to replace this with https://github.com/PHPOffice/PhpSpreadsheet 
// (PHPExcel is no longer maintained and "must not be used anymore" 
// require_once 'classes/PHPExcel.php';
// https://github.com/PHPOffice/PHPExcel

// libjmh 202201017 
// will import library data from tab-delimitted text for now 


/* 
$excel= PHPExcel_IOFactory::load("Leganto_DATA/Leganto_wos_doi.xlsx");
set_time_limit(0);
// clean up all inputted data
   function test_input($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
//Set active sheet to first sheet
$excel->setActiveSheetIndex(0);
$Excel_Row = test_input(1);
$doi_IDCOL = test_input(A);
$title_IDCOL = test_input(B);
$author_IDCOL = test_input(C);
$pubDate_IDCOL = test_input(D);
$course_IDCOL = test_input(E);
$readingListCode_IDCOL = test_input(F);
$readingListName_IDCOL = test_input(G);
$journal_IDCOL = test_input(H);
$instructor_IDCOL = test_input(I);
$dept_IDCOL = test_input(K);
$status_IDCOL = test_input(L);
$year_IDCOL = test_input(M);    

//First row of data series
$i = $Excel_Row;
*/ 


require_once "classes/IO.php"; 
try { 
    $inputData = IO::importAnalyticsTxt("Data/Leeds_Sample_Data.txt");
} 
catch (Exception $e) { 
    print "====================================================\nERROR:\nCould not import data: ".$e->getMessage()."\n====================================================\n\n"; 
    exit;
}

$rows = Array(); // libjmh

// //Loop until the end of data series(cell contains empty string)
// while($excel->getActiveSheet()->getCell('A'.$i)->getValue()!=""){
foreach ($inputData as $inputRow) { 
    // 0	Author	DOI	Journal Title	Publication Date	Title	Academic Department Description	Course Code	Course Instructor Primary Identifier	Course Status	Course Year	Searchable ID 1	Citation Type	Reading List Code	Reading List Name

    try {
        
        $doi = $inputRow["DOI"];
        $author_Leganto = $inputRow["Author"];
        $pubDate_Leganto = $inputRow["Publication Date"];
        $course_code = $inputRow["Course Code"];
        $instructor = $inputRow["Course Instructor Primary Identifier"];
        $status = $inputRow["Course Status"];
        $year = $inputRow["Course Year"];
        $readingListCode = $inputRow["Reading List Code"];
        $readingListName = $inputRow["Reading List Name"];
        $journal_Leganto = $inputRow["Journal Title"];
        $title_Leganto = $inputRow["Title"];
        $dept = $inputRow["Academic Department Description"];
        $doi = trim($doi);
        
        //Get cells value
        /*
         $doi = $excel->getActiveSheet()->getCell($doi_IDCOL.$i)->getValue();
         $author_Leganto = $excel->getActiveSheet()->getCell($author_IDCOL.$i)->getValue();
         $pubDate_Leganto = $excel->getActiveSheet()->getCell($pubDate_IDCOL.$i)->getValue();
         $course_code = $excel->getActiveSheet()->getCell($course_IDCOL.$i)->getValue();
         $instructor = $excel->getActiveSheet()->getCell($instructor_IDCOL.$i)->getValue();
         $status = $excel->getActiveSheet()->getCell($status_IDCOL.$i)->getValue();
         $year = $excel->getActiveSheet()->getCell($year_IDCOL.$i)->getValue();
         $readingListCode = $excel->getActiveSheet()->getCell($readingListCode_IDCOL.$i)->getValue();
         $readingListName = $excel->getActiveSheet()->getCell($readingListName_IDCOL.$i)->getValue();
         $journal_Leganto = $excel->getActiveSheet()->getCell($journal_IDCOL.$i)->getValue();
         $title_Leganto = $excel->getActiveSheet()->getCell($title_IDCOL.$i)->getValue();
         $dept = $excel->getActiveSheet()->getCell($dept_IDCOL.$i)->getValue();
         $i++;
         $doi = trim($doi);
         */
        
        /*
         *
         * temporarily replace API call with stub (below)
         
         // Build the API URL
         $Request = array ("https://wos-api.clarivate.com/api/wos/?databaseId=WOK&count=1&firstRecord=1&usrQuery=DO=%22".$doi."%22");
         
         $mh = curl_multi_init();
         foreach ($Request as $key => $url) {
         $chs[$key] = curl_init($url);
         curl_setopt($chs[$key], CURLOPT_RETURNTRANSFER, true);
         curl_setopt($chs[$key], CURLOPT_CUSTOMREQUEST, 'GET');
         curl_setopt($chs[$key], CURLOPT_POSTFIELDS, $call);
         curl_setopt($chs[$key], CURLOPT_HTTPHEADER, array("Accept-Encoding: gzip, deflate",
         "Cache-Control: no-cache",
         "Connection: keep-alive",
         "Content-Type: text/xml",
         "Host: wos-api.clarivate.com",
         "X-ApiKey: XXXXXXXXXXXXXXXXXXXX",
         "accept: application/xml",
         "cache-control: no-cache"));
         curl_multi_add_handle($mh, $chs[$key]);
         }
         // Running the request
         $running = null;
         do {curl_multi_exec($mh, $running);}
         while ($running);
         
         // Getting the response
         foreach(array_keys($chs) as $key){
         $error = curl_error($chs[$key]);
         $last_effective_URL = curl_getinfo($chs[$key], CURLINFO_EFFECTIVE_URL);
         $urls = $last_effective_URL;
         $time = curl_getinfo($chs[$key], CURLINFO_TOTAL_TIME);
         $response = curl_multi_getcontent($chs[$key]);  // get results
         curl_multi_remove_handle($mh, $chs[$key]);
         }
         // Close current handler
         curl_multi_close($mh);
         
         */
        
        $response = file_get_contents("Sample Data/exampleWoSEnhancedAPIData.xml"); // stub
        
        // Delay each execution to allow for the API throttle limits
        usleep(700000);
        
        // Clean up the response to remove CDATA before parsing
        $find = array('<![CDATA[<records><REC r_id_disclaimer="ResearcherID data provided by Clarivate Analytics">', "</records>]]>");
        $replace   = array('<records><REC>', "</records>");
        $response = str_replace($find, $replace, $response);
        
        // Load API response as simple xml element
        // original Imperial line generated error when run against sample data:
        // PHP Warning:  SimpleXMLElement::__construct(): namespace error : Namespace prefix xsi on type is not defined
        // $xml = new SimpleXMLElement($response, LIBXML_PARSEHUGE | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOCDATA);
        $response = preg_replace('/(<\/?)[^<>: ]+:/', '$1', $response);    // kludge - remove namespaces
        $xml = new SimpleXMLElement($response, LIBXML_PARSEHUGE | LIBXML_COMPACT | LIBXML_NOCDATA); // libjmh taking warning suppression out
        
        // Set vars to null
        $Name=$Seq=$Full_address=$City=$address_name=$Country=$id=$addr_seq=$daisng_id="";
        
        // Extract required data from the xml
        // foreach($xml->map->map->val->records->REC as $REC){
        foreach($xml->Records->records->REC as $REC){ // libjmh kludge - WoS API sample data doesn't match thie above structure
            $Title= end($REC->static_data->summary->titles->title);
            // $Source=($REC->static_data->summary->titles->title);
            $Source=($REC->static_data->summary->titles->title->content);
            // $Year=($REC->static_data->summary->pub_info[pubyear]);
            $Year=($REC->static_data->summary->pub_info->pubyear); // libjmh
            $Publisher=($REC->static_data->summary->publishers->publisher->names->name->display_name);
            $Doctype=($REC->static_data->summary->doctypes->doctype);
            // $Citations=($REC->dynamic_data->citation_related->tc_list->silo_tc[local_count]);
            $Citations=($REC->dynamic_data->citation_related->tc_list->silo_tc->local_count);
            $WosID=($REC->UID);
            
            // foreach($REC->static_data->fullrecord_metadata->addresses->children() as $address_name) {
            foreach($REC->static_data->fullrecord_metadata->addresses->address_name as $address_name) { // libjmh
                $Full_address.=$address_name->address_spec->full_address."|";
                $City.=$address_name->address_spec->city."|";
                $Country.= $address_name->address_spec->country."|";
            }
            
            // foreach($REC->dynamic_data->cluster_related->identifiers-> children() as $identifier) {
            foreach($REC->dynamic_data->cluster_related->identifiers->identifier as $identifier) { // libjmh
                $id.=  $identifier->type.": ". $identifier->value."|";}
                
                // $string .= ($doi."_!".$author_Leganto."_!".$pubDate_Leganto."_!".$course_code."_!".$instructor."_!".$dept."_!".$status."_!".$year."_!".$readingListCode."_!".$readingListName."_!".$journal_Leganto."_!".$title_Leganto."_!".$Title."_!".$Source."_!".$Year."_!".$Publisher."_!".$Doctype."_!".$Citations."_!".$WosID."_!".$Name."_!".$Seq."_!".$addr_seq."_!".$Full_address."_!".$City."_!".$Country."_!".$id."_!".$daisng_id."●～*");
                // $rows = explode("●～*", $string);
                // $rows[] = ($doi."_!".$author_Leganto."_!".$pubDate_Leganto."_!".$course_code."_!".$instructor."_!".$dept."_!".$status."_!".$year."_!".$readingListCode."_!".$readingListName."_!".$journal_Leganto."_!".$title_Leganto."_!".$Title."_!".$Source."_!".$Year."_!".$Publisher."_!".$Doctype."_!".$Citations."_!".$WosID."_!".$Name."_!".$Seq."_!".$addr_seq."_!".$Full_address."_!".$City."_!".$Country."_!".$id."_!".$daisng_id);
                $rows[] = Array($doi,$author_Leganto,$pubDate_Leganto,$course_code,$instructor,$dept,$status,$year,$readingListCode,$readingListName,$journal_Leganto,$title_Leganto,$Title,$Source,$Year,$Publisher,$Doctype,$Citations,$WosID,$Name,$Seq,$addr_seq,$Full_address,$City,$Country,$id,$daisng_id); // libjmh 
                
                // libjmh commenting out - take outside loops 
                // // Set the Header for the CSV export
                // $headers = array("Leganto Doi", "Leganto Authors", "Leganto Pubdate", "Course code", "Course instructors", "Department", "Course status", "Course year", "Reading list code", "Reading list name", "Leganto Journal title", "Leganto  title", "WoS Title", "WoS journal", "Publication year", "Publisher", "Doc type", "Citations", "WoS ID", "Author name", "Author sequence","Address sequence", "Address", "City", "Country","Item ID" ,"Daisng ID");
            }
    
            // libjmh commenting out - take outside loops
            /*
            // Set the text in title of the final CSV export
            $CSVTitle = "Leganto_wos_doi_OUT";
            // Get date
            $TimeStamp = date('Y-m-d');
            // Define destination folder and CSV file title for writing
            $myfile = fopen("Data/$CSVTitle-$TimeStamp.csv", "w");
            fputcsv($myfile, $headers);
            // Format each row of data in CSV format and output
            foreach ($rows as $line) {
                fputcsv($myfile, explode('_!',$line));
            }
            // Closing the file 
            fclose($myfile);
            */
            print ".";
    }
    
    
    // Skip any bad requests
    /*
     catch (Throwable $t) {continue;}
     echo 'Caught exception: ', $e, "\n";
     error_log($doi." | ", 3, "my-errors.log");
     */
    catch (Exception $e) {
        print "WARNING:\nCould not process data for $doi: ".$e->getMessage()."\n====================================================\n\n";
        exit;
    }
}

print "\n";

// libjmh moved from inside loops 
// Set the Header for the CSV export
$headers = array("Leganto Doi", "Leganto Authors", "Leganto Pubdate", "Course code", "Course instructors", "Department", "Course status", "Course year", "Reading list code", "Reading list name", "Leganto Journal title", "Leganto  title", "WoS Title", "WoS journal", "Publication year", "Publisher", "Doc type", "Citations", "WoS ID", "Author name", "Author sequence","Address sequence", "Address", "City", "Country","Item ID" ,"Daisng ID");
// Set the text in title of the final CSV export
$CSVTitle = "Leganto_wos_doi_OUT";
// Get date
$TimeStamp = date('Y-m-d');
// Define destination folder and CSV file title for writing
$myfile = fopen("Data/$CSVTitle-$TimeStamp.csv", "w");
fputcsv($myfile, $headers);
// Format each row of data in CSV format and output
foreach ($rows as $line) {
    // fputcsv($myfile, explode('_!',$line));
    fputcsv($myfile, $line);
}
// Closing the file
fclose($myfile);


?>      