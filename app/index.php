<?php
/**
 * Get the data from command line argument and store it in the $_GET for further processing
 */

//parse_str(implode('&', array_slice($argv, 1)), $_GET);

$ds = DIRECTORY_SEPARATOR;
DEFINE('BASE_DIR', realpath(dirname(__FILE__)  . $ds . '..') . $ds);
//DEFINE('IMAGE_PATH', 'http://staging.kumolus.com/assets');
DEFINE('IMAGE_PATH', 'http://localhost/Icons');



include_once(BASE_DIR.'app\modules\decoder\decode_process.php');
$decodeObj = new DecodeProcess();
//if(isset($_GET['imgData']))  {
	//decode($_GET['imgData']);
$decodeObj->decode();
// } else {
// 	echo 'Not valid input'; exit;
// }
?>
