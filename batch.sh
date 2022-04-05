#!/usr/bin/bash
#  
#  =======================================================================
#  
#  Script to process multiple modules and reading lists using the 
#  individual scripts in this folder  
#  
#  =======================================================================
#  
#  Input: 
#  List of modcodes in -m option 
#  or in file specified by -f option 
#  
#  Output: 
#  Progress report to console 
#  Intermediate .json files in Data folder 
#  Final output summary.txt and LISTCODE.txt files in Data folder 
#  
#  =======================================================================
#
#  Typical usage: 
#  batch.sh -m "PSYC3505,"HPSC2400" 
#  batch.sh -f MODCODES.txt 
#  batch.sh -f MODCODES.txt -s export  
#  batch.sh -f MODCODES.txt -s get,alma  
#   
#  
#  =======================================================================
#  
#  General process: 
#  
#  For each supplied module code, calls in order the scripts: 
#  getCitationsByModule.php 
#  enhanceCitationsFromAlma.php
#  enhanceCitationsFromScopus.php
#  enhanceCitationsFromWoS.php
#  enhanceCitationsFromVIAF.php
#  simpleExport.php 
#  
#  
#  =======================================================================
#  
#  
#  
#  !! Gotchas !!  
#  
#  
#  
#  

while getopts "m:f:s:" o; do
    case "${o}" in
        m)
            m=${OPTARG}
            modcodes=( $(grep -Eo '[^,;.: |]+' <<<"$m") )
            ;;
        f)
            f=${OPTARG}
            m=$(tr '\n\r' '|' <"$f")
            modcodes=( $(grep -Eo '[^,;.: |]+' <<<"$m") )
            ;;
        s)
            s=$(tr '[:upper:]' '[:lower:]' <<<"${OPTARG}")
            stages=( $(grep -Eo '[^,;.: |]+' <<<"$s") )
            ;;
    esac
done
shift $((OPTIND-1))

printf "\nProcessing: " 
echo ${modcodes[@]}
printf "\n\n\n"


if [[ " ${stages[*]} " =~ " export " || -z "${s}" ]]; 
then 
   printf "Initialising summary file\n\n"
   php simpleExport.php -i
   if [ $? -ne 0 ]; 
   then 
      printf "Script ended with error\n"
      exit
   fi 
fi


for modcode in "${modcodes[@]}"
do
   printf "Starting module ${modcode}\n"; 


   if [[ " ${stages[*]} " =~ " get " || -z "${s}" ]]; 
   then 
      printf "Getting citations by module code\n"  
      php getCitationsByModule.php -m ${modcode} >Data/${modcode}.json
      if [ $? -ne 0 ]; 
      then 
         printf "Script ended with error\n"
         exit
      fi 
   fi 

   if [[ " ${stages[*]} " =~ " alma " || -z "${s}" ]]; 
   then 
      printf "Enhancing citations from Alma\n"
      php enhanceCitationsFromAlma.php <Data/${modcode}.json >Data/${modcode}_A.json
      if [ $? -ne 0 ]; 
      then 
         printf "Script ended with error\n"
         exit
      fi 
   fi 

   if [[ " ${stages[*]} " =~ " scopus " || -z "${s}" ]]; 
   then 
      printf "Enhancing citations from Scopus\n"
      php enhanceCitationsFromScopus.php <Data/${modcode}_A.json >Data/${modcode}_AS.json
      if [ $? -ne 0 ]; 
      then 
         printf "Script ended with error\n"
         exit
      fi
   fi

   if [[ " ${stages[*]} " =~ " wos " || -z "${s}" ]]; 
   then 
      printf "Enhancing citations from WoS\n"
      php enhanceCitationsFromWos.php <Data/${modcode}_AS.json >Data/${modcode}_ASW.json
      if [ $? -ne 0 ]; 
      then 
         printf "Script ended with error\n"
         exit
      fi 
   fi

   if [[ " ${stages[*]} " =~ " viaf " || -z "${s}" ]]; 
   then 
      printf "Enhancing citations from VIAF\n"
      php enhanceCitationsFromViaf.php <Data/${modcode}_ASW.json >Data/${modcode}_ASWV.json
      if [ $? -ne 0 ]; 
      then 
         printf "Script ended with error\n"
         exit
      fi 
   fi

   if [[ " ${stages[*]} " =~ " export " || -z "${s}" ]]; 
   then 
      printf "Exporting digested data\n"
      php simpleExport.php -a <Data/${modcode}_ASWV.json
      if [ $? -ne 0 ]; 
      then 
         printf "Script ended with error\n"
         exit
      fi 
   fi

    
   
   printf "Done module ${modcode}\n\n" 
done

printf "All done\n\n\n"

exit 

