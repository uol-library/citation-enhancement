<php

// Import the data from the Leganto Analytics Excel file

require_once 'classes/PHPExcel.php';
// https://github.com/PHPOffice/PHPExcel

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

//Loop until the end of data series(cell contains empty string)
while($excel->getActiveSheet()->getCell('A'.$i)->getValue()!=""){
try {
    //Get cells value
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

    // Delay each execution to allow for the API throttle limits
    usleep(700000); 

    // Clean up the response to remove CDATA before parsing
    $find = array('<![CDATA[<records><REC r_id_disclaimer="ResearcherID data provided by Clarivate Analytics">', "</records>]]>");
    $replace   = array('<records><REC>', "</records>");
    $response = str_replace($find, $replace, $response);

    // Load API response as simple xml element 
    $xml = new SimpleXMLElement($response, LIBXML_PARSEHUGE | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOCDATA);
    
    // Set vars to null
    $Name=$Seq=$Full_address=$City=$address_name=$Country=$id=$addr_seq=$daisng_id="";

    // Extract required data from the xml 
    foreach($xml->map->map->val->records->REC as $REC){              
        $Title= end($REC->static_data->summary->titles->title);
        $Source=($REC->static_data->summary->titles->title);
        $Year=($REC->static_data->summary->pub_info[pubyear]);
        $Publisher=($REC->static_data->summary->publishers->publisher->names->name->display_name);
        $Doctype=($REC->static_data->summary->doctypes->doctype);
        $Citations=($REC->dynamic_data->citation_related->tc_list->silo_tc[local_count]);
        $WosID=($REC->UID);

        foreach($REC->static_data->fullrecord_metadata->addresses->children() as $address_name) {     
        $Full_address.=$address_name->address_spec->full_address."|";
        $City.=$address_name->address_spec->city."|";
        $Country.= $address_name->address_spec->country."|";

        foreach($address_name->address_spec->organizations-> children() as $organization) {
        $organization=$organization;}

        foreach($address_name->address_spec->suborganizations-> children() as $suborganization) {
        $suborganization=$suborganization;}
        };

        foreach($REC->static_data->summary->names->name  as $summary2) {
            $Name.=$summary2->display_name."|";
            $daisng_id.=$summary2[daisng_id]."|";
        }  

        foreach($REC->static_data->summary->names->name  as $summary3) {
            $Seq.=$summary3[seq_no]."|";
            $addr_seq.=$summary3[addr_no]."|";           
        }  
    
        foreach($REC->dynamic_data->cluster_related->identifiers-> children() as $identifier) {
        $id.=  $identifier[type].": ". $identifier[value]."|";}                 
        $string .= ($doi."_!".$author_Leganto."_!".$pubDate_Leganto."_!".$course_code."_!".$instructor."_!".$dept."_!".$status."_!".$year."_!".$readingListCode."_!".$readingListName."_!".$journal_Leganto."_!".$title_Leganto."_!".$Title."_!".$Source."_!".$Year."_!".$Publisher."_!".$Doctype."_!".$Citations."_!".$WosID."_!".$Name."_!".$Seq."_!".$addr_seq."_!".$Full_address."_!".$City."_!".$Country."_!".$id."_!".$daisng_id."●～*");
        $rows = explode("●～*", $string); 

        // Set the Header for the CSV export  
        $headers = array("Leganto Doi", "Leganto Authors", "Leganto Pubdate", "Course code", "Course instructors", "Department", "Course status", "Course year", "Reading list code", "Reading list name", "Leganto Journal title", "Leganto  title", "WoS Title", "WoS journal", "Publication year", "Publisher", "Doc type", "Citations", "WoS ID", "Author name", "Author sequence","Address sequence", "Address", "City", "Country","Item ID" ,"Daisng ID");
        }
    
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
}
// Skip any bad requests 
catch (Throwable $t) {continue;}
echo 'Caught exception: ', $e, "\n";
error_log($doi." | ", 3, "my-errors.log");
}

?>
</body>
</html>
