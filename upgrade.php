<?php
require(ABSPATH . 'wp-includes/version.php');

if ( !current_user_can('manage_options') ) {
	die();
}


?>
<div class="wrap">
	<h2><?php echo fgr_i('Upgrade Foragr Activity'); ?></h2>
	<form method="POST" action="?page=foragr&amp;step=<?php echo isset($_GET['step']) ? $_GET['step'] : ''; ?>">
		<p>You need to upgrade your database to continue.</p>

		<p class="submit" style="text-align: left">
			<input type="submit" name="upgrade" value="Upgrade &raquo;" />
		</p>
	</form>
</div>