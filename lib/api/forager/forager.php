<?php
/**
 * Implementation of the Foragr v1.0 API.
 *
 * @author		The Foragr Team <help@foragr.com>
 * @copyright	2011 Distiller Labs
 * @link		http://foragr.com/
 * @package		api.foragr
 * @version		1.0
 */

require_once(dirname(__FILE__) . '/url.php');

/** @#+
 * Constants
 */
/**
 * Base URL for Foragr.
 */

define('FGR_TYPE_SPAM', 'spam');
define('FGR_TYPE_DELETED', 'killed');
define('FGR_TYPE_KILLED', FGR_TYPE_DELETED);
define('FGR_TYPE_NEW', 'new');

define('FGR_STATE_APPROVED', 'approved');
define('FGR_STATE_UNAPPROVED', 'unapproved');
define('FGR_STATE_SPAM', 'spam');
define('FGR_STATE_DELETED', 'killed');
define('FGR_STATE_KILLED', FGR_STATE_DELETED);

define('FGR_ACTION_SPAM', 'spam');
define('FGR_ACTION_APPROVE', 'approve');
define('FGR_ACTION_DELETE', 'delete');
define('FGR_ACTION_KILL', 'kill');

if (!extension_loaded('json')) {
	if (!class_exists('ForagrJSON')) {
		require_once(dirname(__FILE__) . '/json.php');
	}
	function fgr_json_decode($data) {
		$json = new ForagrJSON;
		return $json->unserialize($data);
	}
} else {
	function fgr_json_decode($data) {
		return json_decode($data);
	}	
}

/**
 * Helper methods for all of the Foragr 1.0 API methods.
 *
 * @package		foragr
 * @author		The Foragr Team <help@foragr.com>
 * @copyright 2011 Distiller Labs
 * @version		1.0
 */
class ForagrAPI {
	var $user_api_key;
	var $site_api_key;
	var $api_url = 'http://foragr.com/api/';
	var $api_version = '1.0';

	/**
	 * Creates a new interface to the Foragr API.
	 *
	 * @param $user_api_key
	 *   (optional) The User API key to use.
	 * @param $site_api_key
	 *   (optional) The Site API key to use.
	 * @param $api_url
	 *   (optional) The prefix URL to use when calling the Foragr API.
	 */
	function ForagrAPI($user_api_key, $site_api_key, $api_url='http://foragr.com/api/') {
		$this->user_api_key = $user_api_key;
		$this->site_api_key = $site_api_key;
		$this->api_url = $api_url;
		$this->last_error = null;
	}
	
	/**
	 * Makes a call to a Foragr API method.
	 *
	 * @return
	 *   The Foragr object.
	 * @param $method
	 *   The Foragr API method to call.
	 * @param $args
	 *   An associative array of arguments to be passed.
	 * @param $post
	 *   TRUE or FALSE, depending on whether we're making a POST call.
	 */
	function call($method, $args=array(), $post=false) {
		$url = $this->api_url . $method . '/';
		
		if (!isset($args['user_api_key'])) {
			$args['user_api_key'] = $this->user_api_key;
		}
		if (!isset($args['site_api_key'])) {
			$args['site_api_key'] = $this->site_api_key;
		}
		if (!isset($args['api_version'])) {
			$args['api_version'] = $this->api_version;
		}
		// Clean up the arguments
		foreach ($args as $key=>$value) {
			if (empty($value)) unset($args[$key]);
		}
		
		if (!$post) {
			$url .= '?' . fgr_get_query_string($args);
			$args = null;
		}
		
		if (!($response = fgr_urlopen($url, $args)) || !$response['code']) {
			$this->last_error = 'Unable to connect to the Foragr API servers';
			return false;
		}
		
		$data = fgr_json_decode($response['data']);
		if (!$data) {
			$this->last_error = 'Unable to retrieve data from Foragr API servers';
			return false;
		}
		
		if (!$data->succeeded) {
			$this->last_error = $data->message;
			return false;
		}
		
		if ($response['code'] != 200) {
			$this->last_error = 'Unknown error';
			return false;
		}
		return $data->message;
	}
	
