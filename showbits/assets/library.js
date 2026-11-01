/**
 * Media → ShowBits: the released clips and quote cards of the connected workspace, newest release first. Pick some,
 * choose a quote card's format, import: each is asked of ShowBits, which sends it to this site; the page waits and
 * shows when it is in the media library. Talks only to this site's own REST routes (showbits/v1), with WordPress'
 * nonce; ShowBits' key stays on the server.
 *
 * @package ShowBits
 */
( function () {
	'use strict';

	const root = document.getElementById( 'showbits-library' );
	const config = window.showbitsLibrary;
	if ( ! root || ! config || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	const apiFetch = window.wp.apiFetch;
	const s = config.strings;
	const FORMATS = [ '4x5', '1x1', '9x16' ];

	const state = { kind: '', q: '', items: [], cursor: null, loading: false, busy: false, selected: new Set(), formats: new Map(), results: new Map(), error: '' };

	/** An element with attributes and children; text only ever as text. */
	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			if ( value === false || value === null || value === undefined ) {
				return;
			}
			if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key.startsWith( 'on' ) ) {
				node.addEventListener( key.slice( 2 ), value );
			} else {
				node.setAttribute( key, value === true ? '' : String( value ) );
			}
		} );
		( children || [] ).forEach( ( child ) => child && node.append( child ) );
		return node;
	}

	/** Text from ShowBits (titles, show and episode names) in its own direction, so it keeps its order and punctuation in a right-to-left admin. */
	const isolated = ( text ) => el( 'bdi', { text } );

	// A text from elsewhere (a reason, a date) in a translated sentence: in a right-to-left admin isolated in its own
	// direction (FSI … PDI) so the translation's punctuation stays in place, as Plugin::isolate() does on the server.
	const rtl = document.documentElement.dir === 'rtl';
	const own = ( value ) => ( rtl && typeof value === 'string' ? `\u2068${ value }\u2069` : value );
	const format = ( template, ...raw ) => {
		const values = raw.map( own );
		return values.reduce( ( text, value, i ) => text.replace( `%${ i + 1 }$d`, value ).replace( `%${ i + 1 }$s`, value ), template ).replace( '%d', values[ 0 ] ).replace( '%s', values[ 0 ] );
	};
	const when = ( iso ) => new Date( iso ).toLocaleString( config.locale || undefined, { dateStyle: 'medium', timeStyle: 'short' } );
	const seconds = ( value ) => {
		const total = Math.round( value );
		return total >= 60 ? `${ Math.floor( total / 60 ) }:${ String( total % 60 ).padStart( 2, '0' ) }` : `0:${ String( total ).padStart( 2, '0' ) }`;
	};
	const variantOf = ( item ) => ( item.kind === 'quote' ? state.formats.get( item.id ) || config.cardFormat : 'video' );
	const available = ( item ) => ! item.embargoUntil && item.files.some( ( file ) => file.variant === variantOf( item ) );

	async function load( more ) {
		if ( state.loading ) {
			return;
		}
		state.loading = true;
		state.error = '';
		render();
		const query = new URLSearchParams();
		if ( state.kind ) {
			query.set( 'kind', state.kind );
		}
		if ( state.q ) {
			query.set( 'q', state.q );
		}
		if ( more && state.cursor ) {
			query.set( 'cursor', state.cursor );
		}
		try {
			const page = await apiFetch( { path: `/showbits/v1/library?${ query }` } );
			state.items = more ? state.items.concat( page.items ) : page.items;
			state.cursor = page.nextCursor;
		} catch ( error ) {
			state.error = format( s.loadFailed, error.message || '' );
		}
		state.loading = false;
		render();
	}

	const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

	async function importSelected() {
		const chosen = state.items.filter( ( item ) => state.selected.has( item.id ) && available( item ) );
		if ( ! chosen.length || state.busy ) {
			return;
		}
		state.busy = true;
		// Ask ShowBits for each; it sends them to this site with the ticket for their files.
		const waiting = new Map();
		for ( const [ i, item ] of chosen.entries() ) {
			state.progress = format( s.importing, i + 1, chosen.length );
			render();
			try {
				const request = await apiFetch( { path: '/showbits/v1/import', method: 'POST', data: { clipId: item.id, variant: variantOf( item ) } } );
				waiting.set( request.id, item );
				state.results.set( item.id, { ok: true, pending: true } );
				state.selected.delete( item.id );
			} catch ( error ) {
				state.results.set( item.id, { ok: false, message: error.message || '' } );
			}
		}
		// Then wait until they are in the media library (asking also works the queue on the server).
		let done = 0;
		const until = Date.now() + 180000;
		while ( waiting.size && Date.now() < until ) {
			state.progress = format( s.waiting, waiting.size );
			render();
			await sleep( 2000 );
			try {
				const answer = await apiFetch( { path: `/showbits/v1/imports?ids=${ [ ...waiting.keys() ].join( ',' ) }` } );
				for ( const row of answer.imports ) {
					const item = waiting.get( row.id );
					if ( ! item || ( row.status !== 'imported' && row.status !== 'failed' ) ) {
						continue;
					}
					waiting.delete( row.id );
					if ( row.status === 'imported' ) {
						item.imported = Object.assign( {}, item.imported, { [ row.variant ]: { editUrl: row.editUrl, current: true } } );
						state.results.set( item.id, { ok: true } );
						done++;
					} else {
						state.results.set( item.id, { ok: false, message: row.error || '' } );
					}
				}
			} catch {
				// The next round asks again.
			}
		}
		state.busy = false;
		state.progress = waiting.size ? s.later : format( s.done, done );
		render();
	}

	function toolbar() {
		const kinds = [ [ '', s.all ], [ 'clip', s.clips ], [ 'quote', s.quotes ] ];
		let timer;
		const search = el( 'input', {
			type: 'search',
			class: 'showbits-search',
			placeholder: s.search,
			'aria-label': s.searchLabel,
			value: state.q,
			oninput: ( event ) => {
				clearTimeout( timer );
				timer = setTimeout( () => {
					state.q = event.target.value.trim();
					load( false );
				}, 350 );
			},
		} );
		const selected = state.items.filter( ( item ) => state.selected.has( item.id ) && available( item ) ).length;
		return el( 'div', { class: 'showbits-toolbar' }, [
			el(
				'div',
				{ class: 'showbits-kinds', role: 'group' },
				kinds.map( ( [ kind, label ] ) =>
					el( 'button', { type: 'button', class: `button${ state.kind === kind ? ' button-primary' : '' }`, 'aria-pressed': state.kind === kind ? 'true' : 'false', text: label, onclick: () => {
						state.kind = kind;
						load( false );
					} } )
				)
			),
			search,
			el( 'button', { type: 'button', class: 'button button-primary', disabled: ! selected || state.busy, text: `${ s.import } (${ selected })`, onclick: importSelected } ),
			state.progress ? el( 'span', { class: 'showbits-progress', role: 'status', text: state.progress } ) : null,
		] );
	}

	function card( item ) {
		const quote = item.kind === 'quote';
		const variant = variantOf( item );
		const have = ( item.imported || {} )[ variant ];
		const result = state.results.get( item.id );
		const id = `showbits-${ item.id }`;
		const badges = [];
		if ( item.embargoUntil ) {
			badges.push( el( 'span', { class: 'showbits-badge is-locked', text: format( s.embargo, when( item.embargoUntil ) ) } ) );
		} else if ( ! available( item ) ) {
			badges.push( el( 'span', { class: 'showbits-badge', text: s.noFile } ) );
		}
		if ( have ) {
			badges.push( el( 'a', { class: `showbits-badge ${ have.current ? 'is-done' : '' }`, href: have.editUrl, text: `${ have.current ? s.inLibrary : s.outdated } · ${ s.edit }` } ) );
		}
		if ( result && result.pending ) {
			badges.push( el( 'span', { class: 'showbits-badge', text: s.pending } ) );
		}
		if ( result && ! result.ok ) {
			badges.push( el( 'span', { class: 'showbits-badge is-error', role: 'alert', text: format( s.failed, result.message ) } ) );
		}
		const picker = quote
			? el( 'label', { class: 'showbits-format' }, [
				el( 'span', { text: `${ s.format } ` } ),
				el(
					'select',
					{ onchange: ( event ) => {
						state.formats.set( item.id, event.target.value );
						render();
					} },
					FORMATS.map( ( value ) => el( 'option', { value, selected: value === variant, text: value.replace( 'x', ':' ) } ) )
				),
			] )
			: null;
		return el( 'li', { class: `showbits-card${ state.selected.has( item.id ) ? ' is-selected' : '' }` }, [
			el( 'div', { class: `showbits-preview${ quote ? ' is-quote' : '' }` }, [ item.preview ? el( 'img', { src: item.preview, alt: '', loading: 'lazy' } ) : null ] ),
			el( 'div', { class: 'showbits-body' }, [
				el( 'label', { class: 'showbits-pick', for: id }, [
					el( 'input', { type: 'checkbox', id, checked: state.selected.has( item.id ), disabled: ! available( item ) || state.busy, onchange: ( event ) => {
						if ( event.target.checked ) {
							state.selected.add( item.id );
						} else {
							state.selected.delete( item.id );
						}
						render();
					} } ),
					el( 'strong', {}, [ isolated( item.title ) ] ),
				] ),
				el(
					'p',
					{ class: 'showbits-meta' },
					[ quote ? s.quote : item.kind === 'meme' ? s.meme : s.video, quote ? '' : seconds( item.durationSec ), item.show.name, item.episode.title ]
						.filter( Boolean )
						.flatMap( ( part, i ) => ( i ? [ ' · ', isolated( part ) ] : [ isolated( part ) ] ) )
				),
				item.caption ? el( 'p', { class: 'showbits-caption' }, [ isolated( item.caption ) ] ) : null,
				picker,
				badges.length ? el( 'div', { class: 'showbits-badges' }, badges ) : null,
			] ),
		] );
	}

	function render() {
		const content = [ toolbar() ];
		if ( state.error ) {
			content.push( el( 'div', { class: 'notice notice-error inline' }, [ el( 'p', { text: state.error } ) ] ) );
		}
		if ( ! state.items.length && ! state.loading && ! state.error ) {
			content.push( el( 'p', { class: 'showbits-empty', text: s.empty } ) );
		}
		content.push( el( 'ul', { class: 'showbits-grid' }, state.items.map( card ) ) );
		if ( state.loading ) {
			content.push( el( 'p', { class: 'showbits-loading', text: s.loading } ) );
		} else if ( state.cursor ) {
			content.push( el( 'p', {}, [ el( 'button', { type: 'button', class: 'button', text: s.loadMore, onclick: () => load( true ) } ) ] ) );
		}
		const focused = document.activeElement && root.contains( document.activeElement ) ? document.activeElement.className : null;
		root.replaceChildren( ...content );
		// Typing in the search keeps its place.
		if ( focused === 'showbits-search' ) {
			const search = root.querySelector( '.showbits-search' );
			search.focus();
			search.setSelectionRange( search.value.length, search.value.length );
		}
	}

	load( false );
}() );
