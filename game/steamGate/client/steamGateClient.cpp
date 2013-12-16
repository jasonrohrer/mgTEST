

#include "minorGems/util/SettingsManager.h"
#include "minorGems/util/stringUtils.h"
#include "minorGems/network/web/WebClient.h"
#include "minorGems/formats/encodingUtils.h"
#include "minorGems/util/log/AppLog.h"
#include "minorGems/util/log/FileLog.h"

#include "minorGems/crypto/cryptoRandom.h"
#include "minorGems/crypto/keyExchange/curve25519.h"

#define STEAM_API_NON_VERSIONED_INTERFACES 1

#include "steam/steam_api.h"
//#include "openSteamworks/Steamclient.h"


/////////////////////
// settings:
static const char *steamGateServerURL = 
"http://192.168.0.3/jcr13/steamGate/server.php";

#define macLaunchTarget "CastleDoctrine.app"
#define winLaunchTarget "CastleDoctrine.exe"

// end settings
/////////////////////



#ifdef __mac__

#include <unistd.h>
#include <stdarg.h>

static void launchGame() {
    printf( "Launching game\n" );
    int forkValue = fork();

    if( forkValue == 0 ) {
        // we're in child process, so exec command
        char *arguments[3] = { "open", (char*)macLaunchTarget, NULL };
                
        execvp( "open", arguments );

        // we'll never return from this call
        }
    }


#elif defined(WIN_32)

#include <windows.h>
#include <process.h>

static void launchGame() {
    char *arguments[2] = { (char*)winLaunchTarget, NULL };
    
    _spawnvp( _P_NOWAIT, winLaunchTarget, arguments );
    }


int main();


int WINAPI WinMain( HINSTANCE hInstance, HINSTANCE hPrevInstance,
                    LPSTR lpCmdLine, int nShowCMD ) {
    
    return main();
    }

#endif





static char authTicketCallbackCalled = false;
static char authTicketCallbackError = false;



// a class that does nothing other than register itself as a Steam
// callback listener when it is constructed
class AuthTicketListener {
        
    public:
        
        AuthTicketListener() 
                // initializing this member object is what actually
                // registers our callback
                : mAuthSessionTicketResponse( 
                    this, 
                    &AuthTicketListener::OnAuthSessionTicketResponse ) {
            AppLog::info( "AuthTicketListener instantiated" );
            }

        
        // callback when our AuthSessionTicket is ready
        // this macro declares our callback function for us AND
        // defines our member object
        STEAM_CALLBACK( 
            // class that the callback function is in
            AuthTicketListener, 
            // name of callback function
            OnAuthSessionTicketResponse,
            // type of callback
            GetAuthSessionTicketResponse_t, 
            // name of the member object       
            mAuthSessionTicketResponse );


    };



void AuthTicketListener::OnAuthSessionTicketResponse( 
    GetAuthSessionTicketResponse_t *inCallback ) {
    
    AppLog::info( "AuthTicketListener callback called" );

    authTicketCallbackCalled = true;
            
    if ( inCallback->m_eResult != k_EResultOK ) {
        authTicketCallbackError = true;
        }
    }        








