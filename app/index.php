<?php
/**
 * Get the data from command line argument and store it in the $_GET for further processing
 */

//parse_str(implode('&', array_slice($argv, 1)), $_GET);
$ds = DIRECTORY_SEPARATOR;
DEFINE('BASE_DIR', realpath(dirname(__FILE__)  . $ds . '..') . $ds);
//DEFINE('IMAGE_PATH', 'http://staging.kumolus.com/assets');
$protocol = strtolower(substr($_SERVER["SERVER_PROTOCOL"],0,5))=='https://'?'https://':'http://';
DEFINE('IMAGE_PATH', $protocol.$_SERVER['HTTP_HOST'].'/Icons');



include_once(BASE_DIR.'app'.$ds.'modules'.$ds.'decoder'.$ds.'decode_process.php');
$decodeObj = new DecodeProcess();

// if (isset($_POST['jsonData']))
// {	
//     $decodeObj->decode($_POST['jsonData']);
// }
// else  
// {
// 	echo 'Not valid input'; exit;
// }
 
$decodeObj->decode();

?>
