<?php

date_default_timezone_set('America/Los_Angeles');

define('FGR_API_URL', 'http://foragr.com/api/');

require_once('PHPUnit/Framework.php');
require_once('forager.php');
require_once('json.php');

define('USER_API_KEY', $_SERVER['argv'][count($_SERVER['argv'])-1]);

if (strlen(USER_API_KEY) != 64) {
	die('Syntax: phpunit tests.php <user_api_key>');
}

class ForagrAPITest extends PHPUnit_Framework_TestCase {
	public function test_get_user_name() {
		$fgr = new ForagrAPI(USER_API_KEY, null, FGR_API_URL);
		$response = $fgr->get_user_name();
		
		$this->assertTrue($response !== false);
	}

	public function test_get_target_list() {
		$fgr = new ForagrAPI(USER_API_KEY, null, FGR_API_URL);
		$response = $fgr->get_target_list();
		
		$this->assertTrue($response !== false);
	}
	
	/**
	 * @depends test_get_target_list
	 */
	public function test_get_site_api_key() {
		$fgr = new ForagrAPI(USER_API_KEY, null, FGR_API_URL);

		$response = $fgr->get_target_list();
		$site_id = $response[0]->id;
		
		$response = $fgr->get_site_api_key($site_id);
		
		$this->assertTrue($response !== false);
	}
	
	/**
	 * @depends test_get_target_list
	 */
	public function test_get_site_activity() {
		$fgr = new ForagrAPI(USER_API_KEY, null, FGR_API_URL);

		$response = $fgr->get_target_list();
		$this->assertTrue($response !== false);

		$site_id = $response[0]->id;
		
		$response = $fgr->get_site_activity($site_id);
		$this->assertTrue($response !== false);
	}
	
	/**
	 * @depends test_get_site_activity
	 */
	public function test_get_num_posts() {
		$fgr = new ForagrAPI(USER_API_KEY, null, FGR_API_URL);

		$response = $fgr->get_target_list();
		$this->assertTrue($response !== false);

		$site_id = $response[0]->id;
		
		$response = $fgr->get_site_activity($site_id);
		$this->assertTrue($response !== false);

		$target_id = $response[0]->target->id;

		$response = $fgr->get_num_posts(array($target_id));
		$this->assertTrue($response !== false);
	}
	
	/**
	 * @depends test_get_target_list
	 */
	public function test_get_categories_list() {
		$fgr = new ForagrAPI(USER_API_KEY, null, FGR_API_URL);

		$response = $fgr->get_target_list();
		$this->assertTrue($response !== false);

		$site_id = $response[0]->id;
		
		$response = $fgr->get_categories_list($site_id);
		$this->assertTrue($response !== false);
	}
	
	/**
	 * @depends test_get_target_list
	 */
	public function test_get_target_list() {
		$fgr = new ForagrAPI(USER_API_KEY, null, FGR_API_URL);

		$response = $fgr->get_target_list();
		$this->assertTrue($response !== false);

		$site_id = $response[0]->id;
		
		$response = $fgr->get_target_list($site_id);
		$this->assertTrue($response !== false);
	}
	
	/**
	 * @depends test_get_target_list
	 */
	public function test_get_updated_targets() {
		$fgr = new ForagrAPI(USER_API_KEY, null, FGR_API_URL);

		$response = $fgr->get_target_list();
		$this->assertTrue($response !== false);

		$site_id = $response[0]->id;
		
		$response = $fgr->get_updated_targets($site_id, time());
		$this->assertTrue($response !== false);
	}
	
	/**
	 * @depends test_get_site_activity
	 */
	public function test_get_target_posts() {
		$fgr = new ForagrAPI(USER_API_KEY, null, FGR_API_URL);

		$response = $fgr->get_target_list();
		$this->assertTrue($response !== false);

		$site_id = $response[0]->id;
		
		$response = $fgr->get_site_activity($site_id);
		$this->assertTrue($response !== false);

		$target_id = $response[0]->target->id;

		$response = $fgr->get_target_posts($target_id);
		$this->assertTrue($response !== false);
	}
	
	public function test_json() {
		$subjects = array(
			"[1, 2, 3]",
			"{foo: 'bar'}",
			"{foo: 'bar', 1: true, 2: false, 3: nil, 4: [1, 2, 3]}",
			// "'hello'",
			// "true",
			// "false",
			// "nil",
			// "1",
		);
		
		foreach ($subjects as $v) {
			$json = new JSON;
			
			$this->assertEquals($json->unserialize($v), json_decode($v));
		}
	}
}

?>