int main() {

    AppLog::setLog( new FileLog( "log_steamGate.txt" ) );
    AppLog::setLoggingLevel( Log::DETAIL_LEVEL );

    char *code = SettingsManager::getStringSetting( "downloadCode" );
    char *email = SettingsManager::getStringSetting( "email" );


    if( code != NULL && email != NULL ) {

        delete [] code;
        delete [] email;
        
        AppLog::info( "We already have saved login info.  Exiting." );
        launchGame();
        return 0;
        }

    
    if( code != NULL ) {
        delete [] code;
        }
     
    if( email != NULL ) {
        delete [] email;
        }
     
    AppLog::info( "No login info found.  "
                  "Executing first-login protocol with server." );

    
    if( ! SteamAPI_Init() ) {
        AppLog::error( "Could not init Steam API." );
        return 0;
        }
    

    CSteamID id = SteamUser()->GetSteamID();
    
    uint64 rawID = id.ConvertToUint64();

    
    
    AppLog::infoF( "Steam ID %lli", rawID );


    unsigned int appID = SteamUtils()->GetAppID();

    AppLog::infoF( "App ID %d", appID );
    

    char loggedOn = SteamUser()->BLoggedOn();
    
    AppLog::infoF( "Logged onto steam: %d", loggedOn );

    char behindNAT = SteamUser()->BIsBehindNAT();
    
    AppLog::infoF( "Behind NAT: %d", behindNAT );

    /*
    EUserHasLicenseForAppResult doesNotOwnApp =
        SteamUser()->UserHasLicenseForApp( id, appID );
    
    AppLog::infoF( "doesNotOwnApp = %d", doesNotOwnApp );
    */

    // construct a listener to add it to Steam's listener pool
    AuthTicketListener *listener = new AuthTicketListener();

    unsigned char authTicketData[2048];
    uint32 authTicketSize = 0;
    HAuthTicket ticketHandle =
        SteamUser()->GetAuthSessionTicket( 
            authTicketData, sizeof(authTicketData), 
            &authTicketSize );
        

    AppLog::infoF( "GetAuthSessionTicket returned %d bytes, handle %d",
                   authTicketSize, ticketHandle );

    AppLog::info( "Waiting for Steam GetAuthSessionTicket callback..." );
    
    while( ! authTicketCallbackCalled ) {
        SteamAPI_RunCallbacks();
        }
    AppLog::info( "...got callback." );

    // de-register listener
    delete listener;
    
    if( authTicketCallbackError ) {
        AppLog::error( "GetAuthSessionTicket callback returned error." );
        SteamAPI_Shutdown();
        return 0;
        }
    

    char *authTicketHex = hexEncode( authTicketData, authTicketSize );
    
    AppLog::infoF( "Auth ticket data:  %s", authTicketHex );


    


    
    unsigned char ourPubKey[32];
    unsigned char ourSecretKey[32];
    
    char gotSecret = 
        getCryptoRandomBytes( ourSecretKey, 32 );
    
    if( ! gotSecret ) {
        AppLog::error( "Failed to get secure random bytes for "
                       "key generation." );
        SteamAPI_Shutdown();
        return 0;
        }
    
    
    curve25519_genPublicKey( ourPubKey, ourSecretKey );
    

    char *ourPubKeyHex = hexEncode( ourPubKey, 32 );
    
    char *webRequest = 
        autoSprintf( 
            "%s?action=get_account"
            "&auth_session_ticket=%s"
            "&client_public_key=%s",
            steamGateServerURL,
            authTicketHex,
            ourPubKeyHex );
            
    delete [] ourPubKeyHex;
    delete [] authTicketHex;

    AppLog::infoF( "Web request to URL: %s", webRequest );
    
    //printf( "Waiting....\n" );
    //int read;
    //scanf( "%d", &read );
    
    int resultLength;
    char *webResult = WebClient::getWebPage( webRequest, &resultLength );
         
    delete [] webRequest;
    

    if( webResult == NULL ) {
        AppLog::error( "Failed to get response from server." );
        
        SteamAPI_Shutdown();
        return 0;
        }
    

    SimpleVector<char *> *tokens = tokenizeString( webResult );
    
    
    
    if( tokens->size() != 3 || 
        strlen( *( tokens->getElement( 0 ) ) ) != 64 ) {
        AppLog::errorF( "Unexpected server response:  %s",
                        webResult );
        
        delete [] webResult;
        for( int i=0; i<tokens->size(); i++ ) {
            delete [] *( tokens->getElement(i) );
            }
        delete tokens;
        
        SteamAPI_Shutdown();
        return 0;
        }


    char *serverPublicKeyHex = *( tokens->getElement( 0 ) );
    email = *( tokens->getElement( 1 ) );
    char *encryptedTicketHex = *( tokens->getElement( 2 ) );


    unsigned char *serverPublicKey = hexDecode( serverPublicKeyHex );
    delete [] serverPublicKeyHex;
    
    if( serverPublicKey == NULL ) {
        AppLog::errorF( "Unexpected server response:  %s",
                        webResult );
        
        delete [] email;
        delete [] encryptedTicketHex;
        delete [] webResult;
        delete tokens;
        
        SteamAPI_Shutdown();
        return 0;
        }
    
    
    unsigned char sharedSecretKey[32];

    curve25519_genSharedSecretKey( sharedSecretKey, ourSecretKey, 
                                   serverPublicKey );
    delete [] serverPublicKey;
    

    int numTicketBytes = strlen( encryptedTicketHex ) / 2;
    unsigned char *encryptedTicket = hexDecode( encryptedTicketHex );
    delete [] encryptedTicketHex;
    
    if( encryptedTicket == NULL ) {
        AppLog::errorF( "Unexpected server response:  %s",
                        webResult );
    
        delete [] email;
        delete [] webResult;
        delete tokens;
        
        SteamAPI_Shutdown();
        return 0;
        }
    
    char *plaintextTicket = new char[ numTicketBytes + 1 ];
    
    for( int i=0; i<numTicketBytes; i++ ) {
        plaintextTicket[i] = encryptedTicket[i] ^ sharedSecretKey[i];
        }

    plaintextTicket[ numTicketBytes ] = '\0';
    delete [] encryptedTicket;


    AppLog::infoF( "Decrypted ticket as:  %s", plaintextTicket );
    
    SettingsManager::setSetting( "email", email );
    SettingsManager::setSetting( "downloadCode", plaintextTicket );
    
    
    delete [] plaintextTicket;
    delete [] email;
    delete [] webResult;
    delete tokens;
    
    SteamAPI_Shutdown();

    launchGame();
    
    return 0;
    }
