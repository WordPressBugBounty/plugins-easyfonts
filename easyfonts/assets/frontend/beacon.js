/**
 * Easy Fonts beacon.
 *
 * Measures real font usage in the browser and reports decisions to the server:
 *   - which font variants render ABOVE THE FOLD  -> preload candidates
 *   - which loaded faces never render anywhere    -> unload candidates
 *
 * Sources: document.fonts (FontFaceSet) for what loaded, getComputedStyle for
 * what's actually applied, getBoundingClientRect for above-the-fold.
 */
( function () {
	'use strict';

	var CFG = window.EasyFontsBeacon;
	if ( ! CFG || ! CFG.endpoint || ! document.fonts ) {
		return;
	}

	var GENERIC = /^(serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-(?:serif|sans-serif|monospace|rounded)|-apple-system|blinkmacsystemfont|"?segoe ui"?|helvetica|helvetica neue|arial|times new roman|georgia|courier new|inherit|initial|revert|unset|emoji|math|fangsong)$/i;

	function clean( name ) {
		return ( name || '' ).trim().replace( /^["']|["']$/g, '' );
	}

	function normWeight( w ) {
		if ( w === 'normal' ) { return '400'; }
		if ( w === 'bold' ) { return '700'; }
		return String( w || '400' );
	}

	function collect() {
		var loaded = {};   // family -> { weight: {style:true} }  (what the browser fetched)
		var rendered = {}; // family|weight|style -> true
		var aboveFold = {};// family|weight|style -> true
		var foldLine = window.innerHeight || document.documentElement.clientHeight;

		// 1. Loaded faces.
		document.fonts.forEach( function ( face ) {
			if ( face.status !== 'loaded' ) { return; }
			var fam = clean( face.family );
			if ( ! fam || GENERIC.test( fam ) ) { return; }
			var w = normWeight( face.weight );
			var s = face.style || 'normal';
			loaded[ fam ] = loaded[ fam ] || {};
			loaded[ fam ][ w + '|' + s ] = true;
		} );

		// 2. Rendered + above-the-fold (sample visible text elements). The set
		//    of element types is deliberately broad — a family is only kept on a
		//    page if something here reports it, so missing an element type would
		//    wrongly scope a used font off the page.
		var nodes = document.querySelectorAll(
			'h1,h2,h3,h4,h5,h6,p,a,span,li,td,th,caption,button,label,input,textarea,select,option,' +
			'blockquote,figcaption,figure,dl,dt,dd,summary,details,strong,b,em,i,u,mark,small,' +
			'cite,q,code,pre,abbr,time,address,legend,output,div'
		);
		var max = Math.min( nodes.length, 3000 ); // cap work on huge pages

		function noteRendered( fam, weight, style, rect ) {
			if ( ! fam || GENERIC.test( fam ) ) { return; }
			var key = fam + '|' + normWeight( weight ) + '|' + ( style || 'normal' );
			rendered[ key ] = true;
			if ( rect && rect.top < foldLine && rect.bottom > 0 && rect.width > 0 && rect.height > 0 ) {
				aboveFold[ key ] = true;
			}
		}

		for ( var i = 0; i < max; i++ ) {
			var el = nodes[ i ];

			var hasText = el.value ? true : ( el.textContent && el.textContent.trim() );
			if ( ! hasText ) { continue; }

			var rect = el.getBoundingClientRect();
			var cs   = getComputedStyle( el );
			noteRendered( clean( ( cs.fontFamily || '' ).split( ',' )[ 0 ] ), cs.fontWeight, cs.fontStyle, rect );

			// Generated content (::before / ::after) can carry its own font.
			var bef = getComputedStyle( el, '::before' );
			if ( bef && bef.content && bef.content !== 'none' && bef.content !== 'normal' ) {
				noteRendered( clean( ( bef.fontFamily || '' ).split( ',' )[ 0 ] ), bef.fontWeight, bef.fontStyle, rect );
			}
			var aft = getComputedStyle( el, '::after' );
			if ( aft && aft.content && aft.content !== 'none' && aft.content !== 'normal' ) {
				noteRendered( clean( ( aft.fontFamily || '' ).split( ',' )[ 0 ] ), aft.fontWeight, aft.fontStyle, rect );
			}
		}

		// 3. Build decisions.
		var preload = [];
		Object.keys( aboveFold ).forEach( function ( key ) {
			var p = key.split( '|' );
			preload.push( { family: p[ 0 ], weight: p[ 1 ], style: p[ 2 ] } );
		} );

		var unload = [];
		Object.keys( loaded ).forEach( function ( fam ) {
			Object.keys( loaded[ fam ] ).forEach( function ( ws ) {
				var parts = ws.split( '|' );
				var key = fam + '|' + parts[ 0 ] + '|' + parts[ 1 ];
				if ( ! rendered[ key ] ) {
					unload.push( { family: fam, weight: parts[ 0 ], style: parts[ 1 ] } );
				}
			} );
		} );

		var renderedList = Object.keys( rendered ).map( function ( key ) {
			var p = key.split( '|' );
			return {
				family: p[ 0 ],
				weight: p[ 1 ],
				style: p[ 2 ],
				above_fold: aboveFold[ key ] ? 1 : 0
			};
		} );

		return { rendered: renderedList, preload: preload, unload: unload };
	}

	function send( data ) {
		if ( ! data.rendered.length && ! data.preload.length ) { return; }

		var payload = JSON.stringify( {
			route: CFG.route,
			device: CFG.device,
			rendered: data.rendered,
			preload: data.preload,
			unload: data.unload
		} );

		var url = CFG.endpoint + '?_wpnonce=' + encodeURIComponent( CFG.nonce )
			+ ( CFG.token ? '&_eftoken=' + encodeURIComponent( CFG.token ) : '' );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( url, new Blob( [ payload ], { type: 'application/json' } ) );
		} else {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', url, true );
			xhr.setRequestHeader( 'Content-Type', 'application/json' );
			xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce );
			xhr.send( payload );
		}
	}

	// Run after fonts settle, giving lazy/builder fonts time to apply. We also
	// flush on pagehide/visibilitychange so the report is never lost — important
	// inside the admin's hidden optimize iframe, which may be torn down quickly.
	var sent = false;

	function flush() {
		if ( sent ) { return; }
		sent = true;
		try { send( collect() ); } catch ( e ) {}
	}

	document.fonts.ready.then( function () {
		setTimeout( flush, 1500 );
	} );

	window.addEventListener( 'pagehide', flush );
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) { flush(); }
	} );
}() );
