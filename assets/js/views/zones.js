$(document).ready(function() { 
	// Register buttons being clicked
	$(".fwbutton").click(function(e) { e.preventDefault(); isClicked(this); });

	// Update address bar when someone changes tabs
	$("a[data-toggle='tab']").on('shown.bs.tab', function(e) { 
		// New target. Don't need jquery here...
		var newuri = updateQuery("tab", e.target.getAttribute('aria-controls'));
		window.history.replaceState(null, document.title, newuri);
	});
});

function isClicked(o) {
	var counter, action, j;

	// jQuery the button.
	j = $(o);

	// Get the button counter..
	if (!j.data('counter')) {
		console.log("No counter attribue for button", o);
		return;
	}
	if (!j.data('action')) {
		console.log("No action attribue for button", o);
		return;
	}

	counter = j.data('counter');
	action = j.data('action');

	// Now, what do we do?
	switch(action) {
		case 'remove':
			removeNetwork(counter);
		return;
		case 'update':
			updateNetwork(counter);
		return;
		case 'create':
			createNetwork(counter);
		return;
		default:
			console.log("Unknown action");
		return;
	}
}

function opaqueRow(c) {
	$("#element-"+c).find('input,label,button').css({
		opacity: "0.33",
		cursor: "wait",
	}).click(function(e) { e.preventDefault(); });
}

function removeNetwork(c) {
	var net;

	console.log("Removing network "+c);
	opaqueRow(c);

	net = $("input[type=text]", "#element-"+c).val()
	$.ajax({
		url: window.ajaxurl,
		data: { command: 'removenetwork', module: 'firewall', net: net },
		complete: function(data) { window.location.href = window.location.href; },
	});

}

function updateNetwork(c) {
	console.log("Updating network "+c);
	opaqueRow(c);
}

function createNetwork(c) {
	var net, zone;

	console.log("Creating network "+c);
	//	opaqueRow(c);
	net = $("input[type=text]", "#element-"+c).val()
	zone = $("input[type=radio]:checked", "#element-"+c).val()
	$.ajax({
		url: window.ajaxurl,
		data: { command: 'addnetworktozone', module: 'firewall', net: net, zone: zone },
		// complete: function(data) { window.location.href = window.location.href; },
	});
}

function updateQuery(key, value) {
	var re = new RegExp("([?&])" + key + "=.*?(&|#|$)(.*)", "gi"), hash;
	var url = window.location.href;

	if (re.test(url)) {
		if (typeof value !== 'undefined' && value !== null) {
			return url.replace(re, '$1' + key + "=" + value + '$2$3');
		} else {
			hash = url.split('#');
			url = hash[0].replace(re, '$1$3').replace(/(&|\?)$/, '');
			if (typeof hash[1] !== 'undefined' && hash[1] !== null) {
				url += '#' + hash[1];
			}
			return url;
		}
	} else {
		if (typeof value !== 'undefined' && value !== null) {
			var separator = url.indexOf('?') !== -1 ? '&' : '?';
			hash = url.split('#');
			url = hash[0] + separator + key + '=' + value;
			if (typeof hash[1] !== 'undefined' && hash[1] !== null) 
				url += '#' + hash[1];
			return url;
		} else {
			return url;
		}
	}
}

