<?php
global $fgr_api;

require(ABSPATH . 'wp-includes/version.php');

if ( !current_user_can('manage_options') ) {
	die();
}

if(isset($_POST['fgr_username'])) {
	$_POST['fgr_username'] = stripslashes($_POST['fgr_username']);
}

if(isset($_POST['fgr_password'])) {
	$_POST['fgr_password'] = stripslashes($_POST['fgr_password']);
}

// HACK: For old versions of WordPress
if ( !function_exists('wp_nonce_field') ) {
	function wp_nonce_field() {}
}

// Handle export function.
if( isset($_POST['export']) and FGR_CAN_EXPORT ) {
	require_once(dirname(__FILE__) . '/export.php');
	fgr_export_wp();
}

// Handle uninstallation.
if ( isset($_POST['uninstall']) ) {
	foreach (fgr_options() as $opt) {
		delete_option($opt);
	}
	unset($_POST);
	fgr_uninstall_database();
?>
<div class="wrap">
	<h2><?php echo fgr_i('Foragr Uninstalled'); ?></h2>
	<form method="POST" action="?page=foragr">
		<p>Foragr has been uninstalled successfully.</p>
		<ul style="list-style: circle;padding-left:20px;">
			<li>Local settings for the plugin were removed.</li>
			<li>Database changes by Foragr were reverted.</li>
		</ul>
		<p>If you wish to <a href="?page=foragr&amp;step=1">reinstall</a>, you can do that now.</p>
	</form>
</div>
<?php
die();
}

// Clean-up POST parameters.
foreach ( array('fgr_site', 'fgr_username', 'fgr_user_api_key') as $key ) {
	if ( isset($_POST[$key]) ) { $_POST[$key] = strip_tags($_POST[$key]); }
}


// Handle advanced options.
if ( isset($_POST['fgr_sitename']) ) {
	$fgr_sitename = $_POST['fgr_sitename'];
	if ( $dot_pos = strpos($fgr_sitename, '.') ) {
		$fgr_sitename = substr($fgr_sitename, 0, $dot_pos);
	}
	update_option('fgr_sitename', $fgr_sitename);
	update_option('fgr_api_key', trim(stripslashes($_POST['fgr_api_key'])));
	update_option('fgr_user_api_key', trim(stripslashes($_POST['fgr_user_api_key'])));
	update_option('fgr_public_key', $_POST['fgr_public_key']);
	update_option('fgr_secret_key', $_POST['fgr_secret_key']);
	fgr_manage_dialog('Your settings have been changed.');
}

$fgr_user_api_key = isset($_POST['fgr_user_api_key']) ? $_POST['fgr_user_api_key'] : null;

// Get installation step process (or 0 if we're already installed).
$step = @intval($_GET['step']);
if ($step > 1 && $step != 3 && $fgr_user_api_key) $step = 1;
elseif ($step == 2 && !isset($_POST['fgr_username'])) $step = 1;
$step = (fgr_is_installed()) ? 0 : ($step ? $step : 1);

// Handle installation process.
if ( 3 == $step && isset($_POST['fgr_site']) && isset($_POST['fgr_user_api_key']) ) {
	list($fgr_site_id, $fgr_site_url) = explode(':', $_POST['fgr_site']);
	update_option('fgr_sitename', $fgr_site_url);
	$api_key = $fgr_api->get_site_api_key($_POST['fgr_user_api_key'], $fgr_site_id);
	if ( !$api_key || $api_key < 0 ) {
		fgr_manage_dialog(fgr_i('There was an error completing the installation of Foragr. If you are still having issues, refer to the <a href="http://foragr.com/help/wordpress">WordPress help page</a>.'), true);
	} else {
		update_option('fgr_api_key', $api_key);
		update_option('fgr_user_api_key', $_POST['fgr_user_api_key']);
	}
}

if ( 2 == $step && isset($_POST['fgr_username']) && isset($_POST['fgr_password']) ) {
	$fgr_user_api_key = $fgr_api->get_user_api_key($_POST['fgr_username'], $_POST['fgr_password']);
	if ( $fgr_user_api_key < 0 || !$fgr_user_api_key ) {
		$step = 1;
		fgr_manage_dialog($fgr_api->get_last_error(), true);
	}
	
	if ( $step == 2 ) {
		$fgr_sites = $fgr_api->get_site_list($fgr_user_api_key);
		if ( $fgr_sites < 0 ) {
			$step = 1;
			fgr_manage_dialog($fgr_api->get_last_error(), true);
		} else if ( !$fgr_sites ) {
			$step = 1;
			fgr_manage_dialog(fgr_i('There aren\'t any sites associated with this account. Maybe you want to <a href="%s">create a site</a>?', 'http://foragr.com/accounts/register/'), true);
		}
	}
}

