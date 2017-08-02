var fgr_old_onload = window.onload;

function fgr_tab_func(clicked_tab) {
	function _fgr_tab_func(e) {
		var tabs = document.getElementById('fgr-tabs').getElementsByTagName('li');
		var contents = document.getElementById('fgr-wrap').getElementsByTagName('div');

		for(var i = 0; i < tabs.length; i++) {
			tabs[i].className = '';
		}

		for(var i = 0; i < contents.length; i++) {
			if(contents[i].className == 'fgr-content') {
				contents[i].style.display = 'none';
			}
		}

		document.getElementById('fgr-tab-' + clicked_tab).className = 'selected';
		document.getElementById('fgr-' + clicked_tab).style.display = 'block';

	}

	return _fgr_tab_func;
}

window.onload = function(e) {
	// Tabs have an ID prefixed with "fgr-tab-".
	// Content containers have an ID prefixed with "fgr-" and a class name of "fgr-content".
	var tabs = document.getElementById('fgr-tabs').getElementsByTagName('li');

	for(var i = 0; i < tabs.length; i++) {
		tabs[i].onclick = fgr_tab_func(tabs[i].id.substr(tabs[i].id.lastIndexOf('-') + 1));
	}

	if(fgr_old_onload) {
		fgr_old_onload(e);
	}
}
