<?php 

class IO {
    

    /**
     * Import data exported from Alma Analytics to a tab-delimited text file  
     * 
     * Returns an array of rows - each row is an array of columns 
     * If $headerRow is true the array of columns will have both numeric indexes and the header cell 
     * 
     * @author  libjmh
     * @since   2022-01-17
     * 
     * @throws  Exception if no filename supplied or file does not exist 
     * 
     * @param   $filename   String      the path to the input file 
     */
    public static function importAnalyticsTxt($filename) {

        if (!$filename || !file_exists($filename)) { throw new Exception("No valid filename supplied"); }
        
        
        function deQuote($field) {
            $field = preg_replace('/^"/', '', $field);
            $field = preg_replace('/"$/', '', $field);
            $field = str_replace('""', '"', $field);
            return $field;
        }
        
        
        $data = Array(); 
    
        $inputLines = file($filename);
        $inputLines = array_map(function ($line) { 
            $line = str_replace("\0", "", $line); 
            return preg_replace('/[\r\n]+$/', '', $line);
        }, $inputLines); 
        
        $columnNames = Array(); 
        if (count($inputLines)) { 
            $columnNames = array_map('deQuote', explode("\t", array_shift($inputLines)));
        }
    
        foreach ($inputLines as $inputLine) { 
            $fields = array_map('deQuote', explode("\t", $inputLine));
            foreach ($columnNames as $key=>$name) { 
                $fields[$name] = $fields[$key]; 
            }
            $data[] = $fields; 
        }
    
        return $data;
                
    }
    
}


?>