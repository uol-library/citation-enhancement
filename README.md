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
> apiKey = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"

## 2. Software dependencies

Host machine must be on 129.11.0.0 network because of restrictions on Scopus API 

PHP - tested against versions 5.6.40, 8.0.7, 8.1.5 

cURL support in PHP including support for https 

Project https://dev.azure.com/uol-support/Library%20API/_git/AlmaAPI?path=%2F&version=GBrl-export&_a=contents 

## 3. Latest releases

v2.4.4

## 4. API references

Scopus: https://dev.elsevier.com/api_docs.html

VIAF: https://www.oclc.org/developer/api/oclc-apis/viaf/authority-source.en.html/ 

WoS expanded: https://developer.clarivate.com/apis/wos 

Alma: https://developers.exlibrisgroup.com/alma/apis/

# Build and Test

The software does not need building once the steps in the Installation process are complete 

# Configuration 

No further configuration is needed once you have saved the WoS and Scopus API Keys in the config.ini file 

# Running process 

Some of the processing is time and memory intensive, so it makes sense to run the scripts a module at a time. 

There are two ways to do this - if you have bash available (e.g. on a Linux server, or using git-bash on a Windows machine) you can run the batch script to loop over a number of modules and do all the processing in sequence. 

Otherwise you can explicitly call the individual scripts step-by-step. 

## Option 1: Batch script 

>bash batch.sh -m *LIST-OF-MODULE-CODES* 

e.g. 

>bash batch.sh -m PSYC3505,PSYC3506,PSYC3507 

or 

>bash batch.sh -f *FILE-CONTAINING-LIST-OF-MODULE-CODES* 

e.g. 

>bash batch.sh -f Config/Modules/modcodes.txt 

Writes output data to tab-delimited txt files in Data folder - summary.txt plus READING-LIST-CODE.txt for each reading list. 

If you only need to run a certain stage for each module you can use the s option - a typical use would be to re-run the export without re-running all the data enhancements: 

>bash batch.sh -f Config/Modules/modcodes.txt -s export 

## Option 2: Step-by-step 

### Step 1: assemble World Bank GNI ranking file  

You only need to do this if you are wanting to use more recent World Bank data than prepared already in this project, or than the last time you ran this step. 

> php makeWorldBankRankings.php 

This outputs data files to Config/WorldBank/ (the locations are set in config.ini) 

Step 4 will later consume these 

### Step 2: collect reading list citations  

Collect citations a module-at-a-time from Alma/Leganto: 

> php getCitationsByModule.php -m *MODCODE* >Data/*FILE*.json  

e.g. 

> php getCitationsByModule.php -m PSYC3505 >Data/PSYC3505.json  

This script (like the following ones) writes a JSON-encoded list of citations to STDOUT, so just save it somewhere suitable 

### Step 3: enhance citations with data from Alma, Scopus, WoS, VIAF  

e.g.: 

> php enhanceCitationsFromAlma.php   <Data/PSYC3505.json >Data/PSYC3505_A.json 
> 
> php enhanceCitationsFromScopus.php <Data/PSYC3505_A.json >Data/PSYC3505_AS.json 
> 
> php enhanceCitationsFromWoS.php <Data/PSYC3505_AS.json >Data/PSYC3505_ASW.json 
>
> php enhanceCitationsFromViaf.php   <Data/PSYC3505_ASW.json >Data/PSYC3505_ASWV.json 

Each script reads a JSON-encoded list of citations from STDIN, and writes an enhanced list of citations to STDOUT, so use the input filename from the previous step and write to a new file ready for the next  

### Step 4: process data and export spreadsheet  

This step is not finalised, and can be modified independently of the collection of raw data in the previous steps. 
e.g.:

> php simpleExport.php <Data/PSYC3505_ASWV.json 

or 

> php simpleExport.php -a <Data/PSYC3505_ASWV.json 

*The a (append) option does not empty the summary.txt file first and does not rewrite the header row to it.*

This script reads a JSON-encoded list of enhanced citations from STDIN and combines it with the World Bank data saved in Step 1 

It writes a set of tab-delimited (UTF-8-encoded) text files suitable for opening in Excel: one per reading list, plus a summary listing stats for each reading list. 

# Possible errors 

# Other issues to note 

The individual enhancement scripts enhanceCitationsFromViaf.php, enhanceCitationsFromScopus.php etc contain specific notes about interaction with these APIs and the issues that might arise 