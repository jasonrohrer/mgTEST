<?php



global $sg_version;
$sg_version = "1";



// edit settings.php to change server' settings
include( "settings.php" );




// no end-user settings below this point


// no caching
//header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', FALSE);
header('Pragma: no-cache'); 



// enable verbose error reporting to detect uninitialized variables
error_reporting( E_ALL );



// page layout for web-based setup
$setup_header = "
<HTML>
<HEAD><TITLE>SteamGate Server Web-based setup</TITLE></HEAD>
<BODY BGCOLOR=#FFFFFF TEXT=#000000 LINK=#0000FF VLINK=#FF0000>

<CENTER>
<TABLE WIDTH=75% BORDER=0 CELLSPACING=0 CELLPADDING=1>
<TR><TD BGCOLOR=#000000>
<TABLE WIDTH=100% BORDER=0 CELLSPACING=0 CELLPADDING=10>
<TR><TD BGCOLOR=#EEEEEE>";

$setup_footer = "
</TD></TR></TABLE>
</TD></TR></TABLE>
</CENTER>
</BODY></HTML>";






// ensure that magic quotes are OFF
// we hand-filter all _REQUEST data with regexs before submitting it to the DB
if( get_magic_quotes_gpc() ) {
    // force magic quotes to be removed
    $_GET     = array_map( 'sg_stripslashes_deep', $_GET );
    $_POST    = array_map( 'sg_stripslashes_deep', $_POST );
    $_REQUEST = array_map( 'sg_stripslashes_deep', $_REQUEST );
    $_COOKIE  = array_map( 'sg_stripslashes_deep', $_COOKIE );
    }
    





// all calls need to connect to DB, so do it once here
//sg_connectToDatabase();

// close connection down below (before function declarations)


// testing:
//sleep( 5 );


// general processing whenver server.php is accessed directly



// all calls need to connect to DB, so do it once here
sg_connectToDatabase();


// grab POST/GET variables
$action = sg_requestFilter( "action", "/[A-Z_]+/i" );

$debug = sg_requestFilter( "debug", "/[01]/" );

$remoteIP = "";
if( isset( $_SERVER[ "REMOTE_ADDR" ] ) ) {
    $remoteIP = $_SERVER[ "REMOTE_ADDR" ];
    }




if( $action == "version" ) {
    global $sg_version;
    echo "$sg_version";
    }
else if( $action == "steam_login_return" ) {
    sg_steamLoginReturn();
    }
else if( $action == "get_steam_key" ) {
    sg_getSteamKey();
    }
else if( $action == "sg_setup" ) {
    global $setup_header, $setup_footer;
    echo $setup_header; 

    echo "<H2>SteamGate Web-based Setup</H2>";

    echo "Creating tables:<BR>";

    echo "<CENTER><TABLE BORDER=0 CELLSPACING=0 CELLPADDING=1>
          <TR><TD BGCOLOR=#000000>
          <TABLE BORDER=0 CELLSPACING=0 CELLPADDING=5>
          <TR><TD BGCOLOR=#FFFFFF>";

    sg_setupDatabase();

    echo "</TD></TR></TABLE></TD></TR></TABLE></CENTER><BR><BR>";
    
    echo $setup_footer;
    }
else if( preg_match( "/server\.php/", $_SERVER[ "SCRIPT_NAME" ] ) ) {
    // server.php has been called without an action parameter

    // the preg_match ensures that server.php was called directly and
    // not just included by another script
    
    // quick (and incomplete) test to see if we should show instructions
    global $tableNamePrefix;
    
    // check if our tables exist
    $exists = sg_doesTableExist( $tableNamePrefix . "mapping" ) &&
        sg_doesTableExist( $tableNamePrefix . "log" ) &&
        sg_doesTableExist( $tableNamePrefix . "steam_key_bank" );
    
        
    if( $exists  ) {
        echo "Steam Gate database setup and ready";
        }
    else {
        // start the setup procedure

        global $setup_header, $setup_footer;
        echo $setup_header; 

        echo "<H2>Steam Gate Web-based Setup</H2>";
    
        echo "Steam Gate will walk you through a " .
            "brief setup process.<BR><BR>";
        
        echo "Step 1: ".
            "<A HREF=\"server.php?action=sg_setup\">".
            "create the database tables</A>";

        echo $setup_footer;
        }
    }



