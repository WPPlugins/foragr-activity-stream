<?php
/**
 * Implementation of the Foragr API designed for WordPress.
 *
 * @author		The Foragr Team <help@foragr.com>
 * @link		http://foragr.com/
 * @package		api.wpapi
 * @version		1.0
 */

require_once(ABSPATH.WPINC.'/http.php');
require_once(dirname(__FILE__) . '/api/forager/forager.php');
/** @#+
 * Constants
 */
/**
 * Allowed HTMl in posts
 */
define('FGR_ALLOWED_HTML', '<b><u><i><h1><h2><h3><code><blockquote><br><hr>');

/**
 * Helper methods for all of the Foragr API methods.
 *
 * @author		The Foragr Team <help@foragr.com>
 * @copyright 2011 Distiller Labs
 * @version		1.0
 */
class ForagrWordPressAPI {
	var $short_name;
	var $site_api_key;

	function ForagrWordPressAPI($short_name=null, $site_api_key=null, $user_api_key=null) {
		$this->short_name = $short_name;
		$this->site_api_key = $site_api_key;
		$this->user_api_key = $user_api_key;
		$this->api = new ForagrAPI($user_api_key, $site_api_key, FGR_API_URL);
	}

	function get_last_error() {
		return $this->api->get_last_error();
	}

	function get_user_api_key($username, $password) {
		$response = $this->api->call('get_user_api_key', array(
			'username'	=> $username,
			'password'	=> $password,
		), true);
		return $response;
	}

	function get_site_list($user_api_key) {
		$this->api->user_api_key = $user_api_key;
		return $this->api->get_site_list();
	}

	function get_site_api_key($user_api_key, $id) {
		$this->api->user_api_key = $user_api_key;
		return $this->api->get_site_api_key($id);
	}
	
	function get_site_activity($start_id=0) {
		$response = $this->api->get_site_activity(null, array(
			'filter' => 'approved',
			'start_id' => $start_id,
			'limit' => 100,
			'order' => 'asc',
			'full_info' => 1
		));
		return $response;
	}

	function import_wordpress_activity($wxr, $timestamp, $eof=true) {
		$http = new WP_Http();
		$response = $http->request(
			FGR_IMPORTER_URL . 'api/import-wordpress-activity/',
			array(
				'method' => 'POST',
				'body' => array(
					'site_shortname' => $this->short_name,
					'site_api_key' => $this->site_api_key,
					'response_type'	=> 'php',
					'wxr' => $wxr,
					'timestamp' => $timestamp,
					'eof' => (int)$eof
				)
			)
		);
		if ($response->errors) {
			// hack
			$this->api->last_error = $response->errors;
			return -1;
		}
		$data = unserialize($response['body']);
		if (!$data || $data['stat'] == 'fail') {
			return -1;
		}
		
		return $data;
	}
}

?>
