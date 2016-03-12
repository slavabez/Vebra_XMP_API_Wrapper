<?php

/**
 * Created by PhpStorm.
 * Project name: Vebra_XML_Api_Wrapper
 * User: Slava Bezgachev, bezgachev@gmail.com
 * Date: 12/03/2016
 * Time: 9:15 AM
 */
class VebraApi
{
    private $_vebraUsername;
    private $_password;
    private $_dataFeedId;
    private $_version;
    private $_debug;
    private $_responseHeaders = "headers.txt";
    private $_tokenFileName = "tokenValue.txt";

    /**
     * VebraApi constructor.
     * @param $parameterArray
     */
    public function __construct($parameterArray)
    {
        $this->_vebraUsername = $parameterArray['username'];
        $this->_password = $parameterArray['password'];
        $this->_dataFeedId = $parameterArray['dataFeedId'];
        $this->_version = $parameterArray['version'];
    }

    public function setDebug($setting)
    {
        $this->_debug = $setting;
    }

    public function getRequestForBranchDetails($branchId)
    {
        return "http://webservices.vebra.com/export/{$this->_dataFeedId}/{$this->_version}/branch/{$branchId}";
    }

    public function getRequestForPropertyList($branchId)
    {
        return "http://webservices.vebra.com/export/{$this->_dataFeedId}/{$this->_version}/branch/{$branchId}/property";
    }

    public function getRequestForPropertyDetails($branchId, $propertyId)
    {
        return "http://webservices.vebra.com/export/{$this->_dataFeedId}/{$this->_version}/branch/{$branchId}/property/{$propertyId}";
    }

    public function getRequestForFilesChangedSince($timestamp)
    {
        $year = date("Y", $timestamp);
        $month = date("m", $timestamp);
        $day = date("d", $timestamp);
        $hours = date("H", $timestamp);
        $minutes = date("i", $timestamp);
        $seconds = date("s", $timestamp);

        return "http://webservices.vebra.com/export/{$this->_dataFeedId}/{$this->_version}/files/{$year}/{$month}/{$day}/{$hours}/{$minutes}/{$seconds};";
    }

    public function loadAllPropertiesThroughBranches()
    {
        $branchList = $this->sendFullRequest($this->getRequestForBranchList());
        $branchPropertyArray = [];

        foreach ($branchList->branch as $branch) {
            $branchPropertyArray[$branch->branchid] = $this->loadAllBranchProperties($branch->url);
        }

        return $branchPropertyArray;
    }

    public function sendFullRequest($url)
    {
        // Check if the latest token expiry is in the past
        $latestExpiry = $this->getLatestTokenExpiryTime();
        if ($this->_debug) {
            print "<br />Latest token expiry date is $latestExpiry...<br />";
        }

        if (strtotime($latestExpiry) > time()) {
            // Latest Token should still be active, retrieve it
            $token = $this->getLatestToken();
            if ($this->_debug) {
                print "<br />It was determined that the latest token is still active. About to send a request to this URL: <b>$url</b> with this token: <b>$token</b><br />";
            }
            return $this->sendRequest($url, $token);
        } else {
            // Create and save a new token
            if ($this->_debug) {
                print "<br />The latest token has now expired, requesting to create a new token...<br />";
            }

            $newToken = $this->requestNewToken();
            if ($newToken) {
                $this->saveTokenToDb($newToken);

                // Test the newly-acquired token, and call the function again recursively if it works
                if ($this->testConnection($newToken)) {
                    return $this->sendFullRequest($url);
                } else {
                    if ($this->_debug) {
                        print("<br />The newly acquired token $newToken did not pass the test...<br />");
                    }
                }
            } else {
                // Token retrieval returned false, error occurred
                if ($this->_debug) {
                    print("<br />There was an error retrieving a new token. Most likely a token mismatch, meaning there should already be a token...<br />");
                }
                return false;
            }
        }
    }

    private function getLatestTokenExpiryTime()
    {
        $db = Database::connection();
        $data = $db->fetchColumn('SELECT expiresOn FROM eaVebraTokens ORDER BY ID DESC LIMIT 1;');
        return $data;
    }

    private function getLatestToken()
    {
        $db = Database::connection();
        $data = $db->fetchColumn('SELECT tokenValue FROM eaVebraTokens ORDER BY ID DESC LIMIT 1;');
        return $data;
    }

