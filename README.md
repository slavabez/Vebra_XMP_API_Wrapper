# Vebra XMP API Wrapper using PHP
A wrapper class to use with the Vebra Property's XML API service.

A quick and dirty class I made for reading data from Vebra Property feed. Extracts the XML feed and converts it to JSON.

### Usage:

###### Step 1.

Implement the following methods:

  - saveTokenToDB($toke) - needs to save the token along with an expiry time to your database of choice
  - getLatestTokenExpiryTime() - returns timestamp of the expiry time
  - getLatestToken() - returns the alphanumeric token value of the latest token
  
###### Step 2.

Create an instance of class VebraApi, follow this example (latest Vebra API version as of writing this is 'v9'):

```php
    $constructorValues = [
        'username' => 'YOUR_VEBRA_USERNAME',
        'password' => 'YOUR_VEBRA_PASSWORD',
        'dataFeedId' => 'YOUR_DATA_FEED_ID',
        'version' => 'v9'
        ];
        
    $api = new VebraApi($constructorValues);

```

###### Step 3.

Use the sendFullRequest method to get data. The method takes URLs as the parameter. The URLs can be generated through the getRequestForXXX methods.

Example call that creates a URL to request for all properties and calls it:

```php
    $allProperties = $api->sendFullRequest($api->getRequestForPropertiesChangedSince(0));
```

The getRequestForPropertiesChangedSince method uses a UNIX timestamp as the parameter and returns all properties that have been flagged as modified since that timestamp. Passing 0 to it will trigger it to send all properties

To get details about a property you need to pass it's URL to the sendFullRequest method.