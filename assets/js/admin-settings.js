( function () {
	'use strict';

	const root = document.querySelector( '[data-geek-cube-search]' );
	const config = window.geekCubeStudioAdmin || {};

	if ( root ) {
		const input = root.querySelector( '[data-geek-cube-search-input]' );
		const results = root.querySelector( '[data-geek-cube-search-results]' );
		const index = Array.isArray( config.searchIndex ) ? config.searchIndex : [];

		const normalize = ( value ) => String( value || '' )
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.toLowerCase();

		const close = () => {
			results.hidden = true;
			results.replaceChildren();
		};

		input.addEventListener( 'input', () => {
			const query = normalize( input.value.trim() );
			results.replaceChildren();

			if ( query.length < 2 ) {
				close();
				return;
			}

			const matches = index.filter( ( item ) => normalize( `${ item.label } ${ item.keywords }` ).includes( query ) ).slice( 0, 8 );
			if ( ! matches.length ) {
				const empty = document.createElement( 'p' );
				empty.className = 'geek-cube-search__empty';
				empty.textContent = config.noResults || 'No settings found.';
				results.appendChild( empty );
			} else {
				matches.forEach( ( item ) => {
					const link = document.createElement( 'a' );
					const meta = document.createElement( 'small' );
					link.href = item.url;
					link.textContent = item.label;
					meta.textContent = item.tab;
					link.appendChild( meta );
					results.appendChild( link );
				} );
			}

			results.hidden = false;
		} );

		document.addEventListener( 'click', ( event ) => {
			if ( ! root.contains( event.target ) ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' ) {
				close();
				input.blur();
			}
		} );
	}

	document.querySelectorAll( '[data-geek-cube-range]' ).forEach( ( range ) => {
		const output = range.parentElement.querySelector( 'output' );
		if ( output ) {
			range.addEventListener( 'input', () => {
				output.value = `${ range.value }%`;
			} );
		}
	} );

	const testForm = document.querySelector( '[data-geek-cube-test-form]' );
	if ( testForm ) {
		const values = {
			userAgent: navigator.userAgent || '',
			platform: navigator.userAgentData && navigator.userAgentData.platform ? navigator.userAgentData.platform : ( navigator.platform || '' ),
			language: navigator.language || '',
			viewport: `${ window.innerWidth }x${ window.innerHeight }@${ window.devicePixelRatio || 1 }`,
		};

		Object.entries( values ).forEach( ( [ key, value ] ) => {
			const field = testForm.querySelector( `[data-env="${ key }"]` );
			if ( field ) {
				field.value = value;
			}
		} );
	}
}() );
