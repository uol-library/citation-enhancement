<?php
	// Constants and similar globals for Alma API

	// Some fundamentals
	define('PROTOCOL','https://');
	if (gethostname() == 'libdevhv.leeds.ac.uk') {
		define('DOMAIN','api-dev.library.leeds.ac.uk');
		define('LUL_CLIENT_URL','http://dev2.library.leeds.ac.uk');
	}else {
		define('DOMAIN','api.library.leeds.ac.uk');
		define('LUL_CLIENT_URL','https://library.leeds.ac.uk');
	}

	// ALMA API
	define('LUL_ALMA_API_HOST','api-eu.hosted.exlibrisgroup.com');
	// Testing TLS 1.2  
	// define('LUL_ALMA_API_HOST','api-eu-tlstest.hosted.exlibrisgroup.com');
	
	define('LUL_ALMA_API_URL','https://'.LUL_ALMA_API_HOST.'/almaws/v1/');

	// PRIMO API
	define('LUL_PRIMO_API_HOST',LUL_ALMA_API_HOST);
	define('LUL_PRIMO_API_URL','https://'.LUL_PRIMO_API_HOST.'/primo/v1/');

	// Log file directory
	define('LUL_ALMA_API_LOG_DIR','/var/www/api/private/Logs');

	// Library enquiries email address
	define('LUL_EMAIL_MAIN','library@leeds.ac.uk');

	// Default Primo search scope
	define('LUL_PRIMO_DEFAULT_SEARCH_SCOPE','My_Inst_CI_not_ebsco');

	// Default Primo search tab
	define('LUL_PRIMO_DEFAULT_SEARCH_TAB','AlmostEverything');

	// Default Primo view
	define('LUL_PRIMO_DEFAULT_VIEW','44LEE_INST:VU1');

	// PRIMO URL
	define('LUL_PRIMO_URL','https://leeds.primo.exlibrisgroup.com/discovery/search?vid='.LUL_PRIMO_DEFAULT_VIEW.'&search_scope='.LUL_PRIMO_DEFAULT_SEARCH_SCOPE);

?>