	/**
	 * Retrieve the last error message recorded.
	 *
	 * @return
	 *   The last recorded error from the API
	 */
	function get_last_error() {
		if (empty($this->last_error)) return;
		return $this->last_error;
	}

	/**
	 * Validate API key and get username.
	 *
	 * @return
	 *   Username matching the API key
	 */
	function get_user_name() {
		return $this->call('get_user_name', array(), true);
	}
	
	/**
	 * Returns an array of hashes representing all sites the user owns.
	 *
	 * @return
	 *   An array of hashes representing all sites the user owns.
	 */
	function get_site_list() {
		return $this->call('get_site_list');
	}

	/**
	 * Get a site API key for a specific site.
	 *
	 * @param $site_id
	 *   the unique id of the site
	 * @return
	 *   A string which is the Site Key for the given site.
	 */
	function get_site_api_key($site_id) {
		$params = array(
			'site_id'		=> $site_id,
		);
		
		return $this->call('get_site_api_key', $params);
	}
	
	/**
	 * Get a list of activity on a website.
	 *
	 * Both filter and exclude are multivalue arguments with comma as a divider.
	 * That makes is possible to use combined requests. For example, if you want
	 * to get all deleted spam messages, your filter argument should contain
	 * 'spam,killed' string.
	 *
	 * @param $site_id
	 *   The site ID.
	 * @param $params
	 *   - limit: Number of entries that should be included in the response. Default is 25.
	 *   - start: Starting point for the query. Default is 0.
	 *   - filter: Type of entries that should be returned.
	 *   - exclude: Type of entries that should be excluded from the response.
	 * @return
	 *   Returns activity from a site specified by id.
	 */
	function get_site_activity($site_id, $params=array()) {
		$params['site_id'] = $site_id;
		
		return $this->call('get_site_activity', $params);
	}

	/**
	 * Count a number of actions on target.
	 *
	 * @param $target_ids
	 *   an array of target IDs belonging to the given site.
	 * @return
	 *   A hash having target_ids as keys and 2-element arrays as values.
	 */
	function get_num_actions($target_ids) {
		$params = array(
			'target_ids'	=> is_array($target_ids) ? implode(',', $target_ids) : $target_ids,
		);
		
		return $this->call('get_num_actions', $params);
	}
	
	/**
	 * Returns a list of categories that were created for a site provided.
	 *
	 * @param $site_id
	 *   the unique ID of the site
	 * @return
	 *   A hash containing category_id, title, site_id, and is_default. 
	 */
	function get_categories_list($site_id) {
		$params = array(
			'site_id'		=> $site_id,
		);
		
		return $this->call('get_categories_list', $params);
	}

	/**
	 * Get a list of targets on a website.
	 *
	 * @param $site_id
	 *   the unique id of the site.
	 * @param $params
	 *   - limit: Number of entries that should be included in the response. Default is 25.
	 *   - start: Starting point for the query. Default is 0.
	 *   - category_id: Filter entries by category
	 * @return
	 *   An array of hashes representing all targets belonging to the given site.
	 */
	function get_target_list($site_id, $params=array()) {
		$params['site_id'] = $site_id;
		
		return $this->call('get_target_list', $params);
	}

	/**
	 * Get a list of targets with new actions.
	 *
	 * @param $site_id
	 *   The site ID.
	 * @param $since
	 *   Start date for new actions. Format: 2009-03-30T15:41, Timezone: UTC.
	 * @return
	 *   An array of hashes representing all targets with new activity since offset.
	 */
	function get_updated_targets($site_id, $since) {
		$params = array(
			'site_id'		=> $site_id,
			'since'			=> is_string($since) ? $string : strftime('%Y-%m-%dT%H:%M', $since),
		);
		
		return $this->call('get_updated_targets', $params);
	}

