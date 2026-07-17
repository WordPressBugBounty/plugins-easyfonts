/**
 * Easy Fonts — async Google Fonts blocker.
 *
 * Some themes/plugins inject Google/Bunny Fonts at RUNTIME via JavaScript
 * (document.createElement('link') + appendChild, or an @import in an injected
 * <style>). Those never appear in the server-rendered HTML, so the PHP output
 * buffer can't see them. This script:
 *
 *   1. Intercepts provider stylesheet <link>s before they enter the DOM and
 *      neutralises them (so the render-blocking Google request is prevented),
 *   2. Reports the URLs to the server, which self-hosts them on the next render.
 *
 * After the first visit the fonts are hosted locally, so the consolidated local
 * stylesheet covers them and the remote request is gone for everyone.
 */
( function () {
	'use strict';

	var CFG = window.EasyFontsAsync;
	if ( ! CFG || ! CFG.endpoint ) {
		return;
	}

	// Google Fonts (and the WordPress.com Google Fonts proxy) only — Bunny is
	// intentionally excluded from async blocking.
	var HOSTS = [ 'fonts.googleapis.com', 'fonts-api.wp.com' ];
	var found = {};

	function isProviderCss( url ) {
		if ( ! url || typeof url !== 'string' ) {
			return false;
		}
		for ( var i = 0; i < HOSTS.length; i++ ) {
			if ( url.indexOf( HOSTS[ i ] + '/css' ) !== -1 || url.indexOf( HOSTS[ i ] + '/icon' ) !== -1 ) {
				return true;
			}
		}
		return false;
	}

	function record( url ) {
		if ( ! url || found[ url ] ) {
			return;
		}
		found[ url ] = true;
		schedule();
	}

	// Neutralise a provider <link> so the browser never fetches it.
	function neutralise( node ) {
		try {
			var href = node.getAttribute ? node.getAttribute( 'href' ) : node.href;
			if ( node.tagName === 'LINK' && isProviderCss( href ) ) {
				record( href );
				// Prevent the fetch: blank rel/href + mark inert.
				node.setAttribute( 'data-easyfonts-async', '1' );
				node.setAttribute( 'media', 'max-width:1px' );
				node.setAttribute( 'href', 'data:text/css,' );
				node.rel = 'preload';
				return true;
			}
		} catch ( e ) {}
		return false;
	}

	// 1. Intercept programmatic insertion (catches it before the request fires).
	function patch( proto, method ) {
		if ( ! proto || ! proto[ method ] || proto[ method ].__efPatched ) {
			return;
		}
		var original = proto[ method ];
		proto[ method ] = function ( node ) {
			try {
				if ( node && node.tagName === 'LINK' ) {
					neutralise( node );
				}
			} catch ( e ) {}
			return original.apply( this, arguments );
		};
		proto[ method ].__efPatched = true;
	}

	patch( Node.prototype, 'appendChild' );
	patch( Node.prototype, 'insertBefore' );

	// 2. Safety net: observe anything that still slips into the DOM.
	function sweep() {
		var links = document.querySelectorAll( 'link[rel="stylesheet"],link[as="style"]' );
		for ( var i = 0; i < links.length; i++ ) {
			if ( ! links[ i ].getAttribute( 'data-easyfonts-async' ) ) {
				neutralise( links[ i ] );
			}
		}
	}

	if ( window.MutationObserver ) {
		var obs = new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;
				for ( var j = 0; j < added.length; j++ ) {
					if ( added[ j ].tagName === 'LINK' ) {
						neutralise( added[ j ] );
					}
				}
			}
		} );
		try {
			obs.observe( document.documentElement, { childList: true, subtree: true } );
		} catch ( e ) {}
	}

	// Report collected URLs (debounced, once settled).
	var timer = null;
	function schedule() {
		if ( timer ) {
			clearTimeout( timer );
		}
		timer = setTimeout( send, 800 );
	}

	var sent = {};
	function send() {
		var urls = [];
		Object.keys( found ).forEach( function ( u ) {
			if ( ! sent[ u ] ) {
				urls.push( u );
				sent[ u ] = true;
			}
		} );
		if ( ! urls.length ) {
			return;
		}

		var payload = JSON.stringify( { urls: urls } );
		var url = CFG.endpoint + '?_wpnonce=' + encodeURIComponent( CFG.nonce )
			+ ( CFG.token ? '&_eftoken=' + encodeURIComponent( CFG.token ) : '' );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( url, new Blob( [ payload ], { type: 'application/json' } ) );
		} else {
			try {
				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', url, true );
				xhr.setRequestHeader( 'Content-Type', 'application/json' );
				xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce );
				xhr.send( payload );
			} catch ( e ) {}
		}
	}

	if ( document.readyState !== 'loading' ) {
		sweep();
	} else {
		document.addEventListener( 'DOMContentLoaded', sweep );
	}
	window.addEventListener( 'load', function () { sweep(); schedule(); } );
}() );