// done processing
// only function declarations below

sg_closeDatabase();





/**
 * Creates the database tables needed by steamGate.
 */
function sg_setupDatabase() {
    global $tableNamePrefix;

    $tableName = $tableNamePrefix . "log";
    if( ! sg_doesTableExist( $tableName ) ) {

        $query =
            "CREATE TABLE $tableName(" .
            "entry TEXT NOT NULL, ".
            "entry_time DATETIME NOT NULL );";

        $result = sg_queryDatabase( $query );

        echo "<B>$tableName</B> table created<BR>";
        }
    else {
        echo "<B>$tableName</B> table already exists<BR>";
        }

    
    
    $tableName = $tableNamePrefix . "mapping";
    if( ! sg_doesTableExist( $tableName ) ) {

        // maps each steam ID to a download code and potentially a
        // steam gift key
        $query =
            "CREATE TABLE $tableName(" .
            "steam_id VARCHAR(255) NOT NULL PRIMARY KEY," .
            "ticket_id VARCHAR(255) NOT NULL," .
            "INDEX(ticket_id),".
            "steam_gift_key TEXT NOT NULL );";

        $result = sg_queryDatabase( $query );

        echo "<B>$tableName</B> table created<BR>";
        }
    else {
        echo "<B>$tableName</B> table already exists<BR>";
        }


    $tableName = $tableNamePrefix . "steam_key_bank";
    if( ! sg_doesTableExist( $tableName ) ) {

        // bank of keys not assigned yet
        $query =
            "CREATE TABLE $tableName(" .
            "steam_gift_key VARCHAR(255) NOT NULL PRIMARY KEY ) ".
            "ENGINE = INNODB;";

        $result = sg_queryDatabase( $query );

        echo "<B>$tableName</B> table created<BR>";
        }
    else {
        echo "<B>$tableName</B> table already exists<BR>";
        }
    }





// $inTicketID can be blank, but no free steam key will be returned
// instead, a download link will be shown
function sg_showSteamLoginButton( $inTicketID ) {
    global $steamLoginURL, $serverRootURL, $fullServerURL, $steamButtonURL;

    // Nonce needed here?  No, because Steam's response contains a nonce
    $returnURL = $fullServerURL .
        "?action=steam_login_return&ticket_id=$inTicketID";


    $queryParams = array();

    $queryParams[ 'openid.mode' ] = "checkid_setup";
    $queryParams[ 'openid.ns' ] = "http://specs.openid.net/auth/2.0";
    $queryParams[ 'openid.return_to' ] = $returnURL;
    $queryParams[ 'openid.realm' ] = $serverRootURL;
    $queryParams[ 'openid.identity' ] =
        "http://specs.openid.net/auth/2.0/identifier_select";
    $queryParams[ 'openid.claimed_id' ] =
        "http://specs.openid.net/auth/2.0/identifier_select";
    

    $formattedParams = http_build_query( $queryParams );
    
    $buttonURL =
        $steamLoginURL . "?$formattedParams";
    
    echo "<a href='$buttonURL'><img border=0 src='$steamButtonURL'></a>";
    }



