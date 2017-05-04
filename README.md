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
- PHP version should be 5.6 or greather
- PHP GD liberary (php5.6-gd - for PHP 5.6) is required to install into ubuntu use: 
> sudo apt-get install php5.6-gd

### Response Header
- Content-Type= "image/png" 

## Upgradation Note:

Core library file of mxgraphs-php located at mxgraphs-php\src\canvas\mxGdCanvas.php
is changed on line 1401 for height(As final daigram will not have same width and height)

### original:
    > $height = round($clip->width + $clip->x) + 1;
### changed to :
    > $height = round($clip->height + $clip->y) + 1;
