# Introduction 

To enhance the metadata in citations from reading lists in the Leganto system using data from various external systems. 

Initially one focus will be on collecting geographical affiliation of authors. 

The starting point for this is the software developed at Imperial College, London, to collect author information for journal articles from the WoS API Expanded: 
https://osf.io/cyj2x/  

We have initially extended this to also work against the VIAF and Scopus APIs and further integrations are possible. 

Further documentation on specific aspects of the process is in the directory Docs/ 

# Getting Started

## 1. Dependencies

- Alma Library Management System and Leganto Reading List Management System to query against 

- Ex Libris API keys with read access to Courses and Bibs  

- Host machine must have permission to use the Scopus API (for University of Leeds users, this means it must be on the 129.11.0.0 network) 

- PHP 

    - Code tested against versions 8.0.7, 8.1.5 

    - Needs http wrappers and openssl enabled

    - Needs system function enabled

    - Needs cURL support including support for https and up-to-date cacert file

- Subscription to Scopus, and developer API key 

- Subscription to WoS Expanded API, and developer API key  

## 2. Installation process

- Identify a suitable machine, with PHP installed and configured - see Dependencies above 

- In a suitable folder, checkout a copy of this project:  
[https://dev.azure.com/uol-support/Reading%20Lists/_git/Citation%20enhancement](https://dev.azure.com/uol-support/Reading%20Lists/_git/Citation%20enhancement)  

- Configure the software as described in **Configuration** (below) 

- If space is at a premium, you can delete the directory Docs/ and its contents without affecting software operation (although the documents may be helpful) 

## 3. Latest releases

v3.4.8

## 4. APIs

- Scopus: [https://dev.elsevier.com/api_docs.html](https://dev.elsevier.com/api_docs.html)

- VIAF: [https://www.oclc.org/developer/api/oclc-apis/viaf/authority-source.en.html/](https://www.oclc.org/developer/api/oclc-apis/viaf/authority-source.en.html/)

- WoS expanded: [https://developer.clarivate.com/apis/wos](https://developer.clarivate.com/apis/wos) 

- Alma: [https://developers.exlibrisgroup.com/alma/apis/](https://developers.exlibrisgroup.com/alma/apis/)

# Build and Test

- There is no separate **build** step after the **Installation** has been carried out (above). 

- **Configuration** (below) must be done before you can test the code. 

- A simple **smoke-test** could be done by extracting citation data from Leganto for a test module and then immediately generating a report from it, as follows: 

- Identify a candidate module to test against - this must exist in Alma with a reading list in Leganto, and a live module can be used - e.g. For Leeds a possible candiate is **BLGY3135** and this will be used in the example commands below 

- Try using the batch-processing script (see below) to get the module's reading list citations from Alma/Leganto: 
	
```
php batch.php -m BLGY3135 -s get
```
   
- You should see something like: 

```
Processing: BLGY3135 
Starting module BLGY3135 
Getting citations by module code
Done module BLGY3135
All done  
```

- Check that the following two (identical) files have been created, and check that they contain valid JSON data for the citations in the test module's reading list(s):

```
Data/BLGY135.json 
Data/tmp/BLGY135_L.json 
```

- Try using the batch-processing script to produce the output reports for this module: 
    
```
php batch.php -m BLGY3135 -s export
```
    
- You should see something like: 

```  
Processing: BLGY3135 
Starting module BLGY3135 
Exporting shorter digested data 
Exporting longer digested data 
Done module BLGY3135
All done
```

- Check that the following two files have been created - the first filename will depend on the code for the test module's reading list. If the test module has more than one reading list there will be more than one file. 

```
Data/202122_BLGY3135__8669630_1.CSV 
Data/Summary.CSV  
```

- Open these two files in Excel - Summary should contain a row-per-reading-list summarising its data and the other file should contain a row-per-citation (excluding Note citations) 

- Most of the columns will be empty of zero - there will be no country data at all. This is because no data has actually been collected from Alma/Scopus/WoS/VIAF - this smoke-test is just to demonstrate that the scripts are installed and configured correctly, and are capable of communicating with Alma/Leganto. 
    
- For a more fully-featured test see the **Running process** section and attempt a complete run against some test modules 

# Configuration 

- Obtain developer API keys for the following Alma APIs from https://developers.exlibrisgroup.com/alma/apis/ - these need permission to the APIs on the production server, and only need read-only access: 

    - Bibs

    - Courses

- Obtain a developer API key for the Scopus API from https://dev.elsevier.com/api_docs.html 

- Obtain a developer API key for the WoS API Expanded from https://developer.clarivate.com/

- In your local copy of the project, copy the template file config.template.ini to become your configuration file config.ini 

- Edit this new config.ini to contain your Alma, Scopus and WoS API keys e.g. 

```ini
[Alma-Keys]
bibs    = xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx 
courses = xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx 
[Scopus]
apiKey = xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
[WoS]
apiKey = xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```
- If your institution is an Ex Libris customer outside Europe, modify the values of apiHost and apiURL in the Alma section of config.ini - 
see the table in Calling Alma APIs in https://developers.exlibrisgroup.com/alma/apis/

# Running process 

There are two ways to do run the scripts - 

- Explicitly call the individual scripts step-by-step for particular modules 

- Use the convenience script batch.php which runs the desired steps in sequence for a desired set of modules 

## Option 1: Step-by-step 

### (1) Collect reading list citations  

Collect citations a module-at-a-time from Alma/Leganto: 

```
php getCitationsByModule.php -m MODCODE >Data/tmp/FILE_L.json
```

e.g. 

```
php getCitationsByModule.php -m PSYC3505 >Data/tmp/PSYC3505_L.json
```

This script (like the following ones) writes a JSON-encoded list of citations to STDOUT, so just save it somewhere suitable 

### (2) Enhance citations with data from Alma, Scopus, WoS, VIAF  

e.g.: 

```
php enhanceCitationsFromAlma.php   <Data/tmp/PSYC3505_L.json >Data/tmp/PSYC3505_A.json 
php enhanceCitationsFromScopus.php <Data/tmp/PSYC3505_A.json >Data/tmp/PSYC3505_S.json 
php enhanceCitationsFromWoS.php <Data/tmp/PSYC3505_S.json >Data/tmp/PSYC3505_W.json 
php enhanceCitationsFromViaf.php   <Data/tmp/PSYC3505_W.json >Data/tmp/PSYC3505_V.json 
```

Each script reads a JSON-encoded list of citations from STDIN, and writes an enhanced list of citations to STDOUT, so use the input filename from the previous step and write to a new file ready for the next step.  

### (3) Process data and export spreadsheets  

These scripts could be modified independently of the collection of raw data in the previous steps, and re-run. 

To run e.g.:

```
php simpleExport.php <Data/tmp/PSYC3505_V.json 
php longExport.php <Data/tmp/PSYC3505_V.json 
```

or 

```
php simpleExport.php -a <Data/tmp/PSYC3505_V.json 
php longExport.php <Data/tmp/PSYC3505_V.json 
```

*The a (append) option for simpleExport.php does **not** empty the summary.csv file first and does **not** rewrite the header row to it.*

These scripts read a JSON-encoded list of enhanced citations from STDIN 

They write a set of CSV files (UTF-8-encoded, with a byte-order-mark) suitable for opening in Excel: one per reading list. 

simpleExport.php also writes a summary listing stats for each reading list. 

## Option 2: Batch script 

```
php batch.php -m LIST-OF-MODULE-CODES
```

e.g. 

```
php batch.php -m PSYC3505,PSYC3506,PSYC3507
```

or 

```
php batch.php -f FILE-CONTAINING-LIST-OF-MODULE-CODES
```

e.g. 

```
php batch.php -f Config/Modules/modcodes.txt
```

Writes output data to UTF-8-encoded CSV files in Data folder - summary.txt plus READING-LIST-CODE.csv and READING-LIST-CODE_LONG.csv for each reading list. 
Also writes MODCODE.json file to Data folder with raw data, and intermediate files from each stage to the Data\tmp folder.  

If you only need to run certain steps for each module you can use the -s option - a typical use would be to re-run the export without re-running all the data enhancements: 

```
php batch.php -f Config/Modules/modcodes.txt -s export
```

Possible steps are get,alma,scopus,wos,viaf,export and more than one can be supplied e.g.: 

```
php batch.php -f Config/Modules/modcodes.txt -s get,alma 
php batch.php -f Config/Modules/modcodes.txt -s scopus,wos,viaf 
```

# Possible errors 

Currently error handling is rudimentary - known possible issues with the data from the APIs is checked for and **trigger_error(message, E_USER_ERROR)** is used to suspend processing (the batch script exits as soon as any individual script ends with an error). There may also be uncaught exceptions which likewise would cause the batch script to end. 

This has the disadvantage that a relatively minor problem with one citation would prevent processing of a large batch of citations, and it may be better to accept and ignore occasional minor errors. A possible improvement would be to add error logging (with different severities) and to only terminate scripts in the case of the most serious errors.  

Issues to look out for: 

- Invalid API keys would cause problems in Leganto, Alma, Scopus and WoS - if this happens, the scripts will terminate with a relevant message 

- Scopus occasionally has temporary problems with its Solr service ["GENERAL_SYSTEM_ERROR (Error calling Solr Search Service)"] - try again later if this happens 

- APIs generally have rate limits - 

    - Scopus has weekly and per-second limits outlined at https://dev.elsevier.com/api_key_settings.html 
    
    - WoS has per-year and per-second limits outlined at https://developer.clarivate.com/apis/wos  
    
    - Ex Libris (Alma and Leganto) has a per-second limit on API calls outlined at https://developers.exlibrisgroup.com/alma/apis/
    
    - Delays in the script should (more than) protect against Ex Lbris, Scopus and WoS per-second limits 
    
    - I can't find a documented limit on the VIAF API but a small delay has been built into the script to avoid hitting it too hard    
    
    - The Scopus weekly limit might be hit if processing a large number of citations, as might the WoS yearly limit (depending on the subscription plan) 
    
        - The scripts record in the JSON output file the number of requests remaining per-week (Scopus) or per-year (WoS) to allow this to be regularly monitored 
        
        - In the output citations.json file, look at an individual citation's **CITATION.WoS.rate-limit** and **CITATION.Scopus.rate-limit** values *NB for Scopus there are different limits for different individual APIs, and from experience author-retrieval is the one most likely to be a problem*    

    - The number of requests could be reduced by using a batch-wide cache (there is a per-run cache but since the same resources may appear on multiple lists, a batch-wide cache would be more efficient) 
   
    - The scripts could be made to run faster by decreasing the delays (e.g. "usleep(500000)") before API calls - these are set to cautious values and there may be scope to reduce them - using a batch-wide cache would also improve processing time
    
- The Scopus API only returns limitted data when called from a machine outside the network of a subscribing organisation - the script ends with an error in this case        

- Special characters (e.g. " * = ' { }) and reserved words (e.g. AND OR) may cause problems with some API searches - documentation on the APIs' behaviours is thin and not always consistent with observed behaviour - I have tried to escape or remove this content as appropriate but there is scope for further improvement    

- The individual enhancement scripts contain specific notes about interaction with these APIs and the issues that might arise  
