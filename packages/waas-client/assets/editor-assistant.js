/**
 * The website assistant beside the page: an "Assistant" panel in the block editor and the Site Editor that frames
 * the assistant's chat (Features/EditorAssistant.php). No build step: plain JavaScript on the wp.* globals.
 *
 * Messages with the chat, only to and from the assistant's own address:
 * - from the chat: { source: 'anypress-assistant', type: 'sign-in' | 'ready' | 'apply', id?, change? }
 * - to the chat: { source: 'anypress-editor', type: 'view', view } with the page, its top-level blocks and the
 *   selected block; { source: 'anypress-editor', type: 'applied', id, ok }.
 * A change lands in the editor like any edit: the owner sees it, can undo it, and keeps it by pressing Save.
 */
( function ( wp, config ) {
	if ( ! wp || ! config || ! wp.plugins || ! wp.editor || ! wp.editor.PluginSidebar ) {
		return;
	}
	const { createElement: el, useEffect, useRef } = wp.element;
	const { select, dispatch, subscribe } = wp.data;
	const { __ } = wp.i18n;

	const NAME = 'anypress-assistant';
	const SIDEBAR = 'assistant';
	const FROM_CHAT = 'anypress-assistant';
	const TO_CHAT = 'anypress-editor';
	const origin = new URL( config.assistantUrl ).origin;
	/** Markup longer than this is named only; the assistant asks the owner to select it. */
	const MAX_MARKUP = 40000;
	const SITE_TYPES = [ 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' ];

	function describe( block ) {
		let markup = '';
		try {
			markup = wp.blocks.serialize( block );
		} catch ( e ) {}
		return { clientId: block.clientId, name: block.name, markup: markup.length > MAX_MARKUP ? '' : markup };
	}

	/** What the editor has open, for the assistant. */
	function view() {
		const editor = select( 'core/editor' );
		const blocks = select( 'core/block-editor' );
		const postType = editor.getCurrentPostType() || '';
		const title = editor.getEditedPostAttribute( 'title' );
		const selected = blocks.getSelectedBlock();
		return {
			screen: SITE_TYPES.indexOf( postType ) >= 0 ? 'site' : 'post',
			postType,
			postId: editor.getCurrentPostId() || 0,
			title: typeof title === 'string' ? title : '',
			blocks: blocks.getBlocks().slice( 0, 200 ).map( describe ),
			selected: selected ? describe( selected ) : null,
		};
	}

	/** Blocks from the assistant's markup, without the empty space between them. */
	function parse( content ) {
		return wp.blocks.parse( content || '' ).filter( function ( block ) {
			return block.name && ! ( block.name === 'core/freeform' && ! ( block.attributes.content || '' ).trim() );
		} );
	}

	/** Puts one change into the editor. Throws when it can't. */
	function apply( change ) {
		const blocks = select( 'core/block-editor' );
		const edit = dispatch( 'core/block-editor' );
		const id = change.clientId;
		if ( id && ! blocks.getBlock( id ) ) {
			throw new Error( __( 'That block is no longer on the page.', 'waas-client' ) );
		}
		const fresh = change.action === 'remove' ? [] : parse( change.content );
		if ( change.action !== 'remove' && ! fresh.length ) {
			throw new Error( __( 'The assistant sent no blocks.', 'waas-client' ) );
		}
		switch ( change.action ) {
			case 'replace':
				edit.replaceBlocks( id, fresh );
				break;
			case 'remove':
				edit.removeBlocks( id );
				break;
			case 'insert': {
				if ( ! id ) {
					edit.insertBlocks( fresh );
					break;
				}
				const root = blocks.getBlockRootClientId( id ) || undefined;
				const index = blocks.getBlockIndex( id ) + ( change.position === 'before' ? 0 : 1 );
				edit.insertBlocks( fresh, index, root );
				break;
			}
			case 'replace_all': {
				const all = blocks.getBlockOrder();
				if ( all.length ) {
					edit.replaceBlocks( all, fresh );
				} else {
					edit.insertBlocks( fresh );
				}
				break;
			}
			default:
				throw new Error( __( 'The assistant sent a change the editor doesn’t know.', 'waas-client' ) );
		}
	}

	function notify( ok, message ) {
		const notices = dispatch( 'core/notices' );
		if ( ! ok ) {
			notices.createErrorNotice( message, { type: 'snackbar', id: NAME } );
			return;
		}
		notices.createSuccessNotice( __( 'The assistant changed the page. Save to keep it.', 'waas-client' ), {
			type: 'snackbar',
			id: NAME,
			actions: [ { label: __( 'Undo', 'waas-client' ), onClick: () => dispatch( 'core/editor' ).undo() } ],
		} );
	}

	function Chat() {
		const frame = useRef( null );
		const signingIn = useRef( false );

		useEffect( function () {
			let stop = null;
			let timer = null;
			const send = function ( message ) {
				if ( frame.current && frame.current.contentWindow ) {
					frame.current.contentWindow.postMessage( Object.assign( { source: TO_CHAT }, message ), origin );
				}
			};
			const sendView = function () {
				send( { type: 'view', view: view() } );
			};
			const listen = function ( event ) {
				if ( event.origin !== origin || ! frame.current || event.source !== frame.current.contentWindow ) {
					return;
				}
				const data = event.data || {};
				if ( data.source !== FROM_CHAT ) {
					return;
				}
				if ( data.type === 'sign-in' && ! signingIn.current ) {
					// Once per opening: a sign-in that fails shows its message in the chat instead of trying again.
					signingIn.current = true;
					wp.apiFetch( { path: config.handoffPath, method: 'POST' } )
						.then( function ( answer ) {
							if ( frame.current && answer && answer.url && answer.url.indexOf( config.assistantUrl + '/' ) === 0 ) {
								frame.current.src = answer.url + '&back=/editor';
							}
						} )
						.catch( function ( error ) {
							notify( false, ( error && error.message ) || __( 'The assistant can’t be reached right now.', 'waas-client' ) );
						} );
				}
				if ( data.type === 'ready' ) {
					sendView();
					// Keep the chat's view current as the owner selects and edits, at most a few times a second.
					if ( ! stop ) {
						stop = subscribe( function () {
							clearTimeout( timer );
							timer = setTimeout( sendView, 400 );
						} );
					}
				}
				if ( data.type === 'apply' && data.change ) {
					try {
						apply( data.change );
						notify( true );
						send( { type: 'applied', id: data.id, ok: true } );
					} catch ( error ) {
						notify( false, error.message );
						send( { type: 'applied', id: data.id, ok: false } );
					}
				}
			};
			window.addEventListener( 'message', listen );
			return function () {
				window.removeEventListener( 'message', listen );
				clearTimeout( timer );
				if ( stop ) {
					stop();
				}
			};
		}, [] );

		return el( 'iframe', {
			ref: frame,
			className: 'waas-editor-assistant__frame',
			title: __( 'Website assistant', 'waas-client' ),
			src: config.assistantUrl + '/editor',
			allow: 'clipboard-write; microphone',
		} );
	}

	const icon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, 'aria-hidden': true },
		el( 'path', { d: 'M12 2.5l2.1 6.4 6.4 2.1-6.4 2.1L12 19.5l-2.1-6.4L3.5 11l6.4-2.1L12 2.5z' } ),
		el( 'path', { d: 'M18.5 15.5l.9 2.6 2.6.9-2.6.9-.9 2.6-.9-2.6-2.6-.9 2.6-.9.9-2.6z' } )
	);

	wp.plugins.registerPlugin( NAME, {
		icon,
		render: function () {
			return el(
				wp.editor.PluginSidebar,
				{ name: SIDEBAR, title: __( 'Assistant', 'waas-client' ), icon, className: 'waas-editor-assistant' },
				el( Chat )
			);
		},
	} );

	// The admin bar's Assistant button opens the editor with the panel open. Once the editor is ready: on a phone
	// WordPress closes its sidebars while it loads, so the panel is opened after that, and again if it was closed.
	if ( new URLSearchParams( window.location.search ).get( config.openParam ) === '1' ) {
		const area = NAME + '/' + SIDEBAR;
		const open = function () {
			if ( select( 'core/interface' ).getActiveComplementaryArea( 'core' ) !== area ) {
				dispatch( 'core/interface' ).enableComplementaryArea( 'core', area );
			}
		};
		const stop = subscribe( function () {
			const editor = select( 'core/editor' );
			if ( editor.__unstableIsEditorReady && ! editor.__unstableIsEditorReady() ) {
				return;
			}
			stop();
			setTimeout( open, 0 );
			setTimeout( open, 800 );
		} );
	}
} )( window.wp, window.waasEditorAssistant );
