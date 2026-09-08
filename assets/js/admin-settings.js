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

	const slugify = ( value ) => String( value || '' )
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );

	document.querySelectorAll( '[data-geek-cube-slug]' ).forEach( ( input ) => {
		input.addEventListener( 'input', () => {
			input.value = slugify( input.value );
		} );
	} );

	const profileAction = document.querySelector( 'input[name="action"][value="geek_cube_create_profile"]' );
	const profileForm = profileAction ? profileAction.closest( 'form' ) : null;
	if ( profileForm ) {
		const game = profileForm.querySelector( 'select[name="game_id"]' );
		const name = profileForm.querySelector( 'input[name="name"]' );
		const slug = profileForm.querySelector( 'input[name="slug"]' );
		let generatedName = '';
		let generatedSlug = '';

		const selectedTitle = () => {
			if ( ! game || ! game.selectedOptions.length ) {
				return '';
			}

			return game.selectedOptions[ 0 ].textContent.trim().replace( /\s+[·•]\s+[A-Z0-9]+$/, '' );
		};
		const syncFromGame = () => {
			const title = selectedTitle();
			if ( ! title ) {
				return;
			}

			if ( name && ( ! name.value.trim() || name.value === generatedName ) ) {
				name.value = title;
				generatedName = title;
			}
			if ( slug && ( ! slug.value.trim() || slug.value === generatedSlug ) ) {
				slug.value = slugify( title );
				generatedSlug = slug.value;
			}
		};

		if ( game ) {
			game.addEventListener( 'change', syncFromGame );
		}
		if ( name ) {
			name.addEventListener( 'input', () => {
				if ( slug && ( ! slug.value.trim() || slug.value === generatedSlug ) ) {
					slug.value = slugify( name.value );
					generatedSlug = slug.value;
				}
			} );
		}
		if ( slug ) {
			slug.addEventListener( 'input', () => {
				slug.value = slugify( slug.value );
			} );
		}
	}

	const testForm = document.querySelector( '[data-geek-cube-test-form]' );
	if ( testForm ) {
		const labFrame = document.querySelector( '[data-geek-cube-lab-frame]' );
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

		if ( labFrame ) {
			window.addEventListener( 'message', ( event ) => {
				const data = event.data || {};
				const fps = Number( data.fps );
				if (
					event.origin !== window.location.origin ||
					event.source !== labFrame.contentWindow ||
					data.type !== 'geek-cube-player-fps' ||
					data.profileId !== labFrame.dataset.profileId ||
					! Number.isFinite( fps )
				) {
					return;
				}

				const input = testForm.querySelector( '[data-geek-cube-observed-fps]' );
				const output = testForm.querySelector( '[data-geek-cube-observed-fps-output]' );
				const value = fps.toFixed( 1 );
				if ( input ) {
					input.value = value;
				}
				if ( output ) {
					output.value = value + ' FPS';
					output.textContent = output.value;
				}
			} );
		}
	}
}() );
