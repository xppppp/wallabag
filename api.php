<?php
/**
 * wallabag, self hostable application allowing you to not miss any content anymore
 *
 * @category   wallabag
 * @author     
 * @copyright  2014
 * @license    http://www.wtfpl.net/ see COPYING file
 */

define ('POCHE', '1.9.1');
require 'check_setup.php';
require_once 'inc/poche/global.inc.php';

header('Access-Control-Allow-Origin: *');

# Start session
Session::$sessionName = 'poche';
Session::init();

# Start Poche
$poche = new Poche();

# Parse GET & REFERER vars
$referer = empty($_SERVER['HTTP_REFERER']) ? '' : $_SERVER['HTTP_REFERER'];
$uname = empty($_POST['login']) ? '' : $_POST['login'];
$upass = empty($_POST['password']) ? '' : $_POST['password'];
$url = new Url((isset($_POST['url'])) ? $_POST['url'] : '');

$xobj = new stdClass();
$xobj->status = 1;
$xobj->message = 'Invalid Username or Password';

#Tools::logm('User: ' . $uname . ', password: ' . $upass . ', url: ' . $url->getUrl());

$user = $poche->store->login($uname, Tools::encodeString($upass . $uname), false);
if ($user != array()) {
	Session::login($user['username'], $user['password'], $uname, $upass, false, array('poche_user' => new User($user)));
	$poche->user = new User($user);
	$action = $_POST['action'];
	switch ($action) {
	       case 'add':
	       	    	$poche->actionOnly = true;
			$poche->action('add', $url, $user['id']);
			$xobj->status = 0;
			$xobj->message = 'OK: added '.$url->getUrl();
			break;
		case 'getAccess':
			$token = $poche->user->getConfigValue('token');
			if (empty($token)) {
				$xobj->status = 3;
				$xobj->message = "No access token available.";
			} else {
				$xobj->status = 0;
				$xobj->userId = intval($user['id']);
				$xobj->userToken = $token;
				$xobj->message = "OK";
			}
			break;
		default:
			$xobj->status = 2;
			$xobj->message = "Invalid action (".$action.")";
			break;
	}
}
Tools::renderJson($xobj);