// either generate/give a steam key to this user, OR show this
// user their non-steam download link
function sg_steamLoginReturn() {
    // php replaces all . with _ in URL parameter names!

    
    $signed = sg_requestFilter( "openid_signed", "/.*/" );
    $sig = sg_requestFilter( "openid_sig", "/.*/" );
    $ns = sg_requestFilter( "openid_ns", "/.*/" );
    $claimed_id = sg_requestFilter( "openid_claimed_id", "/.*/" );

    preg_match( "#http://steamcommunity.com/openid/id/(\d+)#",
                $claimed_id, $matches );
    
    $steam_id = $matches[1];
    
    
    
    // all other params that have been signed
    $signedList = explode( ",", $signed );
    
    
    $queryParams = array();

    foreach( $signedList as $paramName ) {
        $fullParamName = "openid_" . str_replace( ".", "_", $paramName );

        $paramValue = sg_requestFilter( "$fullParamName", "/.*/" );
        
        $queryParams["openid.$paramName"] = $paramValue;
        }

    $queryParams[ 'openid.mode' ] = "check_authentication";
    $queryParams[ 'openid.ns' ] = $ns;
    $queryParams[ 'openid.sig' ] = $sig;
    

    $formattedParams = http_build_query( $queryParams );
    
    
    // url to verify this with steam:

    global $steamLoginURL;
    
    
    $verifyURL = "$steamLoginURL?$formattedParams";

    $verifyResult = file_get_contents( $verifyURL );

    if( ! preg_match( "/is_valid\s*:\s*true/i", $verifyResult ) ) {
        echo "Failed to validate your Steam ID with Valve.";
        return;
        }

    global $tableNamePrefix;
    

    // have a validated $steam_id


    // see if we already have a record for them
    
    $query = "SELECT ticket_id, steam_gift_key FROM ".
        "$tableNamePrefix"."mapping ".
        "WHERE steam_id = '$steam_id';";

    $result = sg_queryDatabase( $query );
    
    $recordExists = ( mysql_numrows( $result ) == 1 );

    
    
    // did user pass in ticket id?
    $ticket_id = sg_getQueryTicketID();
    $steam_gift_key = "";

    if( ! $recordExists ) {

        if( $ticket_id == "" ) {
                    
            echo "No record found for your Steam ID.<br><br>";
            echo "Please launch the game at least once through Steam ".
                "and then try again.";
            return;
            }
        else {
            // they passed in a ticket ID
            
            if( ! sg_checkTicketID( $ticket_id ) ) {
                echo "Invalid download ticket.";
                return;
                }
            
            // make a new record for them
            $query = "INSERT INTO $tableNamePrefix".
                "mapping( steam_id, ticket_id, steam_gift_key ) ".
                "VALUES( '$steam_id', '$ticket_id', '' );";
            sg_queryDatabase( $query );
            }
        }
    else {
        $steam_gift_key = mysql_result( $result, 0, "steam_gift_key" );
        $ticket_id = mysql_result( $result, 0, "ticket_id" );
        }

    
    if( $steam_gift_key != "" ) {
        sg_showSteamKey( $steam_gift_key );
        return;
        }
    else {
        // no steam key present... should we generate one?

        // FIXME:  verify game ownership with Steam API
        // and then DO NOT generate a key for them if they already own the game
        $ownsGameAlready = false;


        if( $ownsGameAlready ) {
            echo "You already own the game on Steam.<br><br>";

            global $ticketServerURL;

            $downloadLink = $ticketServerURL .
                "?action=show_downloads".
                "&ticket_id=$ticket_id";

            echo "Here's your download link to get the source code:<br>";
            echo "<a href='$downloadLink'>$downloadLink</a>";
            
            return;
            }
        else {
            // give them a key

            sg_queryDatabase( "SET AUTOCOMMIT = 0;" );

            $query = "SELECT steam_gift_key FROM ".
                "$tableNamePrefix"."steam_key_bank ".
                "LIMIT 1 FOR UPDATE;";

            $result = sg_queryDatabase( $query );

            $numRows = mysql_numrows( $result );

            if( $numRows == 0 ) {
                sg_queryDatabase( "COMMIT;" );
                sg_queryDatabase( "SET AUTOCOMMIT = 1;" );
                
                echo "There are no Steam keys left.<br><br>";
                global $helpEmail;
                echo "Please email <b>$helpEmail</b>";
                return;
                }

            $steam_gift_key = mysql_result( $result, 0, 0 );
            
            $query = "DELETE FROM ".
                "$tableNamePrefix"."steam_key_bank ".
                "WHERE steam_gift_key = '$steam_gift_key';";

            sg_queryDatabase( $query );
            sg_queryDatabase( "COMMIT;" );
            sg_queryDatabase( "SET AUTOCOMMIT = 1;" );


            // remember key for them in the map

            $query = "UPDATE $tableNamePrefix"."mapping ".
                "SET steam_gift_key = '$steam_gift_key';";

            sg_queryDatabase( $query );


            sg_showSteamKey( $steam_gift_key );
            return;
            }
        
        }
    }