    /**
     * @param $request
     * @param $token
     * @return \SimpleXMLElement
     */
    private function sendRequest($request, $token)
    {
        // Initialise a cURL request
        if (!is_string($request)) {
            return "";
        }
        $curl = curl_init($request);

        if ($this->_debug) {
            print "<br />About to pass the <b>$request</b> request below to Vebra using  <b>$token</b> as the token... <br />";
            print "<br />";
        }

        // Set the options, mainly the URL
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . $token));
        // This bit is important
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($curl);
        $errors = curl_error($curl);

        if ($this->_debug) {
            print "<br />Info about the cURL request:<br />";
            print_r(curl_getinfo($curl));
            print "<br />";
            print "This is the errors we get from the cURL request:<br />";
            if (empty($errors)) {
                print "No errors<br>";
            } else {
                print_r($errors);
            }
            print "<br />";
        }
        curl_close($curl);

        return $this->xmlStringToArray($result);
    }

    /** Returns a PHP array from an XML string
     * @param $xmlString
     * @return mixed
     */
    public static function xmlStringToArray($xmlString)
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);

        if ($xml === false) {
            // Error parsing XML, not valid XML
            print "Invalid XML structure, here's the XML that was attempted to convert<br>";
            print "<pre>";
            print_r($xmlString);
            print "</pre>";
            echo "Failed loading XML\n";
            foreach (libxml_get_errors() as $error) {
                echo "\t", $error->message;
            }
        } else {
            $json = json_encode($xml);
            return json_decode($json);
        }
    }

    private function requestNewToken()
    {
        $token = $this->generateNewTokenFile();
        if ($token) {
            $tokenFromTheFile = file_get_contents($this->_tokenFileName);

            if ($this->_debug) {
                print "<pre>";
                print "The value of the token <strong>from the function</strong>: " . $token;
                print "<br>";
                print "The value of the token <strong>from the file</strong>: " . $tokenFromTheFile;
                print "</pre>";
            }
            return $tokenFromTheFile;
        } else {
            return false;
        }
    }

    private function generateNewTokenFile()
    {
        $sampleUrl = $this->getRequestForBranchList();
        $tokenFilename = $this->_tokenFileName;
        $responseFilename = $this->_responseHeaders;

        // Open file helpers
        $fh = fopen($responseFilename, "w");


        $curlRequest = curl_init($sampleUrl);
        curl_setopt($curlRequest, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curlRequest, CURLOPT_USERPWD, $this->_vebraUsername . ":" . $this->_password);
        curl_setopt($curlRequest, CURLINFO_HEADER_OUT, true);
        curl_setopt($curlRequest, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlRequest, CURLOPT_HEADER, 1);
        curl_setopt($curlRequest, CURLOPT_FILE, $fh);
        curl_exec($curlRequest);

        //close headers.txt file
        fclose($fh);

        $headersContent = file($responseFilename, FILE_SKIP_EMPTY_LINES);
        $info = curl_getinfo($curlRequest);

        print "<p>The requests info:</p><pre>";
        print_r($info);
        print "</pre>";
        curl_close($curlRequest);

        if ($info['http_code'] == '401') {
            return false;
        } elseif ($info['http_code'] == '200') {
            //Token received successfully
            foreach ($headersContent as $headerLine) {

                $line = explode(':', $headerLine);
                $header = $line[0];
                $value = trim($line[1]);

                //If the request is successful and we are returned a token
                if ($header == "Token") {
                    $tokenValue = base64_encode($value);
                    if ($this->_debug) {
                        print "<pre>";
                        print "The non-encoded value of the token: " . $tokenValue;
                        print "<br>";
                        print "The encoded value of the token: " . base64_encode($tokenValue);
                        print "</pre>";
                    }
                    $th = fopen($tokenFilename, "w");
                    fwrite($th, $tokenValue);
                    fclose($th);
                    return $tokenValue;
                }
            }
            // Now we should be able to connect
            // VebraConnect::connect($url);
        }


    }

    public function getRequestForBranchList()
    {
        return "http://webservices.vebra.com/export/{$this->_dataFeedId}/{$this->_version}/branch";
    }

    private function saveTokenToDb($token)
    {
        $db = Database::connection();

        // Create timestamp now, timestamp now + 60 minutes
        $createdTime = date('Y-m-d H:i:s', time());
        $expiryTime = date('Y-m-d H:i:s', time() + 60 * 60);

        //Prepare data for insert
        $data = [
            "tokenValue" => $token,
            "createdOn" => $createdTime,
            "expiresOn" => $expiryTime
        ];

        if ($db->insert('eaVebraTokens', $data)) {
            // Success
            if ($this->_debug) {
                print "<br /> Token inserted into the database successfully...<br />";
            }
            return $db->lastInsertId();
        } else {
            if ($this->_debug) {
                print "<pre>";
                print "The \$db object: <br />";
                print_r($db);
                print "</pre>";
            }
            if ($this->_debug) {
                print("Error inserting the token value into the database...");
            }
            return false;
        }
    }

    /**
     * Test connection with the latest token. Tests a token if one was provided, otherwise uses the latest one.
     * @param string $testToken - optional
     * @return false|true
     */
    private function testConnection($testToken = "")
    {
        // Set up a curl request, send
        $sampleUrl = $this->getRequestForBranchList();
        $curl = curl_init($sampleUrl);

        // If a token was provided to test, use it, otherwise get the latest one
        if ($testToken == "") {
            $token = $this->getLatestToken();
        } else {
            $token = $testToken;
        }

        // Set the options, mainly the URL
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . $token));

        // Execute the test query, no need to save the outcome as it's just a test
        curl_exec($curl);

        $info = curl_getinfo($curl);
        curl_close($curl);
        if ($this->_debug) {
            print "<br />Info about the cURL request:<br />";
            print_r($info);
            print "<br />";
        }

        if ($info['http_code'] == '401') {
            // We have an 401 Unauthorized error, most likely the token has expired
            return false;
        } elseif ($info['http_code'] == '200') {
            // Returned proper information
            return true;
        } else {
            // Some other error, return false
            return false;
        }
    }

    public function loadAllBranchProperties($branchListUrl)
    {
        // Property List Array - has prop_id, lastchanged, url
        $propertyList = $this->sendFullRequest($branchListUrl . "/property");

        $propertyDataArray = [];
        if (isset($propertyList->property) && !empty($propertyList->property)) {
            foreach ($propertyList->property as $property) {
                $propertyData = $this->sendFullRequest($property->url);
                array_push($propertyDataArray, $propertyData);
            }
        }

        return $propertyDataArray;
    }

    public function loadAllPropertiesThroughChangedSince()
    {
        return $this->sendFullRequest($this->getRequestForPropertiesChangedSince(0));
    }

    public function getRequestForPropertiesChangedSince($timestamp)
    {
        // Extract all the necessary parts of the date into separate variables, plug into the URL
        $year = date("Y", $timestamp);
        $month = date("m", $timestamp);
        $day = date("d", $timestamp);
        $hours = date("H", $timestamp);
        $minutes = date("i", $timestamp);
        $seconds = date("s", $timestamp);

        return "http://webservices.vebra.com/export/{$this->_dataFeedId}/{$this->_version}/property/{$year}/{$month}/{$day}/{$hours}/{$minutes}/{$seconds}";
    }

    public function loadPropertyDetailsByVebraId($vebraId)
    {
        // Get a list of all, find the ID, get the URL, load the data from it
        $allData = $this->sendFullRequest($this->getRequestForPropertiesChangedSince(0));
        foreach ($allData->property as $property) {
            if ($property->propid == $vebraId) {
                // Load the property
                $url = $property->url;
                return $this->sendFullRequest($url);
            }
        }
        return null;
    }

    public function getRawBranchData()
    {
        $data = $this->sendFullRequest($this->getRequestForBranchList());
        $branchData = [];
        foreach ($data->branch as $b) {
            $branch = $this->sendFullRequest($b->url);
            array_push($branchData, $branch);
        }
        return $branchData;
    }

    /** Used to 'filter' inputs from the data fed because empty objects are generated instead of null, then converted to string 'Object'
     * @param $obj
     * @return null
     */
    private function setIfNotEmpty($obj)
    {
        if (!$this->object_empty($obj)) {
            // Set the object
            return $obj;
        } else {
            // Set to null
            return null;
        }
    }

    private function object_empty($obj)
    {
        // Cast to array, check if empty
        $tmp = (array)$obj;
        if (empty($tmp)) {
            return true;
        } else {
            return false;
        }
    }

}
