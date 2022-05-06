# Introduction 

To enhance the metadata in citations from reading lists in the Leganto system using data from various external systems. 

Initially one focus will be on collecting geographical affiliation of authors. 

The starting point for this is the software developed at Imperial College, London, to collect author information for journal articles from the WoS API Expanded: 
https://osf.io/cyj2x/  

We have initially extended this to also work against the VIAF and Scopus APIs and further integrations are possible. 

# Getting Started

## 1. Installation process

On a machine with permission to use the Scopus API (for University of Leeds users, this means a machine on the 129.11.0.0 network – other Universities will have their own ranges):  

- In a folder, check out a copy of this project https://dev.azure.com/uol-support/Reading%20Lists/_git/Citation%20enhancement?path=%2F&version=GBlibjmh_dev&_a=contents  

 - Obtain developer API keys for the following Alma APIs from https://developers.exlibrisgroup.com/alma/apis/ - these need permission to the APIs on the production server, and only need read-only access: 

    - Bibiographic Records 
    - Courses

- Obtain a developer api key for the Scopus API from https://dev.elsevier.com/api_docs.html 

- Make a developer account on the Clarivate Portal https://developer.clarivate.com/ create your own Application and subscribe it to the WoS Enhanced API, and get the key for that 

- In your local copy of the project, copy the template file config.template.ini to become your configuration file config.ini 

- Edit this new config.ini to contain your Alma, Scopus and WoS api keys e.g. 

> \[Alma-Keys\]
> 
> bibs    = xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx 
> 
> courses = xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx 

> \[Scopus\]
> 
> apiKey = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"

> \[WoS\]
> 
> apiKey = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"

## 2. Dependencies

- Alma Library Management System and Leganto Reading List Management System to query against 

- Ex Libris api keys with read access to courses, reading lists and bibliographic data  

- Host machine must have permission to use the Scopus API (for University of Leeds users, this means it must be on the 129.11.0.0 network) 

- PHP - tested against versions 8.0.7, 8.1.5 

- cURL support in PHP including support for https with up-to-date cacert file  

- Subscription to Scopus, and developer API key 

- Subscription to WoS Expanded API, and developer API key  

## 3. Latest releases

v3.2.3

## 4. APIs

- Scopus: https://dev.elsevier.com/api_docs.html

- VIAF: https://www.oclc.org/developer/api/oclc-apis/viaf/authority-source.en.html/ 

- WoS expanded: https://developer.clarivate.com/apis/wos 

- Alma: https://developers.exlibrisgroup.com/alma/apis/

# Build and Test

The software does not need building once the steps in the Installation process are complete 

# Configuration 

No further configuration is needed once you have saved the Alma, Scopus and WoS API Keys in the config.ini file as described in **Installation process**  

# Running process 

Some of the processing is time and memory intensive, so it makes sense to run the scripts a module at a time. 

There are two ways to do this - 

- Explicitly call the individual scripts step-by-step 

- Use the convenience script batch.php which runs the desired steps in sequence for a desired set of modules 

## Option 1: Step-by-step 

### (1) Collect reading list citations  

Collect citations a module-at-a-time from Alma/Leganto: 

> php getCitationsByModule.php -m *MODCODE* >Data/tmp/*FILE*_L.json  

e.g. 

> php getCitationsByModule.php -m PSYC3505 >Data/tmp/PSYC3505_L.json  

This script (like the following ones) writes a JSON-encoded list of citations to STDOUT, so just save it somewhere suitable 

### (2) Enhance citations with data from Alma, Scopus, WoS, VIAF  

e.g.: 

> php enhanceCitationsFromAlma.php   <Data/tmp/PSYC3505_L.json >Data/tmp/PSYC3505_A.json 
> 
> php enhanceCitationsFromScopus.php <Data/tmp/PSYC3505_A.json >Data/tmp/PSYC3505_S.json 
> 
> php enhanceCitationsFromWoS.php <Data/tmp/PSYC3505_S.json >Data/tmp/PSYC3505_W.json 
>
> php enhanceCitationsFromViaf.php   <Data/tmp/PSYC3505_W.json >Data/tmp/PSYC3505_V.json 

Each script reads a JSON-encoded list of citations from STDIN, and writes an enhanced list of citations to STDOUT, so use the input filename from the previous step and write to a new file ready for the next step.  

### (3) Process data and export spreadsheet  

These scripts could be modified independently of the collection of raw data in the previous steps, and re-run. 

To run e.g.:

> php simpleExport.php <Data/tmp/PSYC3505_V.json 
> php longExport.php <Data/tmp/PSYC3505_V.json 

or 

> php simpleExport.php -a <Data/tmp/PSYC3505_V.json 
> php longExport.php <Data/tmp/PSYC3505_V.json 

*The a (append) option gor simpleExport.php does **not** empty the summary.csv file first and does **not** rewrite the header row to it.*

These scripts read a JSON-encoded list of enhanced citations from STDIN 

They write a set of CSV files (UTF-8-encoded, with a byte-order-mark) suitable for opening in Excel: one per reading list. 

simpleExport.php also writes a summary listing stats for each reading list. 

## Option 2: Batch script 

>php batch.php -m *LIST-OF-MODULE-CODES* 

e.g. 

>php batch.php -m PSYC3505,PSYC3506,PSYC3507 

or 

>php batch.php -f *FILE-CONTAINING-LIST-OF-MODULE-CODES* 

e.g. 

>php batch.php -f Config/Modules/modcodes.txt 

Writes output data to tab-delimited csv files in Data folder - summary.txt plus READING-LIST-CODE.csv and READING-LIST-CODE_LONG.csv for each reading list. 
Also writes MODCODE.json file to Data folder with raw data, and intermediate files from each stage to the Data\tmp folder.  

If you only need to run a certain stage for each module you can use the -s option - a typical use would be to re-run the export without re-running all the data enhancements: 

>php batch.php -f Config/Modules/modcodes.txt -s export 

# Possible errors 

Currently error handling is rudimentary - errors in each enhancement are saved in the JSON citation files, and then if the export scripts encounter any they exit, displaying the error found. 

One possible error in the Scopus integration is that the rate-limit is hit - each endpoint in the API allows a limitted number of requests per week, and processing a large number of lists might hit these limits. 

# Other issues to note 

The individual enhancement scripts contain specific notes about interaction with these APIs and the issues that might arise 