<?php

/**
* OpenTok PHP Library
* http://www.tokbox.com/
*
* Copyright (c) 2011, TokBox, Inc.
* Permission is hereby granted, free of charge, to any person obtaining
* a copy of this software and associated documentation files (the "Software"),
* to deal in the Software without restriction, including without limitation
* the rights to use, copy, modify, merge, publish, distribute, sublicense,
* and/or sell copies of the Software, and to permit persons to whom the
* Software is furnished to do so, subject to the following conditions:
*
* The above copyright notice and this permission notice shall be included
* in all copies or substantial portions of the Software.
*
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
* OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
* FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
* THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
* LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
* OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
* THE SOFTWARE.
*/

namespace OpenTok;

use OpenTok\Session;
use OpenTok\Archive;

define('OPENTOK_SDK_VERSION', '2.0.0-beta');
define('OPENTOK_SDK_USER_AGENT', 'OpenTok-PHP-SDK/' . OPENTOK_SDK_VERSION);

//Generic OpenTok exception. Read the message to get more details
class OpenTokException extends Exception { };
//OpenTok exception related to authentication. Most likely an issue with your API key or secret
class AuthException extends OpenTokException { };
//OpenTok exception related to the HTTP request. Most likely due to a server error. (HTTP 500 error)
class RequestException extends OpenTokException { };

class RoleConstants {
    const SUBSCRIBER = "subscriber"; //Can only subscribe
    const PUBLISHER = "publisher";   //Can publish, subscribe, and signal
    const MODERATOR = "moderator";   //Can do the above along with  forceDisconnect and forceUnpublish
};

class OpenTok {

    private $api_key;
    private $api_secret;
    private $server_url;

