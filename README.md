# Introduction 
To enhance the metadata in citations from reading lists in the Leganto system using data from various external systems. 

Initially one focus will be on collecting geographical affiliation of authors. 

The starting point for this is the software developed at Imperial College, London, to collect author information for journal articles from the WoS API Enhanced: 
https://osf.io/cyj2x/  

We have initially extended this to work against the VIAF and Scopus APIs and further integrations are possible. 

# Getting Started
## 1. Installation process
On a machine on the 129.11.0.0 network: 

In a folder, check out a copy of this project https://dev.azure.com/uol-support/Reading%20Lists/_git/Citation%20enhancement?path=%2F&version=GBlibjmh_dev&_a=contents  

In the same folder, alongside it, check out a copy of the Alma API client project https://dev.azure.com/uol-support/Library%20API/_git/AlmaAPI?path=%2F&version=GBrl-export&_a=contents 

Obtain a developer api key for the Scopus API from https://dev.elsevier.com/api_docs.html 

In your local copy of this project, edit config.ini to contain your Scopus api key e.g. 

> \[Scopus\]
> 
> apiKey = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"

## 2. Software dependencies
Host machine must be on 129.11.0.0 network because of restrictions on Scopus API 

PHP - tested against versions 5.6.40 and 8.0.7 

cURL support in PHP including support for https 

Project https://dev.azure.com/uol-support/Library%20API/_git/AlmaAPI?path=%2F&version=GBrl-export&_a=contents 

## 3. Latest releases
v1.11 

## 4. API references
Scopus: https://dev.elsevier.com/api_docs.html

VIAF: https://www.oclc.org/developer/api/oclc-apis/viaf/authority-source.en.html/ 

WoS expanded: https://developer.clarivate.com/apis/wos 

Alma: https://developers.exlibrisgroup.com/alma/apis/

# Build and Test
The software does not need building once the steps in the Installation process are complete 

## Configuration 
Choose a reading list or lists to run against, and get the course code and list code for each (e.g. "course_code"=>"29679_HIST1055", "list_code"=>"202122_HIST1055__9463092_1")

In getCitationsByCourseAndList.php, edit the value of $lists_to_process so that it contains this list or lists e.g. 

> $lists_to_process = Array( Array("course_code"=>"28573_SOEE5531M", "list_code"=>"202122_SOEE5531M__8970365_1"), Array("course_code"=>"32925_MEDS5107M", "list_code"=>"202122_MEDS5107M__9256341_1_B") );

## Step 1: assemble World Bank GNI ranking file  
> php makeWorldBankRankings.php 

This outputs data files to Config/WorldBank/ (the locations are set in config.ini) 

Step 4 will later consume these 

## Step 2: collect reading list citations  
> php getCitationsByCourseAndList.php >Data/1.json 

This script (like the following ones) writes a JSON-encoded list of citations to STDOUT, so just save it somewhere suitable 

## Step 3: enhance citations with data from Alma, Scopus, VIAF  
> php enhanceCitationsFromAlma.php <Data/1.json >Data/2.json 
> 
> php enhanceCitationsFromViaf.php <Data/2.json >Data/3.json 
> 
> php enhanceCitationsFromScopus.php <Data/3.json >Data/4.json 

Each script reads a JSON-encoded list of citations from STDIN, and writes an enhanced list of citations to STDOUT, so use the input filename from the previous step and write to a new file ready for the next  

## Step 4: process data and export spreadsheet  
> php simpleCsvExport.php <Data/4.json >Data/5.csv 

This script reads a JSON-encoded list of enhanced citations from STDIN and combines it with the World Bank data saved in Step 1 

TODO: This script outputs UTF-encoded data but Excel expects CSV files to be ANSI-encoded and so special characters will hash. 
This does not matter in development, but we need to modify this script e.g. to export a UTF-8-encoded Excel .xlsx file that Excel can open 
directly with the correct encoding 

## Possible errors 
Step 4 might fail with the error: 

> No World Bank ranking for COUNTRY-CODE 

This means the reading list affiliation data includes a 2-letter ISO country code that is not in the World Bank ranking file generated in step 1 

Long term we might choose to ignore these but for now while we train and test the system, manually add the 2-letter code to the file: 

> Config/WorldBank/alias.json 

In the form absent code => a suitable replacement code that is present 

e.g. this file currently contains 

> {"NF":"AU"}

which indicates that where we have data for "NF" (Norfolk Island) we will use the World Bank data for its parent territory "AU" (Australia) instead

## Other issues to note 
The individual enhancement scripts enhanceCitationsFromViaf.php and enhanceCitationsFromScopus.php contain specific notes about interaction with these APIs and the issues that might arise 