# MXgraphs-php

## Synopsis

This is the mxgraphs algorthm in php. This script will take JSON data as input and return the png format image of the template.

## Code Example

One need to send the post request to the 

> PROTOCOL://HOST/app/index.php

### Request-Header
 - Content-Type = "application/x-www-form-urlencoded"
 
### Request/input Data(Body)
jsonData = VALID_JSON

#### Note:
- valid json should not have validtion rules (any regular expression)
- key and value both should be in double quotes, including numbers.
eg. {
    "x": "100",
    "y": "-50"
}

### Response Header
- Content-Type= "image/png" 