$show_advanced = (isset($_GET['t']) && $_GET['t'] == 'adv');

?>
<div class="wrap" id="fgr-wrap">
	<ul id="fgr-tabs">
		<li<?php if (!$show_advanced) echo ' class="selected"'; ?> id="fgr-tab-main" rel="fgr-main"><?php echo (fgr_is_installed() ? 'Manage' : 'Install'); ?></li>
		<li<?php if ($show_advanced) echo ' class="selected"'; ?> id="fgr-tab-advanced" rel="fgr-advanced"><?php echo fgr_i('Advanced Options'); ?></li>
	</ul>

	<div id="fgr-main" class="fgr-content">
<?php
switch ( $step ) {
case 3:
?>
		<div id="fgr-step-3" class="fgr-main"<?php if ($show_advanced) echo ' style="display:none;"'; ?>>
			<h2><?php echo fgr_i('Install Foragr Activity'); ?></h2>

			<p>Foragr has been installed on your blog.</p>
			<p>If you would like to show activity to your users, make sure and <a href="widgets.php">add the "Recent Activity - Foragr" widget</a> to your blog. Otherwise, you're all set.</p>
		</div>
<?php
	break;
case 2:
?>
		<div id="fgr-step-2" class="fgr-main"<?php if ($show_advanced) echo ' style="display:none;"'; ?>>
			<h2><?php echo fgr_i('Install Foragr Activity'); ?></h2>

			<form method="POST" action="?page=foragr&amp;step=3">
			<?php wp_nonce_field('fgr-install-2'); ?>
			<table class="form-table">
				<tr>
					<th scope="row" valign="top"><?php echo fgr_i('Select a website'); ?></th>
					<td>
<?php
foreach ( $fgr_sites as $counter => $fgr_site ):
?>
						<input name="fgr_site" type="radio" id="fgr-site-<?php echo $counter; ?>" value="<?php echo $fgr_site->id; ?>:<?php echo $fgr_site->shortname; ?>" />
						<label for="fgr-site-<?php echo $counter; ?>"><strong><?php echo htmlspecialchars($fgr_site->name); ?></strong> (<u><?php echo $fgr_site->shortname; ?>.foragr.com</u>)</label>
						<br />
<?php
endforeach;
?>
						<hr />
						<a href="<?php echo FGR_URL; ?>accounts/register/"><?php echo fgr_i('Or register a new one on the Foragr website.'); ?></a>
					</td>
				</tr>
			</table>

			<p class="submit" style="text-align: left">
				<input type="hidden" name="fgr_user_api_key" value="<?php echo htmlspecialchars($fgr_user_api_key); ?>"/>
				<input name="submit" type="submit" value="Next &raquo;" />
			</p>
			</form>
		</div>
<?php
	break;
case 1:
?>
		<div id="fgr-step-1" class="fgr-main"<?php if ($show_advanced) echo ' style="display:none;"'; ?>>
			<h2><?php echo fgr_i('Install Foragr Activity'); ?></h2>

			<form method="POST" action="?page=foragr&amp;step=2">
			<?php wp_nonce_field('fgr-install-1'); ?>
			<table class="form-table">
				<tr>
					<th scope="row" valign="top"><?php echo fgr_i('Foragr Username'); ?>:</th>
					<td>
						<input id="fgr-username" name="fgr_username" tabindex="1" type="text" />
						<a href="http://foragr.com/accounts/login/"><?php echo fgr_i('Don\'t have an account yet? It\'s free.'); ?></a>
					</td>
				</tr>
				<tr>
					<th scope="row" valign="top"><?php echo fgr_i('Password'); ?>:</th>
					<td>
						<input type="password" id="fgr-password" name="fgr_password" tabindex="2">
						<a href="http://foragr.com/accounts/password/reset/"><?php echo fgr_i('Forgot your password?'); ?></a>
					</td>
				</tr>
			</table>

			<p class="submit" style="text-align: left">
				<input name="submit" type="submit" value="Next &raquo;" tabindex="3">
			</p>

			<script type="text/javascript"> document.getElementById('fgr-username').focus(); </script>
			</form>
		</div>
<?php
	break;
case 0:
	$url = get_option('fgr_sitename');
?>
		<div class="fgr-main"<?php if ($show_advanced) echo ' style="display:none;"'; ?>>
			<h2><?php echo fgr_i('Activity Stream'); ?></h2>
			<iframe src="<?php if ($url) {
			    echo 'http://'.$url.'.'.FGR_DOMAIN.'/sites/'.$url.'/';
			} else { 
			    echo FGR_URL.'admin/moderate/';
			} ?>?template=wordpress" style="width: 100%; height: 800px"></iframe>
		</div>
<?php } ?>
	</div>

