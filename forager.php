<?php
/*
Plugin Name: Foragr Activity Stream
Plugin URI: http://foragr.com/
Description: Gathers Facebook, Twitter, Disqus, IntenseDebate, and WordPess activity from your users and displays it in a simple activity stream on your blog. Powered by the Foragr web service. Go to <a href="?page=foragr">Foragr Configuration</a> to set up your activity stream and then add the <a href="widgets.php">"Recent Activity - Foragr"</a> widget.
Author: The Foragr Team <help@foragr.com>
Version: 1.2
Author URI: http://foragr.com/
*/

require_once(dirname(__FILE__) . '/lib/wpapi.php');

#define('FGR_DOMAIN',       'local.foragr.com');
define('FGR_DOMAIN',       'foragr.com');
define('FGR_URL',					 'http://' . FGR_DOMAIN . '/');
define('FGR_API_URL',			 FGR_URL . 'api/');
define('FGR_IMPORTER_URL',	FGR_URL . 'importer/');
define('FGR_MEDIA_URL',		 FGR_URL . 'media/');
define('FGR_RSS_PATH',			'/activity.rss');
define('FGR_CAN_EXPORT',		is_file(dirname(__FILE__) . '/export.php'));
if (!defined('FGR_DEBUG')) {
	define('FGR_DEBUG', false);
}
define('FGR_VERSION',		'1.2');

/**
 * Returns an array of all option identifiers used by Foragr.
 */
function fgr_options() {
	return array(
		'fgr_public_key',
		'fgr_secret_key',
		'fgr_sitename',
		'fgr_api_key',
		'fgr_user_api_key',
		# the last sync comment id (from get_site_activity)
		'fgr_last_comment_id',
		'fgr_version',
	);
}

function fgr_plugin_basename($file) {
	$file = dirname($file);

	// From WP2.5 wp-includes/plugin.php:plugin_basename()
	$file = str_replace('\\','/',$file); // sanitize for Win32 installs
	$file = preg_replace('|/+|','/', $file); // remove any duplicate slash
	$file = preg_replace('|^.*/' . PLUGINDIR . '/|','',$file); // get relative path from plugins dir

	if ( strstr($file, '/') === false ) {
		return $file;
	}

	$pieces = explode('/', $file);
	return !empty($pieces[count($pieces)-1]) ? $pieces[count($pieces)-1] : $pieces[count($pieces)-2];
}

function fgr_strip_html($text) {
	// Remove script and style contents before removing all HTML tags
  $text = preg_replace('|<script.*?>.*?</script.*?>|is','',$text);
  $text = preg_replace('|<style.*?>.*?</style.*?>|is','',$text);
  $text = strip_tags($text, FGR_ALLOWED_HTML);
  return $text;
}

if ( !defined('WP_CONTENT_URL') ) {
	define('WP_CONTENT_URL', get_option('siteurl') . '/wp-content');
}
if ( !defined('PLUGINDIR') ) {
	define('PLUGINDIR', 'wp-content/plugins'); // Relative to ABSPATH.	For back compat.
}

define('FGR_PLUGIN_URL', WP_CONTENT_URL . '/plugins/' . fgr_plugin_basename(__FILE__));

/**
 * Response from Foragr get_target API call for activity template.
 *
 * @global	string	$fgr_response
 * @since	1.0
 */
$fgr_response = '';
/**
 * Foragr API instance.
 *
 * @global	string	$fgr_api
 * @since	1.0
 */
$fgr_api = new ForagrWordPressAPI(get_option('fgr_sitename'), get_option('fgr_api_key'), get_option('fgr_user_api_key'));

/**
 * Foragr currently unsupported dev toggle to output activity for this query.
 *
 * @global	bool	$fgr_activity_for_query
 * @since	1.0
 */
$FGR_QUERY_ACTIVITY = false;

/**
 * Foragr array to store post_ids from WP_Query for comment JS output.
 *
 * @global	array	$FGR_QUERY_POST_IDS
 * @since	1.0
 */
$FGR_QUERY_POST_IDS = array();

/**
 * Helper functions.
 */

/**
 * Tests if required options are configured to display the Foragr embed.
 */
function fgr_is_installed() {
	return get_option('fgr_sitename') && get_option('fgr_api_key') && get_option('fgr_user_api_key');
}

function fgr_can_replace() {
	global $id, $post;
	$replace = 'all';

		if ( is_feed() )											 { return false; }
	if ( 'draft' == $post->post_status )	 { return false; }
	if ( !get_option('fgr_sitename') ) { return false; }
	else if ( 'all' == $replace )					{ return true; }

	if ( !isset($post->comment_count) ) {
		$num_comments = 0;
	} else {
		if ( 'empty' == $replace ) {
			// Only get count of comments, not including pings.

			// If there are comments, make sure there are comments (that are not track/pingbacks)
			if ( $post->comment_count > 0 ) {
				// Yuck, this causes a DB query for each post.	This can be
				// replaced with a lighter query, but this is still not optimal.
				$comments = get_approved_comments($post->ID);
				foreach ( $comments as $comment ) {
					if ( $comment->comment_type != 'trackback' && $comment->comment_type != 'pingback' ) {
						$num_comments++;
					}
				}
			} else {
				$num_comments = 0;
			}
		}
		else {
			$num_comments = $post->comment_count;
		}
	}

	return ( ('empty' == $replace && 0 == $num_comments)
		|| ('closed' == $replace && 'closed' == $post->comment_status) );
}

function fgr_manage_dialog($message, $error = false) {
	global $wp_version;

	echo '<div '
		. ( $error ? 'id="fgr_warning" ' : '')
		. 'class="updated fade'
		. ( (version_compare($wp_version, '2.5', '<') && $error) ? '-ff0000' : '' )
		. '"><p><strong>'
		. $message
		. '</strong></p></div>';
}