function sg_getSteamKey() {
    global $tableNamePrefix, $ticketServerURL;

    $ticket_id = sg_getQueryTicketID();


    if( ! sg_checkTicketID( $ticket_id ) ) {
        echo "Invalid download ticket.";
        return;
        }


    // do they already have a steam key?
    $query = "SELECT steam_gift_key from $tableNamePrefix"."mapping ".
        "WHERE ticket_id = '$ticket_id';";

    $result = sg_queryDatabase( $query );
    
    $row = mysql_fetch_array( $result, MYSQL_ASSOC );
        
    $steam_gift_key = $row[ "steam_gift_key" ];

    if( $steam_gift_key != "" ) {
        sg_showSteamKey( $steam_gift_key );
        return;
        }

    // else need to generate one for them

    echo "Log in with Steam to get your free Steam key:<br>";

    sg_showSteamLoginButton( $ticket_id );
    
    }


 
 function sg_showSteamKey( $inKey ) {
     echo "Your steam key is: <b>$inKey</b>";
     }
 


function sg_getQueryTicketID() {
    $ticket_id = sg_requestFilter( "ticket_id", "/[A-HJ-NP-Z2-9\-]+/i" );
    
    $ticket_id = strtoupper( $ticket_id );

    return $ticket_id;
    }



function sg_checkTicketID( $inTicketID ) {
    global $ticketServerURL;

    
    $result = file_get_contents(
            "$ticketServerURL".
            "?action=check_ticket".
            "&ticket_id=$inTicketID" );

    if( $result != "VALID" ) {
        return false;
        }
    else {
        return true;
        }
    }




$sg_mysqlLink;


// general-purpose functions down here, many copied from ticketServer

/**
 * Connects to the database according to the database variables.
 */  
function sg_connectToDatabase() {
    global $databaseServer,
        $databaseUsername, $databasePassword, $databaseName,
        $sg_mysqlLink;
    
    
    $sg_mysqlLink =
        mysql_connect( $databaseServer, $databaseUsername, $databasePassword )
        or sg_operationError( "Could not connect to database server: " .
                              mysql_error() );
    
    mysql_select_db( $databaseName )
        or sg_operationError( "Could not select $databaseName database: " .
                              mysql_error() );
    }


 
/**
 * Closes the database connection.
 */
function sg_closeDatabase() {
    global $sg_mysqlLink;
    
    mysql_close( $sg_mysqlLink );
    }



/**
 * Queries the database, and dies with an error message on failure.
 *
 * @param $inQueryString the SQL query string.
 *
 * @return a result handle that can be passed to other mysql functions.
 */
function sg_queryDatabase( $inQueryString ) {
    global $sg_mysqlLink;
    
    if( gettype( $sg_mysqlLink ) != "resource" ) {
        // not a valid mysql link?
        sg_connectToDatabase();
        }
    
    $result = mysql_query( $inQueryString );
    
    if( $result == FALSE ) {

        $errorNumber = mysql_errno();
        
        // server lost or gone?
        if( $errorNumber == 2006 ||
            $errorNumber == 2013 ||
            // access denied?
            $errorNumber == 1044 ||
            $errorNumber == 1045 ||
            // no db selected?
            $errorNumber == 1046 ) {

            // connect again?
            sg_closeDatabase();
            sg_connectToDatabase();

            $result = mysql_query( $inQueryString, $sg_mysqlLink )
                or sg_operationError(
                    "Database query failed:<BR>$inQueryString<BR><BR>" .
                    mysql_error() );
            }
        else {
            // some other error (we're still connected, so we can
            // add log messages to database
            sg_fatalError( "Database query failed:<BR>$inQueryString<BR><BR>" .
                           mysql_error() );
            }
        }

    return $result;
    }



/**
 * Checks whether a table exists in the currently-connected database.
 *
 * @param $inTableName the name of the table to look for.
 *
 * @return 1 if the table exists, or 0 if not.
 */
