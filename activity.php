<?php
srand ((double) microtime( )*1000000);
$rand = rand(0,10e16);
if (FGR_DEBUG) {
	echo "<p><strong>Foragr Debug</strong> target_id: ".get_post_meta($post->ID, 'fgr_target_id', true)."</p>";
}
?>
<div id="fgr-activity-<?php echo $rand; ?>">
	<?php if (false): ?>
		<?php
		// if (is_file(TEMPLATEPATH . '/activity.php')) {
		// 	include(TEMPLATEPATH . '/activity.php');
		// }
		?>
		<div id="fgr-content">
			<ul id="fgr-activity">
	<?php foreach ($comments as $action) : ?>
				<li id="fgr-action-<?php echo comment_ID(); ?>">
					<div id="fgr-action-header-<?php echo comment_ID(); ?>" class="fgr-action-header">
						<cite id="fgr-cite-<?php echo comment_ID(); ?>">
	<?php if(comment_author_url()) : ?>
							<a id="fgr-author-user-<?php echo comment_ID(); ?>" href="<?php echo comment_author_url(); ?>" target="_blank" rel="nofollow"><?php echo comment_author(); ?></a>
	<?php else : ?>
							<span id="fgr-author-user-<?php echo comment_ID(); ?>"><?php echo comment_author(); ?></span>
	<?php endif; ?>
						</cite>
					</div>
					<div id="fgr-action-body-<?php echo comment_ID(); ?>" class="fgr-action-body">
						<div id="fgr-action-message-<?php echo comment_ID(); ?>" class="fgr-action-message"><?php echo wp_filter_kses(comment_text()); ?></div>
					</div>
				</li>
	<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
</div>

<script type="text/javascript">
window.foragr_load = window.foragr_load || [];
foragr_load.push(function() {fgr.embed({ embed: 'stream.Stream', container: 'fgr-activity-<?php echo $rand; ?>', style: '<?php echo $FGR_STYLE; ?>', count: '<?php echo $FGR_COUNT; ?>' })});
</script>