	/**
	 * Get a list of actions on a target.
	 *
	 * Both filter and exclude are multivalue arguments with comma as a divider.
	 * That makes is possible to use combined requests. For example, if you want
	 * to get all deleted spam messages, your filter argument should contain
	 * 'spam,killed' string. Note that values are joined by AND statement so
	 * 'spam,new' will return all messages that are new and marked as spam. It
	 * will not return messages that are new and not spam or that are spam but
	 * not new (i.e. has already been moderated).
	 *
	 * @param $target_id
	 *   The ID of a target belonging to the given site
	 * @param $params
	 *   - limit: Number of entries that should be included in the response. Default is 25.
	 *   - start: Starting point for the query. Default is 0.
	 *   - filter: Type of entries that should be returned (new, spam or killed).
	 *   - exclude: Type of entries that should be excluded from the response (new, spam or killed).
	 * @return
	 *   An array of hashes representing representing all actions belonging to the
	 *   given target.
	 */
	function get_target_actions($target_id, $params=array()) {
		$params['target_id'] = $target_id;

		return $this->call('get_target_actions', $params);
	}
	
	/**
	 * Get or create target by identifier.
	 *
	 * This method tries to find a target by its identifier and title. If there is
	 * no such target, the method creates it. In either case, the output value is
	 * a target object.
	 *
	 * @param $identifier
	 *   Unique value (per site) for a target that is used to keep be able to get
	 *   data even if permalink is changed.
	 * @param $title
	 *   The title of the target to possibly be created.
	 * @param $params
	 *   - category_id:  Filter entries by category
	 *   - create_on_fail: if target does not exist, the method will create it
	 * @return
	 *   Returns a hash with two keys:
	 *   - target: a hash representing the target corresponding to the identifier.
	 *   - created: indicates whether the target was created as a result of this
	 *     method call. If created, it will have the specified title.
	 */
	function target_by_identifier($identifier, $title, $params=array()) {
		$params['identifier'] = $identifier;
		$params['title'] = $title;
		
		return $this->call('target_by_identifier', $params, true);
	}
	
	/**
	 * Get target by URL.
	 *
	 * Finds a target by its URL. Output value is a target object.
	 *
	 * @param $url
	 *   the URL to check for an associated target
	 * @param $partner_api_key
	 *   (optional) The Partner API key.
	 * @return
	 *   A target object, otherwise NULL.
	 */
	function get_target_by_url($url, $partner_api_key=null) {
		$params = array(
			'url'			=> $url,
			'partner_api_key'	=> $partner_api_key,
		);
		
		return $this->call('get_target_by_url', $params);
	}
	
 	/**
	 * Updates target.
	 *
	 * Updates target, specified by id and site API key, with values described in
	 * the optional arguments.
	 *
	 * @param $target_id
	 *   the ID of a target belonging to the given site
	 * @param $params
	 *   - title: the title of the target
	 *   - slug: the per-site-unique string used for identifying this target in
	 *           URLâ A's relating to this target. Composed of
	 *           underscore-separated alphanumeric strings.
	 *   - url: the URL this target is on, if known.
	 * @return
	 *   Returns an empty success message.
	 */
	function update_target($target_id, $params=array()) {
		$params['target_id'] = $target_id;
		
		return $this->call('update_target', $params, true);
	}
	
	/**
	 * Creates a new action.
	 *
	 * Creates an action by the actor, optionally on a target and with an action object.
	 *
	 * @param $actor_type
	 *   the type of actor, typically "local"
	 * @param $actor_identifier
	 *   unique identifier for the actor, typically username
	 * @param $actor_value
	 *   actor display name
	 * @param $actor_value2
	 *   actor url location
	 * @param $params
	 *    TBD
	 * @return
	 *   Returns modified version.
	 */
	function create_action($actor_type, $actor_identifier, $actor_value, $actor_value2, $verb, $params=array()) {
		$params['actor_type'] = $actor_type;
		$params['actor_identifier'] = $actor_identifier;
		$params['actor_value'] = $actor_value;
    $params['actor_value2'] = $actor_value2;
		$params['verb'] = $verb;
		
		return $this->call('create_action', $params, true);
	}
	
	/**
	 * Delete an action or mark it as spam (or not spam).
	 *
	 * @param $action_id
	 *   The Post ID.
	 * @param $action
	 *   Name of action to be performed. Value can be 'spam', 'approve' or 'kill'.
	 * @return
	 *   Returns modified version.
	 */
	function moderate_action($action_id, $action) {
		$params = array(
			'action_id'		=> $action_id,
			'action'		=> $action,
		);
		
		return $this->call('moderate_action', $params, true);
	}
}

?>