function sg_doesTableExist( $inTableName ) {
    // check if our table exists
    $tableExists = 0;
    
    $query = "SHOW TABLES";
    $result = sg_queryDatabase( $query );

    $numRows = mysql_numrows( $result );


    for( $i=0; $i<$numRows && ! $tableExists; $i++ ) {

        $tableName = mysql_result( $result, $i, 0 );
        
        if( $tableName == $inTableName ) {
            $tableExists = 1;
            }
        }
    return $tableExists;
    }



function sg_log( $message ) {
    global $enableLog, $tableNamePrefix;

    if( $enableLog ) {
        $slashedMessage = mysql_real_escape_string( $message );
    
        $query = "INSERT INTO $tableNamePrefix"."log VALUES ( " .
            "'$slashedMessage', CURRENT_TIMESTAMP );";
        $result = sg_queryDatabase( $query );
        }
    }



/**
 * Displays the error page and dies.
 *
 * @param $message the error message to display on the error page.
 */
function sg_fatalError( $message ) {
    //global $errorMessage;

    // set the variable that is displayed inside error.php
    //$errorMessage = $message;
    
    //include_once( "error.php" );

    // for now, just print error message
    $logMessage = "Fatal error:  $message";
    
    echo( $logMessage );

    sg_log( $logMessage );
    
    die();
    }



/**
 * Displays the operation error message and dies.
 *
 * @param $message the error message to display.
 */
function sg_operationError( $message ) {
    
    // for now, just print error message
    echo( "ERROR:  $message" );
    die();
    }


/**
 * Recursively applies the addslashes function to arrays of arrays.
 * This effectively forces magic_quote escaping behavior, eliminating
 * a slew of possible database security issues. 
 *
 * @inValue the value or array to addslashes to.
 *
 * @return the value or array with slashes added.
 */
function sg_addslashes_deep( $inValue ) {
    return
        ( is_array( $inValue )
          ? array_map( 'sg_addslashes_deep', $inValue )
          : addslashes( $inValue ) );
    }



/**
 * Recursively applies the stripslashes function to arrays of arrays.
 * This effectively disables magic_quote escaping behavior. 
 *
 * @inValue the value or array to stripslashes from.
 *
 * @return the value or array with slashes removed.
 */
function sg_stripslashes_deep( $inValue ) {
    return
        ( is_array( $inValue )
          ? array_map( 'sg_stripslashes_deep', $inValue )
          : stripslashes( $inValue ) );
    }



/**
 * Filters a $_REQUEST variable using a regex match.
 *
 * Returns "" (or specified default value) if there is no match.
 */
function sg_requestFilter( $inRequestVariable, $inRegex, $inDefault = "" ) {
    if( ! isset( $_REQUEST[ $inRequestVariable ] ) ) {
        return $inDefault;
        }
    
    $numMatches = preg_match( $inRegex,
                              $_REQUEST[ $inRequestVariable ], $matches );

    if( $numMatches != 1 ) {
        return $inDefault;
        }
        
    return $matches[0];
    }



