<script type="text/javascript">
var foragr_domain = '<?php echo FGR_DOMAIN; ?>';
var foragr_sitename = '<?php echo strtolower(get_option('fgr_sitename')); ?>';
var foragr_url = '<?php echo get_permalink(); ?> ';
<?php if (isset($post)): ?>
var foragr_identifier = '<?php echo fgr_identifier_for_post($post); ?>';
var foragr_title = <?php echo fgr_json_encode(fgr_title_for_post($post)); ?>;
var foragr_description = <?php echo fgr_json_encode(fgr_summary_for_post($post)); ?>;
<?php endif; ?>
<?php if (false && get_option('fgr_developer')): ?>
  var foragr_developer = 1;
<?php endif; ?>
(function() {
  var s = document.createElement('script'); s.type = 'text/javascript'; s.async = true;
  s.src = 'http://' + foragr_sitename + '.' + foragr_domain + '/configure.js?pname=wordpress&pver=<?php echo FGR_VERSION; ?>';
  (document.getElementsByTagName('head')[0] || document.getElementsByTagName('body')[0]).appendChild(s);
})();
</script>