function fgr_sync_comments($comments) {
	global $wpdb;
	
	// user MUST be logged out during this process
	wp_set_current_user(0);
	
	// we need the target_ids so we can map them to posts
	$target_map = array();
	foreach ( $comments as $comment ) {
		$target_map[$comment->target->id] = null;
	}
	$target_ids = "'" . implode("', '", array_keys($target_map)) . "'";
	
	$results = $wpdb->get_results( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = 'fgr_target_id' AND meta_value IN ({$target_ids}) LIMIT 1");
	foreach ( $results as $result ) {
		$target_map[$result->meta_value] = $result->post_id;
	}
	
	foreach ( $comments as $comment ) {
		$ts = strtotime($comment->created_at);
		if (!$target_map[$comment->target->id] && !empty($comment->target->identifier)) {
			// legacy targets dont already have their meta stored
			foreach ( $comment->target->identifier as $identifier ) {
				// we know identifier starts with post_ID
				if ($post_ID = (int)substr($identifier, 0, strpos($identifier, ' '))) {
					$target_map[$comment->target->id] = $post_ID;
					update_post_meta($post_ID, 'fgr_target_id', $comment->target->id);
				}
			}
		}
		if (!$target_map[$comment->target->id]) {
			// shouldn't ever happen, but we can't be certain
			if (FGR_DEBUG) {
				echo "skipped {$comment->id}: missing target for identifier ({$comment->target->identifier})\n";
			}
			continue;
		}
		if ($wpdb->get_row($wpdb->prepare( "SELECT comment_id FROM $wpdb->commentmeta WHERE meta_key = 'fgr_post_id' AND meta_value = %s LIMIT 1", $comment->id))) {
			// already exists
			if (FGR_DEBUG) {
				echo "skipped {$comment->id}: comment already exists\n";
			}
			continue;
		}
		
		$commentdata = false;

		// first lets check by the id we have stored
		if ($comment->meta) {
			$meta = explode(';', $comment->meta);
			foreach ($meta as $value) {
				$value = explode('=', $value);
				$meta[$value[0]] = $value[1];
			}
			if ($meta['wp_id']) {
				$commentdata = $wpdb->get_row($wpdb->prepare( "SELECT comment_ID, comment_parent FROM $wpdb->comments WHERE comment_ID = %s LIMIT 1", $meta['wp_id']), ARRAY_A);
			}
		}

		// skip comments that were imported but are missing meta information
		if (!$commentdata && $comment->imported) {
			if (FGR_DEBUG) {
				echo "skipped {$comment->id}: comment not found and marked as imported\n";
			}
			continue;
		}

		
		
		// and follow up using legacy Foragr agent
		if (!$commentdata) {
			$commentdata = $wpdb->get_row($wpdb->prepare( "SELECT comment_ID, comment_parent FROM $wpdb->comments WHERE comment_agent LIKE 'Foragr/%%:{$comment->id}' LIMIT 1"), ARRAY_A);
		}
		if (!$commentdata) {
			// Comment doesnt exist yet, lets insert it
			if ($comment->status == 'approved') {
				$approved = 1;
			} elseif ($comment->status == 'spam') {
				$approved = 'spam';
			} else {
				$approved = 0;
			}
			$commentdata = array(
				'comment_post_ID' => $target_map[$comment->target->id],
				'comment_date' => $comment->created_at,
				'comment_date_gmt' => $comment->created_at,
				'comment_content' => apply_filters('pre_comment_content', $comment->message),
				'comment_approved' => $approved,
				'comment_agent' => 'Foragr/1.0('.FGR_VERSION.'):'.intval($comment->id),
				'comment_type' => '',
			);
			if ($comment->is_anonymous) {
				$commentdata['comment_author'] = $comment->anonymous_author->name;
				$commentdata['comment_author_email'] = $comment->anonymous_author->email;
				$commentdata['comment_author_url'] = $comment->anonymous_author->url;
				$commentdata['comment_author_IP'] = $comment->anonymous_author->ip_address;
			} else {
				$commentdata['comment_author'] = $comment->author->display_name;
				$commentdata['comment_author_email'] = $comment->author->email;
				$commentdata['comment_author_url'] = $comment->author->url;
				$commentdata['comment_author_IP'] = $comment->author->ip_address;
			}
			$commentdata = wp_filter_comment($commentdata);
			if ($comment->parent_post) {
				$parent_id = $wpdb->get_var($wpdb->prepare( "SELECT comment_id FROM $wpdb->commentmeta WHERE meta_key = 'fgr_post_id' AND meta_value = %s LIMIT 1", $comment->parent_post));
				if ($parent_id) {
					$commentdata['comment_parent'] = $parent_id;
				}
			}
			$commentdata['comment_ID'] = wp_insert_comment($commentdata);
			if (FGR_DEBUG) {
				echo "inserted {$comment->id}: id is {$commentdata[comment_ID]}\n";
			}
		}
		if (!$commentdata['comment_parent'] && $comment->parent_post) {
			$parent_id = $wpdb->get_var($wpdb->prepare( "SELECT comment_id FROM $wpdb->commentmeta WHERE meta_key = 'fgr_post_id' AND meta_value = %s LIMIT 1", $comment->parent_post));
			if ($parent_id) {
				$wpdb->query($wpdb->prepare( "UPDATE $wpdb->comments SET comment_parent = %s WHERE comment_id = %s", $parent_id, $commentdata['comment_ID']));
				if (FGR_DEBUG) {
					echo "updated {$comment->id}: comment_parent changed to {$parent_id}\n";
				}
				
			}
		}
		$comment_id = $commentdata['comment_ID'];
		update_comment_meta($comment_id, 'fgr_parent_post_id', $comment->parent_post);
		update_comment_meta($comment_id, 'fgr_post_id', $comment->id);
	}

	if( isset($_POST['fgr_api_key']) && $_POST['fgr_api_key'] == get_option('fgr_api_key') ) {
		if( isset($_GET['fgr_sync_action']) && isset($_GET['fgr_sync_comment_id']) ) {
			$comment_parts = explode('=', $_GET['fgr_sync_comment_id']);

			if (!($comment_id = intval($comment_parts[1])) > 0) {
				return;
			}
			
			if( 'wp_id' != $comment_parts[0] ) {
				$comment_id = $wpdb->get_var($wpdb->prepare('SELECT comment_ID FROM ' . $wpdb->comments . ' WHERE comment_post_ID = %d AND comment_agent LIKE %s', intval($post->ID), 'Foragr/1.0:' . $comment_id));
			}
				
			switch( $_GET['fgr_sync_action'] ) {
				case 'mark_spam':
					wp_set_comment_status($comment_id, 'spam');
					echo "<!-- fgr_sync: wp_set_comment_status($comment_id, 'spam') -->";
					break;
				case 'mark_approved':
					wp_set_comment_status($comment_id, 'approve');
					echo "<!-- fgr_sync: wp_set_comment_status($comment_id, 'approve') -->";
					break;
				case 'mark_killed':
					wp_set_comment_status($comment_id, 'hold');
					echo "<!-- fgr_sync: wp_set_comment_status($comment_id, 'hold') -->";
					break;
			}
		}
	}
}

function fgr_request_handler() {
	global $fgr_response;
	global $fgr_api;
	global $post;
	global $wpdb;
	
	if (!empty($_GET['cf_action'])) {
		switch ($_GET['cf_action']) {
			case 'sync_activity':
				if( !( $post_id = $_GET['post_id'] ) ) {
					header("HTTP/1.0 400 Bad Request");
					die();
				}
				// schedule the event for 30 seconds from now in case they
				// happen to make a quick post
				if (FGR_DEBUG) {
					fgr_sync_post($post_id);
					$response = fgr_sync_forum();
					if (!$response) {
						die('// error: '.$fgr_api->get_last_error());
					} else {
						list($last_comment_id, $comments) = $response;
						die('// synced '.$comments.' comments');
					}
				} else {
					wp_schedule_single_event(time(), 'fgr_sync_post', array($post_id));
					wp_schedule_single_event(time(), 'fgr_sync_forum');
					die('// sync scheduled');
				}
			break;
			case 'export_activity':
				if (current_user_can('manage_options') && FGR_CAN_EXPORT) {
					$timestamp = intval($_GET['timestamp']);
					$post_id = intval($_GET['post_id']);
					$limit = 2;
					global $wpdb, $fgr_api;
					$posts = $wpdb->get_results($wpdb->prepare("
						SELECT * 
						FROM $wpdb->posts 
						WHERE post_type != 'revision'
						AND post_status = 'publish'
						AND comment_count > 0
						AND ID > %d
						ORDER BY ID ASC
						LIMIT $limit
					", $post_id));
					$first_post_id = $posts[0]->ID;
					$last_post_id = $posts[(count($posts) - 1)]->ID;
					$max_post_id = $wpdb->get_var($wpdb->prepare("
						SELECT MAX(ID)
						FROM $wpdb->posts 
						WHERE post_type != 'revision'
						AND post_status = 'publish'
						AND comment_count > 0
						AND ID > %d
					", $post_id));
					$eof = (int)($last_post_id == $max_post_id);
					if ($eof) {
						$status = 'complete';
						$msg = 'Your activity has been sent to Foragr and queued for import!<br/><a href="'.FGR_IMPORTER_URL.'" target="_blank">See the status of your import at Foragr</a>';
					}
					else {
						$status = 'partial';
						if (count($posts) == 1) {
							$msg = fgr_i('Processed activity on post #%s&hellip;', $first_post_id);
						}
						else {
							$msg = fgr_i('Processed activity on posts #%s-%s&hellip;', $first_post_id, $last_post_id);
						}
					}
					$result = 'fail';
					$response = null;
					if (count($posts)) {
						include_once(dirname(__FILE__) . '/export.php');
						$wxr = fgr_export_wp($posts);
						$response = $fgr_api->import_wordpress_activity($wxr, $timestamp, $eof);
						if (!($response['group_id'] > 0)) {
							$result = 'fail';
							$msg = '<p class="status fgr-export-fail">'. fgr_i('Sorry, something unexpected happened with the export. Please <a href="#" id="fgr_export_retry">try again</a></p><p>If your API key has changed, you may need to reinstall Foragr (deactivate the plugin and then reactivate it). If you are still having issues, refer to the <a href="%s" onclick="window.open(this.href); return false">WordPress help page</a>.', 'http://foragr.com/help/wordpress'). '</p>';
							$response = $fgr_api->get_last_error();
						}
						else {
							if ($eof) {
								$msg = fgr_i('Your activity has been sent to Foragr and queued for import!<br/><a href="%s" target="_blank">See the status of your import at Foragr</a>', $response['link']);
								
							}
							$result = 'success';
						}
					}
// send AJAX response
					$response = compact('result', 'timestamp', 'status', 'last_post_id', 'msg', 'eof', 'response');
					header('Content-type: text/javascript');
					echo fgr_json_encode($response);
					die();
				}
			break;
			case 'import_activity':
				if (current_user_can('manage_options')) {
					if (!isset($_GET['last_comment_id'])) $last_comment_id = false;
					else $last_comment_id = $_GET['last_comment_id'];

					if ($_GET['wipe'] == '1') {
						$wpdb->query("DELETE FROM `".$wpdb->prefix."commentmeta` WHERE meta_key IN ('fgr_post_id', 'fgr_parent_post_id')");
						$wpdb->query("DELETE FROM `".$wpdb->prefix."comments` WHERE comment_agent LIKE 'Foragr/%%'");
					}

					ob_start();
					$response = fgr_sync_forum($last_comment_id);
					$debug = ob_get_clean();
					if (!$response) {
						$status = 'error';
						$result = 'fail';
						$error = $fgr_api->get_last_error();
						$msg = '<p class="status fgr-export-fail">'.fgr_i('There was an error downloading your comments from Foragr.').'<br/>'.htmlspecialchars($error).'</p>';
					} else {
						list($comments, $last_comment_id) = $response;
						if (!$comments) {
							$status = 'complete';
							$msg = fgr_i('Your comments have been downloaded from Foragr and saved in your local database.');
						} else {
							$status = 'partial';
							$msg = fgr_i('Import in progress (last post id: %s) &hellip;', $last_comment_id);
						}
						$result = 'success';
					}
					$debug = explode("\n", $debug);
					$response = compact('result', 'status', 'comments', 'msg', 'last_comment_id', 'debug');
					header('Content-type: text/javascript');
					echo fgr_json_encode($response);
					die();
				}
			break;
		}
	}
}

add_action('init', 'fgr_request_handler');

function fgr_sync_post($post_id) {
	global $fgr_api, $wpdb;
	
	$post = get_post($post_id);

	// Call update_target to ensure our permalink is up to date
	fgr_update_permalink($post);
}

add_action('fgr_sync_post', 'fgr_sync_post');

function fgr_sync_forum($last_comment_id=false) {
	global $fgr_api, $wpdb;
	
	if ($last_comment_id === false) {
		$last_comment_id = get_option('fgr_last_comment_id');
		if (!$last_comment_id) {
			$last_comment_id = 0;
		}
	}
	if ($last_comment_id) {
		$last_comment_id++;
	}

	//$last_comment_id = 0;

	// Pull comments from API
	$fgr_response = $fgr_api->get_site_activity($last_comment_id);
	if( $fgr_response < 0 || $fgr_response === false ) {
		return false;
	}
	
	// Sync comments with database.
	fgr_sync_comments($fgr_response);
	if ($fgr_response) {
		foreach ($fgr_response as $comment) {
			if ($comment->id > $last_comment_id) $last_comment_id = $comment->id;
		}
		if ($last_comment_id > get_option('fgr_last_comment_id')) {
			update_option('fgr_last_comment_id', $last_comment_id);
		}
	}
	return array(count($fgr_response), $last_comment_id);
}

add_action('fgr_sync_forum', 'fgr_sync_forum');

function fgr_update_permalink($post) {
	global $fgr_api;

	$response = $fgr_api->api->update_target(null, array(
		'target_identifier'	=> fgr_identifier_for_post($post),
		'title' => fgr_title_for_post($post),
		'url' => fgr_link_for_post($post)
	));
	
	update_post_meta($post->ID, 'fgr_target_id', $response->id);
	
	return $response;
}

/**
 *	Compatibility
 */

if (!function_exists ( '_wp_specialchars' ) ) {
function _wp_specialchars( $string, $quote_style = ENT_NOQUOTES, $charset = false, $double_encode = false ) {
	$string = (string) $string;

	if ( 0 === strlen( $string ) ) {
		return '';
	}

	// Don't bother if there are no specialchars - saves some processing
	if ( !preg_match( '/[&<>"\']/', $string ) ) {
		return $string;
	}

	// Account for the previous behaviour of the function when the $quote_style is not an accepted value
	if ( empty( $quote_style ) ) {
		$quote_style = ENT_NOQUOTES;
	} elseif ( !in_array( $quote_style, array( 0, 2, 3, 'single', 'double' ), true ) ) {
		$quote_style = ENT_QUOTES;
	}

	// Store the site charset as a static to avoid multiple calls to wp_load_alloptions()
	if ( !$charset ) {
		static $_charset;
		if ( !isset( $_charset ) ) {
			$alloptions = wp_load_alloptions();
			$_charset = isset( $alloptions['blog_charset'] ) ? $alloptions['blog_charset'] : '';
		}
		$charset = $_charset;
	}
	if ( in_array( $charset, array( 'utf8', 'utf-8', 'UTF8' ) ) ) {
		$charset = 'UTF-8';
	}

	$_quote_style = $quote_style;

	if ( $quote_style === 'double' ) {
		$quote_style = ENT_COMPAT;
		$_quote_style = ENT_COMPAT;
	} elseif ( $quote_style === 'single' ) {
		$quote_style = ENT_NOQUOTES;
	}

	// Handle double encoding ourselves
	if ( !$double_encode ) {
		$string = wp_specialchars_decode( $string, $_quote_style );
		$string = preg_replace( '/&(#?x?[0-9a-z]+);/i', '|wp_entity|$1|/wp_entity|', $string );
	}

	$string = @htmlspecialchars( $string, $quote_style, $charset );

	// Handle double encoding ourselves
	if ( !$double_encode ) {
		$string = str_replace( array( '|wp_entity|', '|/wp_entity|' ), array( '&', ';' ), $string );
	}

	// Backwards compatibility
	if ( 'single' === $_quote_style ) {
		$string = str_replace( "'", '&#039;', $string );
	}

	return $string;
}
}

if (!function_exists ( 'wp_check_invalid_utf8' ) ) {
function wp_check_invalid_utf8( $string, $strip = false ) {
	$string = (string) $string;

	if ( 0 === strlen( $string ) ) {
		return '';
	}

	// Store the site charset as a static to avoid multiple calls to get_option()
	static $is_utf8;
	if ( !isset( $is_utf8 ) ) {
		$is_utf8 = in_array( get_option( 'blog_charset' ), array( 'utf8', 'utf-8', 'UTF8', 'UTF-8' ) );
	}
	if ( !$is_utf8 ) {
		return $string;
	}

	// Check for support for utf8 in the installed PCRE library once and store the result in a static
	static $utf8_pcre;
	if ( !isset( $utf8_pcre ) ) {
		$utf8_pcre = @preg_match( '/^./u', 'a' );
	}
	// We can't demand utf8 in the PCRE installation, so just return the string in those cases
	if ( !$utf8_pcre ) {
		return $string;
	}

	// preg_match fails when it encounters invalid UTF8 in $string
	if ( 1 === @preg_match( '/^./us', $string ) ) {
		return $string;
	}

	// Attempt to strip the bad chars if requested (not recommended)
	if ( $strip && function_exists( 'iconv' ) ) {
		return iconv( 'utf-8', 'utf-8', $string );
	}

	return '';
}
}

if (!function_exists ( 'esc_html' ) ) {
function esc_html( $text ) {
	$safe_text = wp_check_invalid_utf8( $text );
	$safe_text = _wp_specialchars( $safe_text, ENT_QUOTES );
	return apply_filters( 'esc_html', $safe_text, $text );
}
}

if (!function_exists ( 'esc_attr' ) ) {
function esc_attr( $text ) {
	$safe_text = wp_check_invalid_utf8( $text );
	$safe_text = _wp_specialchars( $safe_text, ENT_QUOTES );
	return apply_filters( 'attribute_escape', $safe_text, $text );
}
}

// ugly global hack for comments closing
$EMBED = false;
function fgr_comments_template($value) {
	return;
	// Not used right now
	global $EMBED;
	global $post;
	global $comments;

	if ( !( is_singular() && ( have_comments() || 'open' == $post->comment_status ) ) ) {
		return;
	}

	if ( !fgr_is_installed() || !fgr_can_replace() ) {
		return $value;
	}

	// TODO: If a foragr-activity.php is found in the current template's
	// path, use that instead of the default bundled activity.php
	//return TEMPLATEPATH . '/foragr-activity.php';
	$EMBED = true;
	return dirname(__FILE__) . '/activity.php';
}

// Mark entries in index to replace comments link.
function fgr_comments_number($count) {
		global $post;

	if ( fgr_can_replace() ) {
		return '<span class="fgr-postid" rel="'.htmlspecialchars(fgr_identifier_for_post($post)).'">'.$count.'</span>';
	} else {
		return $count;
	}
}

function fgr_comments_text($comment_text) {
	global $post;

	if ( fgr_can_replace() ) {
		return '<span class="fgr-postid" rel="'.htmlspecialchars(fgr_identifier_for_post($post)).'">View Comments</span>';
	} else {
		return $comment_text;
	}
}

function fgr_bloginfo_url($url) {
	if ( get_feed_link('comments_rss2') == $url ) {
		return 'http://' . strtolower(get_option('fgr_sitename')) . '.' . FGR_DOMAIN . FGR_RSS_PATH;
	} else {
		return $url;
	}
}

function fgr_plugin_action_links($links, $file) {
	$plugin_file = basename(__FILE__);
	if (basename($file) == $plugin_file) {
		$settings_link = '<a href="plugins.php?page=foragr#adv">'.__('Settings', 'foragr').'</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}
add_filter('plugin_action_links', 'fgr_plugin_action_links', 10, 2);

/** 
 * Hide the default comment form to stop spammers by marking all comments
 * as closed.
 */
function fgr_comments_open($open, $post_id=null) {
	global $EMBED;
	if ($EMBED) return false;
	return $open;
}
add_filter('comments_open', 'fgr_comments_open');


// Always add Foragr management page to the admin menu
function fgr_add_pages() {
	if ( function_exists('add_submenu_page') ) {
		add_submenu_page(
	 		'plugins.php',
	 		'Foragr Activity Configuration',
	 		'Foragr',
	 		'manage_options',
	 		'foragr',
	 		'fgr_manage'
	 	);
	} else {
		add_options_page('Foragr Activity Configuration', 'Foragr', 'manage_options', 'foragr', 'fgr_manage');
	}
}
add_action('admin_menu', 'fgr_add_pages', 10);

// a little jQuery goodness to get comments menu working as desired
function fgr_menu_admin_head() {
?>
<script type="text/javascript">
jQuery(function($) {
// fix menu
 	var mc = $('#menu-comments');
	mc.find('a.wp-has-submenu').attr('href', 'plugins.php?page=foragr').end().find('.wp-submenu	li:has(a[href=plugins.php?page=foragr])').prependTo(mc.find('.wp-submenu ul'));
});
</script>
<?php
}
add_action('admin_head', 'fgr_menu_admin_head');

// only active on dashboard
function fgr_dash_comment_counts() {
	global $wpdb;
// taken from wp-includes/comment.php - WP 2.8.5
	$count = $wpdb->get_results("
		SELECT comment_approved, COUNT( * ) AS num_comments 
		FROM {$wpdb->comments} 
		WHERE comment_type != 'trackback'
		AND comment_type != 'pingback'
		GROUP BY comment_approved
	", ARRAY_A );
	$total = 0;
	$approved = array('0' => 'moderated', '1' => 'approved', 'spam' => 'spam');
	$known_types = array_keys( $approved );
	foreach( (array) $count as $row_num => $row ) {
		$total += $row['num_comments'];
		if ( in_array( $row['comment_approved'], $known_types ) )
			$stats[$approved[$row['comment_approved']]] = $row['num_comments'];
	}

	$stats['total_comments'] = $total;
	foreach ( $approved as $key ) {
		if ( empty($stats[$key]) )
			$stats[$key] = 0;
	}
	$stats = (object) $stats;
?>
<style type="text/css">
#dashboard_right_now .inside,
#dashboard_recent_comments div.trackback {
	display: none;
}
</style>
<script type="text/javascript">
jQuery(function($) {
	$('#dashboard_right_now').find('.b-comments a').html('<?php echo $stats->total_comments; ?>').end().find('.b_approved a').html('<?php echo $stats->approved; ?>').end().find('.b-waiting a').html('<?php echo $stats->moderated; ?>').end().find('.b-spam a').html('<?php echo $stats->spam; ?>').end().find('.inside').slideDown();
 	$('#dashboard_recent_activity div.trackback').remove();
 	$('#dashboard_right_now .inside table td.last a, #dashboard_recent_activity .inside .textright a.button').attr('href', 'plugins.php?page=foragr');
});
</script>
<?php
}
function fgr_wp_dashboard_setup() {
	add_action('admin_head', 'fgr_dash_comment_counts');
}
add_action('wp_dashboard_setup', 'fgr_wp_dashboard_setup');

function fgr_manage() {
	if (fgr_does_need_update() && isset($_POST['upgrade'])) {
		fgr_install();
	}
	
	if (fgr_does_need_update() && !isset($_POST['uninstall'])) {
		include_once(dirname(__FILE__) . '/upgrade.php');
	} else {
		include_once(dirname(__FILE__) . '/manage.php');
	}
}

function fgr_admin_head() {
	if (isset($_GET['page']) && $_GET['page'] == 'foragr') {
?>
<link rel='stylesheet' href='<?php echo FGR_PLUGIN_URL; ?>/styles/manage.css' type='text/css' />
<style type="text/css">
.fgr-importing, .fgr-imported, .fgr-import-fail, .fgr-exporting, .fgr-exported, .fgr-export-fail {
	background: url(<?php echo admin_url('images/loading.gif'); ?>) left center no-repeat;
	line-height: 16px;
	padding-left: 20px;
}
p.status {
	padding-top: 0;
	padding-bottom: 0;
	margin: 0;
}
.fgr-imported, .fgr-exported {
	background: url(<?php echo admin_url('images/yes.png'); ?>) left center no-repeat;
}
.fgr-import-fail, .fgr-export-fail {
	background: url(<?php echo admin_url('images/no.png'); ?>) left center no-repeat;
}
</style>
<script type="text/javascript">
jQuery(function($) {
	$('#fgr-tabs li').click(function() {
		$('#fgr-tabs li.selected').removeClass('selected');
		$(this).addClass('selected');
		$('.fgr-main, .fgr-advanced').hide();
		$('.' + $(this).attr('rel')).show();
	});
	if (location.href.indexOf('#adv') != -1) {
		$('#fgr-tab-advanced').click();
	}
	fgr_fire_export();
	fgr_fire_import();
});
fgr_fire_export = function() {
	var $ = jQuery;
	$('#fgr_export a.button, #fgr_export_retry').unbind().click(function() {
		$('#fgr_export').html('<p class="status"></p>');
		$('#fgr_export .status').removeClass('fgr-export-fail').addClass('fgr-exporting').html('Processing...');
		fgr_export_comments();
		return false;
	});
}
fgr_export_comments = function() {
	var $ = jQuery;
	var status = $('#fgr_export .status');
	var export_info = (status.attr('rel') || '0|' + (new Date().getTime()/1000)).split('|');
	$.get(
		'<?php echo admin_url('index.php'); ?>',
		{
			cf_action: 'export_comments',
			post_id: export_info[0],
			timestamp: export_info[1]
		},
		function(response) {
			switch (response.result) {
				case 'success':
					status.html(response.msg).attr('rel', response.last_post_id + '|' + response.timestamp);
					switch (response.status) {
						case 'partial':
							fgr_export_comments();
							break;
						case 'complete':
							status.removeClass('fgr-exporting').addClass('fgr-exported');
							break;
					}
				break;
				case 'fail':
					status.parent().html(response.msg);
					fgr_fire_export();
				break;
			}
		},
		'json'
	);
}
fgr_fire_import = function() {
	var $ = jQuery;
	$('#fgr_import a.button, #fgr_import_retry').unbind().click(function() {
		var wipe = $('#fgr_import_wipe').is(':checked');
		$('#fgr_import').html('<p class="status"></p>');
		$('#fgr_import .status').removeClass('fgr-import-fail').addClass('fgr-importing').html('Processing...');
		fgr_import_comments(wipe);
		return false;
	});
}
fgr_import_comments = function(wipe) {
	var $ = jQuery;
	var status = $('#fgr_import .status');
	var last_comment_id = status.attr('rel') || '0';
	$.get(
		'<?php echo admin_url('index.php'); ?>',
		{
			cf_action: 'import_comments',
			last_comment_id: last_comment_id,
			wipe: (wipe ? 1 : 0)
		},
		function(response) {
			switch (response.result) {
				case 'success':
					status.html(response.msg).attr('rel', response.last_comment_id);
					switch (response.status) {
						case 'partial':
							fgr_import_comments();
							break;
						case 'complete':
							status.removeClass('fgr-importing').addClass('fgr-imported');
							break;
					}
				break;
				case 'fail':
					status.parent().html(response.msg);
					fgr_fire_import();
				break;
			}
		},
		'json'
	);
}
</script>
<?php
// HACK: Our own styles for older versions of WordPress.
		global $wp_version;
		if ( version_compare($wp_version, '2.5', '<') ) {
			echo "<link rel='stylesheet' href='" . FGR_PLUGIN_URL . "/styles/manage-pre25.css' type='text/css' />";
		}
	}
}
add_action('admin_head', 'fgr_admin_head');

function fgr_warning() {
	if ( !get_option('fgr_sitename') && !isset($_POST['forum_url']) && (!isset($_GET['page']) || $_GET['page'] != 'foragr') ) {
		fgr_manage_dialog('Foragr Activity is almost ready. You must <a href="plugins.php?page=foragr">configure the plugin</a> for it to work.', true);
	}

	//if ( !fgr_is_installed() && isset($_GET['page']) && $_GET['page'] == 'foragr' && (!isset($_GET['step']) || !$_GET['step']) && 
	//		 (!isset($_POST['uninstall']) || !$_POST['uninstall']) ) {
	//	fgr_manage_dialog('Foragr Activity has not yet been configured. (<a href="plugins.php?page=foragr">Click here to configure</a>)');
	//}
}

/**
 * Wrapper for built-in __() which pulls all text from
 * the foragr domain and supports variable interpolation.
 */
function fgr_i($text, $params=null) {
	if (!is_array($params))
	{
		$params = func_get_args();
		$params = array_slice($params, 1);
	}
	return vsprintf(__($text, 'foragr'), $params);
}

// insert header and footer requirements
function fgr_header_meta()
{
	if ( !fgr_is_installed() || !fgr_can_replace() ) {
		return;
	}
	/*
		$fbid = trim($tt_like_settings['facebook_id']);
		$fbappid = trim($tt_like_settings['facebook_app_id']);
		$fbpageid = trim($tt_like_settings['facebook_page_id']);

		if ($fbid != $tt_like_settings['default_id'] && $fbid!='') {
	echo '<meta property="fb:admins" content="'.$fbid.'" />'."\n";
		}
		if ($fbappid != $tt_like_settings['default_app_id'] && $fbappid!='') {
	echo '<meta property="fb:app_id" content="'.$fbappid.'" />'."\n";
		}
		if ($fbpageid != $tt_like_settings['default_page_id'] && $fbpageid!='') {
	echo '<meta property="fb:page_id" content="'.$fbpageid.'" />'."\n";
		}
		$image = trim($tt_like_settings['facebook_image']);
		if($image!='') {
			echo '<meta property="og:image" content="'.$image.'" />'."\n";
		}
		echo '<meta property="og:site_name" content="'.htmlspecialchars(get_bloginfo('name')).'" />'."\n";
		if(is_single() || is_page()) {
	$title = the_title('', '', false);
	$php_version = explode('.', phpversion());
	if(count($php_version) && $php_version[0]>=5)
		$title = html_entity_decode($title,ENT_QUOTES,'UTF-8');
	else
		$title = html_entity_decode($title,ENT_QUOTES);
			echo '<meta property="og:title" content="'.htmlspecialchars($title).'" />'."\n";
			echo '<meta property="og:url" content="'.get_permalink().'" />'."\n";
	if($tt_like_settings['use_excerpt_as_description']=='true') {
				$description = trim(get_the_excerpt());
		if($description!='')
					echo '<meta property="og:description" content="'.htmlspecialchars($description).'" />'."\n";
	}
		} else {
			//echo '<meta property="og:title" content="'.get_bloginfo('name').'" />';
			//echo '<meta property="og:url" content="'.get_bloginfo('url').'" />';
			//echo '<meta property="og:description" content="'.get_bloginfo('description').'" />';
		}

		foreach($tt_like_settings['og'] as $k => $v) {
	$v = trim($v);
	if($v!='')
				echo '<meta property="og:'.$k.'" content="'.htmlspecialchars($v).'" />'."\n";
		}
		*/
}

function fgr_footer()
{
	if ( !fgr_is_installed() ) {
		return;
	}
	include(dirname(__FILE__) . '/configure.php');
}

add_action('wp_head', 'fgr_header_meta');
add_action('wp_footer', 'fgr_footer');

// catch original query
function fgr_parse_query($query) {
	add_action('the_posts', 'fgr_add_request_post_ids', 999);
}
add_action('parse_request', 'fgr_parse_query');

// track the original request post_ids, only run once
function fgr_add_request_post_ids($posts) {
	fgr_add_query_posts($posts);
	remove_action('the_posts', 'fgr_log_request_post_ids', 999);
	return $posts;
}

function fgr_maybe_add_post_ids($posts) {
	global $FGR_QUERY_ACTIVITY;
	if ($FGR_QUERY_ACTIVITY) {
		fgr_add_query_posts($posts);
	}
	return $posts;
}
add_action('the_posts', 'fgr_maybe_add_post_ids');

function fgr_add_query_posts($posts) {
	global $FGR_QUERY_POST_IDS;
	if (count($posts)) {
		foreach ($posts as $post) {
			$post_ids[] = intval($post->ID);
		}
		$FGR_QUERY_POST_IDS[md5(serialize($post_ids))] = $post_ids;
	}
}

// check to see if the posts in the loop match the original request or an explicit request, if so output the JS
function fgr_loop_end($query) {
	if (true) {
		return;
	}
	global $FGR_QUERY_POST_IDS;
	foreach ($query->posts as $post) {
		$loop_ids[] = intval($post->ID);
	}
	$posts_key = md5(serialize($loop_ids));
	if (isset($FGR_QUERY_POST_IDS[$posts_key])) {
		fgr_output_loop_comment_js($FGR_QUERY_POST_IDS[$posts_key]);
	}
}
add_action('loop_end', 'fgr_loop_end');

// if someone has a better hack, let me know
// prevents duplicate calls to count.js
$_HAS_COUNTS = false;

function fgr_output_loop_comment_js($post_ids = null) {
	global $_HAS_COUNTS;
	if ($_HAS_COUNTS) return;
	$_HAS_COUNTS = true;
	if (false) { //if (count($post_ids)) {
?>
	<script type="text/javascript">
	// <![CDATA[
		var fgr_sitename = '<?php echo strtolower(get_option('fgr_sitename')); ?>';
		var fgr_domain = '<?php echo FGR_DOMAIN; ?>';
		(function () {
			var nodes = document.getElementsByTagName('span');
			for (var i = 0, url; i < nodes.length; i++) {
				if (nodes[i].className.indexOf('fgr-postid') != -1) {
					nodes[i].parentNode.setAttribute('data-fgr-identifier', nodes[i].getAttribute('rel'));
					url = nodes[i].parentNode.href.split('#', 1);
					if (url.length == 1) url = url[0];
					else url = url[1]
					nodes[i].parentNode.href = url + '#fgr_target';
				}
			}
			var s = document.createElement('script'); s.async = true;
			s.type = 'text/javascript';
			s.src = 'http://' + fgr_domain + '/sites/' + fgr_sitename + '/count.js';
			(document.getElementsByTagName('HEAD')[0] || document.getElementsByTagName('BODY')[0]).appendChild(s);
		}());
	//]]>
	</script>
<?php
	}
}

// UPDATE Foragr when a permalink changes

$fgr_prev_permalinks = array();

function fgr_prev_permalink($post_id) {
// if post not published, return
	$post = &get_post($post_id);
	if ($post->post_status != 'publish') {
		return;
	}
	global $fgr_prev_permalinks;
	$fgr_prev_permalinks['post_'.$post_id] = get_permalink($post_id);
}
add_action('pre_post_update', 'fgr_prev_permalink');

function fgr_check_permalink($post_id) {
	global $fgr_prev_permalinks;
	if (!empty($fgr_prev_permalinks['post_'.$post_id]) && $fgr_prev_permalinks['post_'.$post_id] != get_permalink($post_id)) {
		$post = get_post($post_id);
		fgr_update_permalink($post);
	}
}
add_action('edit_post', 'fgr_check_permalink');

add_action('admin_notices', 'fgr_warning');

//
// Our publishing of posts and comments
//


function fgr_publish_post($post_id) {
  global $fgr_api;
  
  $post = get_post($post_id);
  $author = get_userdata($post->post_author);
  $response = $fgr_api->api->create_action(
    'local', fgr_identifier_for_user($author), fgr_display_for_user($author), fgr_link_for_user($author), 'post',
    array(
	    'target_type' => 'article',
	    'target_identifier' => fgr_identifier_for_post($post),
	    'target_url' => fgr_link_for_post($post),
	    'target_value' => fgr_title_for_post($post),
	    'target_value2' => fgr_summary_for_post($post),
    )
  );
}
add_action('publish_post', 'fgr_publish_post');

function fgr_comment_post($comment_id, $status) {
  global $fgr_api;
  
  if ($status != 'approve' && $status != 1) {
  	return;
  }
  $comment = get_comment($comment_id);
  if ( $comment->comment_type == 'trackback' || $comment->comment_type == 'pingback' ) {
    return;
  }
  $post = get_post($comment->comment_post_ID);
  if ($comment->user_id) {
  	$author = get_userdata($comment->user_id);
  	$author_identifier = fgr_identifier_for_user($author);
  	$author_display = fgr_display_for_user($author);
  	$author_link = fgr_link_for_user($author);
  } else {
  	$author_identifier = 'email '.$comment->comment_author_email;
    $author_display = $comment->comment_author;
    $author_link = $comment->comment_author_url;
  }
  $response = $fgr_api->api->create_action(
    'local', $author_identifier, $author_display, $author_link, 'post',
    array(
      'target_type' => 'article',
      'target_identifier' => fgr_identifier_for_post($post),
      'target_url' => fgr_link_for_post($post),
      'target_value' => fgr_title_for_post($post),
      'target_value2' => fgr_summary_for_post($post),
      'action_object_type' => 'comment',
      'action_object_identifier' => fgr_identifier_for_comment($comment),
      'action_object_url' => fgr_link_for_comment($comment),
      'action_object_value' => fgr_text_for_comment($comment)
    )
  );
}
add_action('comment_post', 'fgr_comment_post', 10, 2);
add_action('wp_set_comment_status', 'fgr_comment_post', 10, 2);

// Only replace activity if the fgr_sitename option is set.
// BIG TODO - switch this to the things we care about
//add_filter('comments_template', 'fgr_comments_template');
//add_filter('comments_number', 'fgr_comments_text');
//add_filter('get_comments_number', 'fgr_comments_number');
//add_filter('bloginfo_url', 'fgr_bloginfo_url');

//
// Our widget registration
//

add_action('widgets_init', 'fgr_activity_register');
register_activation_hook( __FILE__, 'fgr_activity_activate');
register_deactivation_hook( __FILE__, 'fgr_activity_deactivate');

function fgr_activity_activate() {
	$data = array('title' => 'Recent Activity', 'style' => 'bullet', 'count' => 5);
	if (!get_option('fgr_activity')) {
		add_option('fgr_activity' , $data);
	} else {
		update_option('fgr_activity' , $data);
	}
}
function fgr_activity_deactivate() {
	delete_option('fgr_activity');
}
function fgr_activity_control() {
	$data = get_option('fgr_activity');
	?>
		<p><label>Title: <input name="fgr_activity_title" type="text" value="<?php echo (isset($data['title']) && $data['title']) ? $data['title'] : 'Recent Activity' ; ?>" /></label></p>
		<p><label>Style: <input name="fgr_activity_style" type="text" value="<?php echo (isset($data['style']) && $data['style']) ? $data['style'] : 'bullet'; ?>" /></label></p>
		<p><label>Count: <input name="fgr_activity_count" type="text" value="<?php echo (isset($data['count']) && $data['count']) ? $data['count'] : 5; ?>" /></label></p>
	<?php
	if (isset($_POST['fgr_activity_style'])) {
		if (function_exists('esc_attr')) {
			$data['title'] = esc_attr($_POST['fgr_activity_title']);
			$data['style'] = esc_attr($_POST['fgr_activity_style']);
			$data['count'] = esc_attr($_POST['fgr_activity_count']);
		} else {
			$data['title'] = attribute_escape($_POST['fgr_activity_title']);
			$data['style'] = attribute_escape($_POST['fgr_activity_style']);
			$data['count'] = attribute_escape($_POST['fgr_activity_count']);
		}
		update_option('fgr_activity', $data);
	}
}
function fgr_activity_widget($args) {
	$data = get_option('fgr_activity');
	global $FGR_TITLE, $FGR_STYLE, $FGR_COUNT;
	$FGR_TITLE = (isset($data['title']) && $data['title']) ? $data['title'] : 'Recent Activity';
	$FGR_STYLE = (isset($data['style']) && $data['style']) ? $data['style'] : 'bullet';
	$FGR_COUNT = (isset($data['count']) && $data['count']) ? $data['count'] : 5;
	echo $args['before_widget'];
	echo $args['before_title'] . $FGR_TITLE . $args['after_title'];
	$EMBED = true;
	include(dirname(__FILE__) . '/activity.php');
	echo $args['after_widget'];
}
function fgr_activity_register() {
	if (function_exists('wp_register_sidebar_widget')) {
		wp_register_sidebar_widget('fgr_activity', 'Recent Activity - Foragr', 'fgr_activity_widget',
															 array('description' => 'The most recent activity on your site'));
		wp_register_widget_control('fgr_activity', 'Foragr Activity', 'fgr_activity_control');
	} else {
		register_sidebar_widget('Recent Activity', 'fgr_activity_widget');
		register_widget_control('Foragr Activity', 'fgr_activity_control');
	}
}

/**
 * JSON ENCODE for PHP < 5.2.0
 * Checks if json_encode is not available and defines json_encode
 * to use php_json_encode in its stead
 * Works on iteratable objects as well - stdClass is iteratable, so all WP objects are gonna be iteratable
 */ 
if(!function_exists('fgr_json_encode')) {
	function fgr_json_encode($data) {
// json_encode is sending an application/x-javascript header on Joyent servers
// for some unknown reason.
// 		if(function_exists('json_encode')) { return json_encode($data); }
// 		else { return fgrjson_encode($data); }
		return fgrjson_encode($data);
	}
	
	function fgrjson_encode_string($str) {
		if(is_bool($str)) { 
			return $str ? 'true' : 'false'; 
		}
	
		return str_replace(
			array(
				'"'
				, '/'
				, "\n"
				, "\r"
			)
			, array(
				'\"'
				, '\/'
				, '\n'
				, '\r'
			)
			, $str
		);
	}

	function fgrjson_encode($arr) {
		$json_str = '';
		if (is_array($arr)) {
			$pure_array = true;
			$array_length = count($arr);
			for ( $i = 0; $i < $array_length ; $i++) {
				if (!isset($arr[$i])) {
					$pure_array = false;
					break;
				}
			}
			if ($pure_array) {
				$json_str = '[';
				$temp = array();
				for ($i=0; $i < $array_length; $i++) {
					$temp[] = sprintf("%s", fgrjson_encode($arr[$i]));
				}
				$json_str .= implode(',', $temp);
				$json_str .="]";
			}
			else {
				$json_str = '{';
				$temp = array();
				foreach ($arr as $key => $value) {
					$temp[] = sprintf("\"%s\":%s", $key, fgrjson_encode($value));
				}
				$json_str .= implode(',', $temp);
				$json_str .= '}';
			}
		}
		else if (is_object($arr)) {
			$json_str = '{';
			$temp = array();
			foreach ($arr as $k => $v) {
				$temp[] = '"'.$k.'":'.fgrjson_encode($v);
			}
			$json_str .= implode(',', $temp);
			$json_str .= '}';
		}
		else if (is_string($arr)) {
			$json_str = '"'. fgrjson_encode_string($arr) . '"';
		}
		else if (is_numeric($arr)) {
			$json_str = $arr;
		}
		else if (is_bool($arr)) {
			$json_str = $arr ? 'true' : 'false';
		}
		else {
			$json_str = '"'. fgrjson_encode_string($arr) . '"';
		}
		return $json_str;
	}
}

// Single Sign-on Integration

function fgr_sso() {
	if (!($key = get_option('fgr_secret_key')) || !($public = get_option('fgr_public_key'))) {
		// sso is not configured
		return array();
	}
	global $current_user, $fgr_api;
	get_currentuserinfo();
	if ($current_user->ID) {
		$avatar_tag = get_avatar($current_user->ID);
		$avatar_data = array();
		preg_match('/(src)=((\'|")[^(\'|")]*(\'|"))/i', $avatar_tag, $avatar_data);
		$avatar = str_replace(array('"', "'"), '', $avatar_data[2]);
		$user_data = array(
			'username' => $current_user->display_name,
			'id' => $current_user->ID,
			'avatar' => $avatar,
			'email' => $current_user->user_email,
		);
	}
	else {
		$user_data = array();
	}
	$user_data = base64_encode(fgr_json_encode($user_data));
	$time = time();
	$hmac = fgr_hmacsha1($user_data.' '.$time, $key);

	$payload = $user_data.' '.$hmac.' '.$time;
	
	return array('remote_auth_s3'=>$payload, 'api_key'=>$public);
}

// from: http://www.php.net/manual/en/function.sha1.php#39492
//Calculate HMAC-SHA1 according to RFC2104
// http://www.ietf.org/rfc/rfc2104.txt
function fgr_hmacsha1($data, $key) {
		$blocksize=64;
		$hashfunc='sha1';
		if (strlen($key)>$blocksize)
				$key=pack('H*', $hashfunc($key));
		$key=str_pad($key,$blocksize,chr(0x00));
		$ipad=str_repeat(chr(0x36),$blocksize);
		$opad=str_repeat(chr(0x5c),$blocksize);
		$hmac = pack(
								'H*',$hashfunc(
										($key^$opad).pack(
												'H*',$hashfunc(
														($key^$ipad).$data
												)
										)
								)
						);
		return bin2hex($hmac);
}
 
function fgr_identifier_for_post($post) {
	return $post->ID . ' ' . $post->guid;
}

function fgr_title_for_post($post) {
	$title = get_the_title($post);
	$title = strip_tags($title, FGR_ALLOWED_HTML);
	return $title;
}

function fgr_summary_for_post($post) {
	setup_postdata($post);
	$summary = get_the_content();
	$summary = fgr_strip_html($summary);
	return $summary;
}

function fgr_link_for_post($post) {
	return get_permalink($post);
}

function fgr_identifier_for_user($user) {
	return $user->user_login;
}

function fgr_display_for_user($user) {
  return $user->display_name;
}

function fgr_link_for_user($user) {
  return get_author_posts_url($user->ID);
}

function fgr_identifier_for_comment($comment) {
  return $comment->comment_post_ID  . ' ' . $comment->comment_ID;
}

function fgr_link_for_comment($comment) {
	return get_comment_link($comment);
}

function fgr_text_for_comment($comment) {
	$text = $comment->comment_content;
  $text = strip_tags($text, FGR_ALLOWED_HTML);
  return $text;
}

function fgr_does_need_update() {
	$version = (string)get_option('fgr_version');
	if (empty($version)) {
		$version = '0';
	}
	
	if (version_compare($version, '1.0', '<')) {
		return true;
	}
	
	return false;
}

function fgr_install($allow_database_install=true) {
	global $wpdb, $userdata;

	$version = (string)get_option('fgr_version');
	if (!$version) {
		$version = '0';
	}

	if ($allow_database_install)
	{
		fgr_install_database($version);
	}
	
	if (version_compare($version, FGR_VERSION, '=')) return;

	update_option('fgr_version', FGR_VERSION);
}

/**
 * Initializes the database if it's not already present.
 */
function fgr_install_database($version=0) {
	global $wpdb;
	
	fgr_uninstall_database($version);
	$wpdb->query("CREATE INDEX fgr_dupecheck ON `".$wpdb->prefix."commentmeta` (meta_key, meta_value(11));");
}
function fgr_uninstall_database($version=0) {
	global $wpdb;
	
	$wpdb->query("DROP INDEX fgr_dupecheck ON `".$wpdb->prefix."commentmeta`;");
}
?>
