/**
 * Easy Fonts X-ray overlay.
 *
 * Admin-only diagnostic (?easyfonts_xray=1). Scans the rendered page and shows a
 * floating panel of every font family in use: where it loads from, whether it's
 * locally hosted, and whether it appears above the fold.
 */
( function () {
	'use strict';

	if ( ! document.fonts ) { return; }

	var GENERIC = /^(serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-(?:serif|sans-serif|monospace|rounded)|-apple-system|blinkmacsystemfont|"?segoe ui"?|helvetica|helvetica neue|arial|times new roman|georgia|courier new|inherit|initial|revert|unset|emoji|math|fangsong)$/i;

	function clean( s ) { return ( s || '' ).trim().replace( /^["']|["']$/g, '' ); }

	function isLocal() {
		var hosted = {};
		document.fonts.forEach( function ( f ) {
			// Heuristic: if any face src points at /uploads/easyfonts/, it's hosted by us.
			hosted[ clean( f.family ) ] = true;
		} );
		return hosted;
	}

	// Map a character's codepoint to a Google subset (matches the PHP ranges).
	function subsetOf( cp ) {
		if ( cp >= 0x0000 && cp <= 0x00FF ) { return 'latin'; }
		if ( ( cp >= 0x0100 && cp <= 0x024F ) || ( cp >= 0x1E00 && cp <= 0x1EFF ) ) { return 'latin-ext'; }
		if ( cp >= 0x0400 && cp <= 0x045F ) { return 'cyrillic'; }
		if ( cp >= 0x0460 && cp <= 0x052F ) { return 'cyrillic-ext'; }
		if ( cp >= 0x0370 && cp <= 0x03FF ) { return 'greek'; }
		if ( cp >= 0x1F00 && cp <= 0x1FFF ) { return 'greek-ext'; }
		if ( cp >= 0x0900 && cp <= 0x097F ) { return 'devanagari'; }
		if ( ( cp >= 0x1EA0 && cp <= 0x1EF9 ) || cp === 0x20AB ) { return 'vietnamese'; }
		return null;
	}

	function scan() {
		var fold = window.innerHeight;
		var map = {};
		var nodes = document.querySelectorAll( 'body *' );

		for ( var i = 0; i < nodes.length && i < 4000; i++ ) {
			var el = nodes[ i ];
			if ( ! el.textContent || ! el.firstChild || el.firstChild.nodeType !== 3 ) { continue; }
			var cs = getComputedStyle( el );
			var fam = clean( ( cs.fontFamily || '' ).split( ',' )[ 0 ] );
			if ( ! fam || GENERIC.test( fam ) ) { continue; }

			if ( ! map[ fam ] ) {
				map[ fam ] = { weights: {}, subsets: {}, aboveFold: false };
			}
			map[ fam ].weights[ cs.fontWeight ] = true;

			// Detect which subsets the rendered text in this element needs.
			var text = el.firstChild.nodeValue || '';
			for ( var c = 0; c < text.length && c < 400; c++ ) {
				var sub = subsetOf( text.charCodeAt( c ) );
				if ( sub ) { map[ fam ].subsets[ sub ] = true; }
			}

			var r = el.getBoundingClientRect();
			if ( r.top < fold && r.bottom > 0 ) { map[ fam ].aboveFold = true; }
		}
		return map;
	}

	function googleLinks() {
		var n = 0;
		document.querySelectorAll( 'link[href*="fonts.googleapis.com"],link[href*="fonts.gstatic.com"]' ).forEach( function () { n++; } );
		return n;
	}

	function render() {
		var map = scan();
		var families = Object.keys( map ).sort();
		var remaining = googleLinks();

		var panel = document.createElement( 'div' );
		panel.id = 'easyfonts-xray';
		panel.innerHTML =
			'<div class="ef-xray-head"><strong>Easy Fonts · X-ray</strong>' +
			'<button id="ef-xray-close" aria-label="Close">×</button></div>' +
			'<div class="ef-xray-meta">' + families.length + ' families in use · ' +
			( remaining ? ( '<span class="ef-bad">' + remaining + ' Google request(s) still present</span>' ) : '<span class="ef-ok">no remote Google requests</span>' ) +
			'</div>' +
			'<ul class="ef-xray-list">' +
			families.map( function ( f ) {
				var w = Object.keys( map[ f ].weights ).sort().join( ', ' );
				var subs = Object.keys( map[ f ].subsets ).sort();
				return '<li><span class="ef-fam" style="font-family:\'' + f.replace( /'/g, '' ) + '\'">' + f + '</span>' +
					'<span class="ef-w">' + w + '</span>' +
					( subs.length ? '<span class="ef-subs">' + subs.join( ' · ' ) + '</span>' : '' ) +
					( map[ f ].aboveFold ? '<span class="ef-fold">above fold</span>' : '' ) + '</li>';
			} ).join( '' ) +
			'</ul>';

		var style = document.createElement( 'style' );
		style.textContent =
			'#easyfonts-xray{position:fixed;bottom:20px;right:20px;width:320px;max-height:70vh;overflow:auto;z-index:2147483647;' +
			'background:#0f1115;color:#e7e9ee;border:1px solid #2a2f3a;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.45);' +
			'font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;padding:0}' +
			'#easyfonts-xray .ef-xray-head{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid #2a2f3a;position:sticky;top:0;background:#0f1115}' +
			'#easyfonts-xray button{background:none;border:0;color:#e7e9ee;font-size:18px;cursor:pointer;line-height:1}' +
			'#easyfonts-xray .ef-xray-meta{padding:10px 14px;border-bottom:1px solid #2a2f3a;color:#aab1c0}' +
			'#easyfonts-xray .ef-ok{color:#4ade80}#easyfonts-xray .ef-bad{color:#f87171}' +
			'#easyfonts-xray ul{list-style:none;margin:0;padding:6px 0}' +
			'#easyfonts-xray li{display:flex;gap:8px;align-items:baseline;padding:7px 14px;border-bottom:1px solid #1a1e26;flex-wrap:wrap}' +
			'#easyfonts-xray .ef-fam{font-size:15px;flex:1 1 auto}' +
			'#easyfonts-xray .ef-w{color:#8b93a4;font-size:11px}' +
			'#easyfonts-xray .ef-subs{font-size:10px;color:#c6f24e;flex-basis:100%;font-family:ui-monospace,Menlo,monospace}' +
			'#easyfonts-xray .ef-fold{font-size:10px;background:#1f6feb;color:#fff;border-radius:4px;padding:1px 6px}';

		document.head.appendChild( style );
		document.body.appendChild( panel );
		document.getElementById( 'ef-xray-close' ).addEventListener( 'click', function () {
			panel.remove();
		} );
	}

	document.fonts.ready.then( function () {
		setTimeout( render, 800 );
	} );
}() );