    public function __construct($api_key, $api_secret) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        $this->server_url= 'https://api.opentok.com';
    }

    /** - Generate a token
     *
     * $session_id  - If session_id is not blank, this token can only join the call with the specified session_id.
     * $role        - One of the constants defined in RoleConstants. Default is publisher, look in the documentation to learn more about roles.
     * $expire_time - Optional timestamp to change when the token expires. See documentation on token for details.
     * $connection_data - Optional string data to pass into the stream. See documentation on token for details.
     */
    public function generateToken($session_id='', $role='', $expire_time=NULL, $connection_data='') {
        $create_time = time();

        $nonce = microtime(true) . mt_rand();

        if(is_null($session_id) || strlen($session_id) == 0){
            throw new OpenTokException("Null or empty session ID are not valid");
        }

        $sub_session_id = substr($session_id, 2);
        $decoded_session_id="";
        for($i=0;$i<3;$i++){
            $new_session_id = $sub_session_id.str_repeat("=",$i);
            $new_session_id = str_replace("-", "+",$new_session_id);
            $new_session_id = str_replace("_", "/",$new_session_id);
            $decoded_session_id = base64_decode($new_session_id);
            if($decoded_session_id){
                break;
            }
        }
        if (strpos($decoded_session_id, "~")===false){
            throw new OpenTokException("An invalid session ID was passed");
        }else{
            $arr=explode("~",$decoded_session_id);
            if($arr[1]!=$this->api_key){
                throw new OpenTokException("An invalid session ID was passed");
            }
        }

        if(!$role) {
            $role = RoleConstants::PUBLISHER;
        } else if (!in_array($role, array(RoleConstants::SUBSCRIBER,
                RoleConstants::PUBLISHER, RoleConstants::MODERATOR))) {
            throw new OpenTokException("unknown role $role");
        }

        $data_string = "session_id=$session_id&create_time=$create_time&role=$role&nonce=$nonce";
        if(!is_null($expire_time)) {
            if(!is_numeric($expire_time))
                throw new OpenTokException("Expire time must be a number");
            if($expire_time < $create_time)
                throw new OpenTokException("Expire time must be in the future");
            if($expire_time > $create_time + 2592000)
                throw new OpenTokException("Expire time must be in the next 30 days");
            $data_string .= "&expire_time=$expire_time";
        }
        if($connection_data != '') {
            if(strlen($connection_data) > 1000)
                throw new OpenTokException("Connection data must be less than 1000 characters");
            $data_string .= "&connection_data=" . urlencode($connection_data);
        }

        $sig = $this->_sign_string($data_string, $this->api_secret);
        $api_key = $this->api_key;

        return "T1==" . base64_encode("partner_id=$api_key&sig=$sig:$data_string");
    }

    /**
     * Creates a new session.
     * $location - IP address to geolocate the call around.
     * $properties - Optional array, keys are defined in SessionPropertyConstants
     */
    public function createSession($location='', $properties=array()) {
        $properties["location"] = $location;
        $properties["api_key"] = $this->api_key;

        $createSessionResult = $this->_do_request("/session/create", $properties);
        $createSessionXML = @simplexml_load_string($createSessionResult, 'SimpleXMLElement', LIBXML_NOCDATA);
        if(!$createSessionXML) {
            throw new OpenTokException("Failed to create session: Invalid response from server");
        }

        $errors = $createSessionXML->xpath("//error");
        if($errors) {
            $errMsg = $errors[0]->xpath("//@message");
            if($errMsg) {
                $errMsg = (string)$errMsg[0]['message'];
            } else {
                $errMsg = "Unknown error";
            }
            throw new AuthException("Error " . $createSessionXML->error['code'] ." ". $createSessionXML->error->children()->getName() . ": " . $errMsg );
        }
        if(!isset($createSessionXML->Session->session_id)) {
            echo"<pre>";print_r($createSessionXML);echo"</pre>";
            throw new OpenTokException("Failed to create session.");
        }
        $sessionId = $createSessionXML->Session->session_id;

        return new Session($sessionId, null);
    }

    /**
     * Starts archiving an OpenTok 2.0 session.
     * <p>
     * Clients must be actively connected to the OpenTok session for you to successfully start recording an archive.
     * <p>
     * You can only record one archive at a time for a given session. You can only record archives of OpenTok
     * server-enabled sessions; you cannot archive peer-to-peer sessions.
     *
     * @param String $session_id The session ID of the OpenTok session to archive.
     * @param String $name The name of the archive. You can use this name to identify the archive. It is a property
     * of the Archive object, and it is a property of archive-related events in the OpenTok JavaScript SDK.
     * @return OpenTokArchive The OpenTokArchive object, which includes properties defining the archive, including the archive ID.
     */
    public function startArchive($session_id, $name=null) {
        $ar = new OpenTokArchivingInterface($this->api_key, $this->api_secret, $this->server_url);
        return $ar->startArchive($session_id, $name);
    }

    /**
     * Stops an OpenTok archive that is being recorded.
     * <p>
     * Archives automatically stop recording after 90 minutes or when all clients have disconnected from the
     * session being archived.
     *
     * @param String $archive_id The archive ID of the archive you want to stop recording.
     * @return The OpenTokArchive object corresponding to the archive being stopped.
     */
    public function stopArchive($archive_id) {
        $ar = new OpenTokArchivingInterface($this->api_key, $this->api_secret, $this->server_url);
        $archive = $ar->stopArchive($archive_id);
        return new Archive($archive, $ar);
    }

    /**
     * Gets an OpenTokArchive object for the given archive ID.
     *
     * @param String $archive_id The archive ID.
     *
     * @throws OpenTokArchiveException There is no archive with the specified ID.
     * @throws OpenTokArgumentException The archive ID provided is null or an empty string.
     *
     * @return OpenTokArchive The OpenTokArchive object.
     */
    public function getArchive($archive_id) {
        $ar = new OpenTokArchivingInterface($this->api_key, $this->api_secret, $this->server_url);
        return $ar->getArchive($archive_id);
    }

    /**
     * Deletes an OpenTok archive.
     * <p>
     * You can only delete an archive which has a status of "available", "uploaded", or "deleted".
     * Deleting an archive removes its record from the list of archives. For an "available" archive,
     * it also removes the archive file, making it unavailable for download. For a "deleted"
     * archive, the archive remains deleted.
     *
     * @param String $archive_id The archive ID of the archive you want to delete.
     *
     * @return Boolean Returns true on success.
     *
     * @throws OpenTokArchiveException There archive status is not "available", "updated",
     * or "deleted".
     */
    public function deleteArchive($archive_id) {
        $ar = new OpenTokArchivingInterface($this->api_key, $this->api_secret, $this->server_url);
        return $ar->deleteArchive($archive_id);
    }

    /**
     * Returns an OpenTokArchiveList. The <code>items()</code> method of this object returns a list of
     * archives that are completed and in-progress, for your API key.
     *
     * @param integer $offset Optional. The index offset of the first archive. 0 is offset of the most recently
     * started archive. 1 is the offset of the archive that started prior to the most recent archive. If you do not
     * specify an offset, 0 is used.
     * @param integer $count Optional. The number of archives to be returned. The maximum number of archives returned
     * is 1000.
     * @return OpenTokArchiveList An OpenTokArchiveList object. Call the items() method of the OpenTokArchiveList object
     * to return an array of Archive objects.
     */
    public function listArchives($offset=0, $count=null) {
        $ar = new OpenTokArchivingInterface($this->api_key, $this->api_secret, $this->server_url);
        return $ar->listArchives($offset, $count);
    }

    //////////////////////////////////////////////
    //Signing functions, request functions, and other utility functions needed for the OpenTok
    //Server API. Developers should not edit below this line. Do so at your own risk.
    //////////////////////////////////////////////

    /** @internal */
    protected function _sign_string($string, $secret) {
        return hash_hmac("sha1", $string, $secret);
    }

    /** @internal */
    protected function _do_request($url, $data, $auth = array('type' => 'partner')) {
        $url = $this->server_url . $url;

        $dataString = "";
        foreach($data as $key => $value){
            $value = urlencode($value);
            $dataString .= "$key=$value&";
        }

        $dataString = rtrim($dataString,"&");

        switch($auth['type']) {
            case 'token':
                $authString = "X-TB-TOKEN-AUTH: ".$auth['token'];
                break;
            case 'partner':
            default:
                $authString = "X-TB-PARTNER-AUTH: $this->api_key:$this->api_secret";
                break;
        }

        //Use file_get_contents if curl is not available for PHP
        if(function_exists("curl_init")) {
            $ch = curl_init();

            $api_key = $this->api_key;
            $api_secret = $this->api_secret;

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERAGENT, OPENTOK_SDK_USER_AGENT);
            curl_setopt($ch, CURLOPT_HTTPHEADER, Array('Content-type: application/x-www-form-urlencoded'));
            curl_setopt($ch, CURLOPT_HTTPHEADER, Array($authString));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);

            $res = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new RequestException('Request error: ' . curl_error($ch));
            }

            curl_close($ch);

        } else {
            if(function_exists("file_get_contents")) {
                $context_source = array (
                    'http' => array (
                        'method' => 'POST',
                        'header'=> Array(
                            "Content-type: application/x-www-form-urlencoded",
                            $authString,
                            "Content-Length: " . strlen($dataString),
                            'content' => $dataString
                        ),
                        'user_agent' => OPENTOK_SDK_USER_AGENT
                    )
                );
                $context = stream_context_create($context_source);
                $res = @file_get_contents( $url ,false, $context);
            } else {
                throw new RequestException("Your PHP installion neither supports the file_get_contents method nor cURL. Please enable one of these functions so that you can make API calls.");
            }
        }
        return $res;
    }
    
    
    /** - Old functions to be depreciated...
     */
    public function generate_token($session_id='', $role='', $expire_time=NULL, $connection_data='') {
      return $this->generateToken($session_id, $role, $expire_time, $connection_data);
    }
    public function create_session($location='', $properties=array()) {
      return $this->createSession($location, $properties);
    }
    
}