// this function checks the password directly from a request variable
// or via hash from a cookie.
//
// It then sets a new cookie for the next request.
//
// This avoids storing the password itself in the cookie, so a stale cookie
// (cached by a browser) can't be used to figure out the password and log in
// later. 
function sg_checkPassword( $inFunctionName ) {
    $password = "";
    $password_hash = "";

    $badCookie = false;
    
    
    global $accessPasswords, $tableNamePrefix, $remoteIP, $enableYubikey,
        $passwordHashingPepper;

    $cookieName = $tableNamePrefix . "cookie_password_hash";

    $passwordSent = false;
    
    if( isset( $_REQUEST[ "password" ] ) ) {
        $passwordSent = true;
        
        $password = sg_hmac_sha1( $passwordHashingPepper,
                                  $_REQUEST[ "password" ] );

        // generate a new hash cookie from this password
        $newSalt = time();
        $newHash = md5( $newSalt . $password );
        
        $password_hash = $newSalt . "_" . $newHash;
        }
    else if( isset( $_COOKIE[ $cookieName ] ) ) {
        $password_hash = $_COOKIE[ $cookieName ];
        
        // check that it's a good hash
        
        $hashParts = preg_split( "/_/", $password_hash );

        // default, to show in log message on failure
        // gets replaced if cookie contains a good hash
        $password = "(bad cookie:  $password_hash)";

        $badCookie = true;
        
        if( count( $hashParts ) == 2 ) {
            
            $salt = $hashParts[0];
            $hash = $hashParts[1];

            foreach( $accessPasswords as $truePassword ) {    
                $trueHash = md5( $salt . $truePassword );
            
                if( $trueHash == $hash ) {
                    $password = $truePassword;
                    $badCookie = false;
                    }
                }
            
            }
        }
    else {
        // no request variable, no cookie
        // cookie probably expired
        $badCookie = true;
        $password_hash = "(no cookie.  expired?)";
        }
    
        
    
    if( ! in_array( $password, $accessPasswords ) ) {

        if( ! $badCookie ) {
            
            echo "Incorrect password.";

            sg_log( "Failed $inFunctionName access with password:  ".
                    "$password" );
            }
        else {
            echo "Session expired.";
                
            sg_log( "Failed $inFunctionName access with bad cookie:  ".
                    "$password_hash" );
            }
        
        die();
        }
    else {
        
        if( $passwordSent && $enableYubikey ) {
            global $yubikeyIDs, $yubicoClientID, $yubicoSecretKey,
                $serverSecretKey;
            
            $yubikey = $_REQUEST[ "yubikey" ];

            $index = array_search( $password, $accessPasswords );
            $yubikeyIDList = preg_split( "/:/", $yubikeyIDs[ $index ] );

            $providedID = substr( $yubikey, 0, 12 );

            if( ! in_array( $providedID, $yubikeyIDList ) ) {
                echo "Provided Yubikey does not match ID for this password.";
                die();
                }
            
            
            $nonce = sg_hmac_sha1( $serverSecretKey, uniqid() );
            
            $callURL =
                "http://api2.yubico.com/wsapi/2.0/verify?id=$yubicoClientID".
                "&otp=$yubikey&nonce=$nonce";
            
            $result = trim( file_get_contents( $callURL ) );

            $resultLines = preg_split( "/\s+/", $result );

            sort( $resultLines );

            $resultPairs = array();

            $messageToSignParts = array();
            
            foreach( $resultLines as $line ) {
                // careful here, because = is used in base-64 encoding
                // replace first = in a line (the key/value separator)
                // with #
                
                $lineToParse = preg_replace( '/=/', '#', $line, 1 );

                // now split on # instead of =
                $parts = preg_split( "/#/", $lineToParse );

                $resultPairs[$parts[0]] = $parts[1];

                if( $parts[0] != "h" ) {
                    // include all but signature in message to sign
                    $messageToSignParts[] = $line;
                    }
                }
            $messageToSign = implode( "&", $messageToSignParts );

            $trueSig =
                base64_encode(
                    hash_hmac( 'sha1',
                               $messageToSign,
                               // need to pass in raw key
                               base64_decode( $yubicoSecretKey ),
                               true) );
            
            if( $trueSig != $resultPairs["h"] ) {
                echo "Yubikey authentication failed.<br>";
                echo "Bad signature from authentication server<br>";
                die();
                }

            $status = $resultPairs["status"];
            if( $status != "OK" ) {
                echo "Yubikey authentication failed: $status";
                die();
                }

            }
        
        // set cookie again, renewing it, expires in 24 hours
        $expireTime = time() + 60 * 60 * 24;
    
        setcookie( $cookieName, $password_hash, $expireTime, "/" );
        }
    }
 



function sg_clearPasswordCookie() {
    global $tableNamePrefix;

    $cookieName = $tableNamePrefix . "cookie_password_hash";

    // expire 24 hours ago (to avoid timezone issues)
    $expireTime = time() - 60 * 60 * 24;

    setcookie( $cookieName, "", $expireTime, "/" );
    }

?>