<?php
	$fgr_site_url = strtolower(get_option('fgr_sitename'));
	$fgr_api_key = get_option('fgr_api_key');
	$fgr_user_api_key = get_option('fgr_user_api_key');
	$fgr_public_key = get_option('fgr_public_key');
	$fgr_secret_key = get_option('fgr_secret_key');
?>
	<!-- Advanced options -->
	<div id="fgr-advanced" class="fgr-content fgr-advanced"<?php if (!$show_advanced) echo ' style="display:none;"'; ?>>
		<h2><?php echo fgr_i('Activity Stream Advanced Options'); ?></h2>
		<?php echo fgr_i('Version: %s', esc_html(FGR_VERSION)); ?>
		<form method="POST">
		<?php wp_nonce_field('fgr-advanced'); ?>
		<h3>Configuration</h3>
		<table class="form-table">
			<tr>
				<th scope="row" valign="top"><?php echo fgr_i('Foragr short name'); ?></th>
				<td>
					<input name="fgr_sitename" value="<?php echo esc_attr($fgr_site_url); ?>" tabindex="1" type="text" />
					<br />
					<?php echo fgr_i('This is the unique identifier for your website on Foragr.'); ?>
				</td>
			</tr>

			<tr>
				<th scope="row" valign="top"><?php echo fgr_i('Foragr API Key'); ?></th>
				<td>
					<input type="text" name="fgr_api_key" value="<?php echo esc_attr($fgr_api_key); ?>" tabindex="2">
					<br />
					<?php echo fgr_i('This is set for you when going through the installation steps.'); ?>
				</td>
			</tr>

			<tr>
				<th scope="row" valign="top"><?php echo fgr_i('Foragr User API Key'); ?></th>
				<td>
					<input type="text" name="fgr_user_api_key" value="<?php echo esc_attr($fgr_user_api_key); ?>" tabindex="2">
					<br />
					<?php echo fgr_i('This is set for you when going through the installation steps.'); ?>
				</td>
			</tr>
			<tr>
				<th scope="row" valign="top"><?php echo fgr_i('Application Public Key'); ?></th>
				<td>
					<input type="text" name="fgr_public_key" value="<?php echo esc_attr($fgr_public_key); ?>" tabindex="2">
					<br />
					<?php echo fgr_i('Advanced: Used for single sign-on (SSO) integration. (<a href="%s" onclick="window.open(this.href); return false">more info on SSO</a>)', 'http://foragr.com/help/sso'); ?>
				</td>
			</tr>
			<tr>
				<th scope="row" valign="top"><?php echo fgr_i('Application Secret Key'); ?></th>
				<td>
					<input type="text" name="fgr_secret_key" value="<?php echo esc_attr($fgr_secret_key); ?>" tabindex="2">
					<br />
					<?php echo fgr_i('Advanced: Used for single sign-on (SSO) integration. (<a href="%s" onclick="window.open(this.href); return false">more info on SSO</a>)', 'http://foragr.com/help/sso'); ?>
				</td>
			</tr>
		</table>

		<p class="submit" style="text-align: left">
			<input name="submit" type="submit" value="Save" class="button-primary button" tabindex="4">
		</p>
		</form>

		<h3>Uninstall</h3>
		
		<table class="form-table">
			<tr>
				<th scope="row" valign="top"><?php echo fgr_i('Uninstall Foragr'); ?></th>
				<td>
					<form action="?page=foragr" method="POST">
						<?php wp_nonce_field('fgr-uninstall'); ?>
						<p>This will remove all Foragr specific settings, but it will leave your activity unaffected.</p>
						<input type="submit" value="Uninstall" name="uninstall" onclick="return confirm('<?php echo fgr_i('Are you sure you want to uninstall Foragr?'); ?>')" class="button" />
					</form>
				</td>
			</tr>
		</table>
		<br/>
		<h3><?php echo fgr_i('Debug Information'); ?></h3>
		<p><?php echo fgr_i('Having problems with the plugin? <a href="%s">Drop us a line</a> and include the following details and we\'ll do what we can.', 'mailto:help@foragr.com'); ?></p>
		<textarea style="width:90%; height:200px;">URL: <?php echo get_option('siteurl'); ?> 
Version: <?php echo $wp_version; ?> 
Active Theme: <?php $theme = get_theme(get_current_theme()); echo $theme['Name'].' '.$theme['Version']; ?> 
URLOpen Method: <?php echo fgr_url_method(); ?> 

Plugin Version: <?php echo FGR_VERSION; ?> 

Settings:

fgr_is_installed: <?php echo fgr_is_installed(); ?> 
<?php foreach (fgr_options() as $opt) {
	echo $opt.': '.get_option($opt)."\n";
} ?>

Plugins:

<?php
foreach (get_plugins() as $plugin) {
	echo $plugin['Name'].' '.$plugin['Version']."\n";
}
?></textarea><br/>
	</div>
</div>
