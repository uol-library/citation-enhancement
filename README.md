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

Make a developer account on the Clarivate Portal https://developer.clarivate.com/ and either arrange with colleagues at Leeds to have access to their application readinglistanalysis_leeds_ac_uk, and get its key, or create your own Application and subscribe it to the WoS Enhanced API, and get the key for that - either way, edit config.ini to contain your WoS api key e.g. 

> \[WoS\]
> 
> apiKey = "----------------------------------------"

## 2. Software dependencies
Host machine must be on 129.11.0.0 network because of restrictions on Scopus API 

PHP - tested against versions 5.6.40 and 8.0.7 

cURL support in PHP including support for https 

Project https://dev.azure.com/uol-support/Library%20API/_git/AlmaAPI?path=%2F&version=GBrl-export&_a=contents 

## 3. Latest releases
v2.2

## 4. API references
Scopus: https://dev.elsevier.com/api_docs.html

VIAF: https://www.oclc.org/developer/api/oclc-apis/viaf/authority-source.en.html/ 

WoS expanded: https://developer.clarivate.com/apis/wos 

Alma: https://developers.exlibrisgroup.com/alma/apis/

# Build and Test
The software does not need building once the steps in the Installation process are complete 

## Configuration 
Choose a module code or codes to run against e.g. "PSYC3505" and list those in the value of the array $modulesToInclude in getCitationsByCourseAndList.php, e.g.: 

> $modulesToInclude = Array("LUBS1295","LUBS3340","HPSC2400","HPSC3450","HECS5169M","HECS3295","HECS5186M","HECS5189M","COMP2121","COMP5840M","XJCO2121","OCOM5204M","GEOG1081","GEOG2000","DSUR5130M","DSUR5022M","BLGY3135","SOEE1640");

## Step 1: assemble World Bank GNI ranking file  
You only need to do this if you are wanting to use more recent World Bank data than prepared already in this project, or than the last time you ran this step. 

> php makeWorldBankRankings.php 

This outputs data files to Config/WorldBank/ (the locations are set in config.ini) 

Step 4 will later consume these 

## Step 2: collect reading list citations  
> php getCitationsByCourseAndList.php >Data/1.json 

This script (like the following ones) writes a JSON-encoded list of citations to STDOUT, so just save it somewhere suitable 

## Step 3: enhance citations with data from Alma, Scopus, WoS, VIAF  
> php enhanceCitationsFromAlma.php   <Data/1.json >Data/1A.json 
> 
> php enhanceCitationsFromScopus.php <Data/1A.json >Data/1AS.json 
> 
> php enhanceCitationsFromWoS.php <Data/1AS.json >Data/1ASW.json 
>
> php enhanceCitationsFromViaf.php   <Data/1ASW.json >Data/1ASWV.json 

Each script reads a JSON-encoded list of citations from STDIN, and writes an enhanced list of citations to STDOUT, so use the input filename from the previous step and write to a new file ready for the next  

## Step 4: process data and export spreadsheet  
This step is not finalised, and can be modified independently of the collection of raw data in the previous steps. 

> php simpleCsvExport.php <Data/1ASWV.json 

This script reads a JSON-encoded list of enhanced citations from STDIN and combines it with the World Bank data saved in Step 1 

It writes a set of tab-delimited (UTF-8-encoded) text files suitable for opening in Excel: one per reading list, plus a summary listing stats for each reading list. 

## Possible errors 

## Other issues to note 
The individual enhancement scripts enhanceCitationsFromViaf.php, enhanceCitationsFromScopus.php etc contain specific notes about interaction with these APIs and the issues that might arise 