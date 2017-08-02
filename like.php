<?php
if (FGR_DEBUG) {
	echo "<p><strong>Foragr Debug</strong> target_id: ".get_post_meta($post->ID, 'fgr_target_id', true)."</p>";
}
?>
<div id="fgr_likeButtons"></div>
<script type="text/javascript">
window.foragr_load = window.foragr_load || [];
foragr_load.push(function() {fgr.embed({ embed: 'like.Like', container: 'fgr_likeButtons' })});
</script>
