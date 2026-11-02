/**
 * The website assistant beside the page: an "Assistant" panel in the block editor and the Site Editor that frames
 * the assistant's chat (Features/EditorAssistant.php). No build step: plain JavaScript on the wp.* globals.
 *
 * Messages with the chat, only to and from the assistant's own address:
 * - from the chat: { source: 'anypress-assistant', type: 'sign-in' | 'ready' | 'apply', id?, change? }
 * - to the chat: { source: 'anypress-editor', type: 'view', view } with the page, its top-level blocks and the
 *   selected block; { source: 'anypress-editor', type: 'applied', id, ok }.
 * A change lands in the editor like any edit: the owner sees it outlined on the page, and a card at the top of the
 * panel says what changed with Undo and Save. Nothing is kept until the owner saves.
 */
( function ( wp, config ) {
	if ( ! wp || ! config || ! wp.plugins || ! wp.editor || ! wp.editor.PluginSidebar ) {
		return;
	}
	const { createElement: el, useEffect, useRef, useState } = wp.element;
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
		const before = id ? blocks.getBlock( id ) : null;
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
		return { label: label( change.action, before, fresh ), shown: fresh.length ? fresh[ 0 ].clientId : null };
	}

	/** A block's name as the owner knows it: "Heading", "Group". */
	function title( block ) {
		const type = block && wp.blocks.getBlockType( block.name );
		return ( type && type.title ) || __( 'Block', 'waas-client' );
	}

	/** What one change did, in a few words. */
	function label( action, before, fresh ) {
		if ( action === 'remove' ) {
			/* translators: %s: a block's name, such as Heading. */
			return wp.i18n.sprintf( __( 'Removed: %s', 'waas-client' ), title( before ) );
		}
		if ( action === 'replace_all' ) {
			return __( 'Rewrote the page', 'waas-client' );
		}
		const what = fresh.length === 1 ? title( fresh[ 0 ] ) : wp.i18n.sprintf(
			/* translators: %d: a number of blocks. */
			__( '%d blocks', 'waas-client' ),
			fresh.length
		);
		/* translators: %s: a block's name, such as Heading, or a number of blocks. */
		return wp.i18n.sprintf( action === 'insert' ? __( 'Added: %s', 'waas-client' ) : __( 'Changed: %s', 'waas-client' ), what );
	}

	/** Brings the changed block into view and outlines it for a moment, without moving the owner's cursor away for long. */
	function show( clientId ) {
		if ( ! clientId ) {
			return;
		}
		setTimeout( function () {
			const canvas = document.querySelector( 'iframe[name="editor-canvas"]' );
			const doc = canvas && canvas.contentDocument ? canvas.contentDocument : document;
			const node = doc.getElementById( 'block-' + clientId );
			if ( ! node ) {
				return;
			}
			node.scrollIntoView( { block: 'center', behavior: matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
			// The page is in its own frame without this file's styles, so the outline is set on the block itself.
			const before = { outline: node.style.outline, outlineOffset: node.style.outlineOffset, transition: node.style.transition };
			node.style.transition = 'outline-color 0.6s ease';
			node.style.outline = '2px solid var(--wp-admin-theme-color, #3858e9)';
			node.style.outlineOffset = '4px';
			setTimeout( function () {
				node.style.outlineColor = 'transparent';
			}, 1800 );
			setTimeout( function () {
				Object.assign( node.style, before );
			}, 2600 );
		}, 60 );
	}

	/** A problem the owner should know about, in the editor's own notices. */
	function warn( message ) {
		dispatch( 'core/notices' ).createErrorNotice( message, { type: 'snackbar', id: NAME } );
	}

	/**
	 * The card at the top of the panel after the assistant changes the page: what changed, Undo and Save. It goes away
	 * once nothing is left unsaved (the owner saved, or undid everything), and says "Saved" for a moment after a save.
	 */
	function Changes( props ) {
		const items = props.items;
		const editor = select( 'core/editor' );
		const saving = editor.isSavingPost() && ! editor.isAutosavingPost();
		if ( props.saved ) {
			return el(
				'div',
				{ className: 'waas-editor-assistant__changes is-saved', role: 'status' },
				el( 'span', { className: 'waas-editor-assistant__dot', 'aria-hidden': true } ),
				el( 'p', { className: 'waas-editor-assistant__what' }, __( 'Saved. Your page is up to date.', 'waas-client' ) )
			);
		}
		if ( ! items.length ) {
			return null;
		}
		const last = items[ items.length - 1 ];
		return el(
			'div',
			{ className: 'waas-editor-assistant__changes', role: 'status' },
			el( 'span', { className: 'waas-editor-assistant__dot', 'aria-hidden': true } ),
			el(
				'div',
				{ className: 'waas-editor-assistant__text' },
				el( 'p', { className: 'waas-editor-assistant__what' }, last ),
				el(
					'p',
					{ className: 'waas-editor-assistant__note' },
					items.length > 1
						? wp.i18n.sprintf(
							/* translators: %d: how many changes are not saved yet. */
							wp.i18n._n( '%d change, not saved yet', '%d changes, not saved yet', items.length, 'waas-client' ),
							items.length
						)
						: __( 'Not saved yet', 'waas-client' )
				)
			),
			el(
				'div',
				{ className: 'waas-editor-assistant__actions' },
				el( wp.components.Button, { variant: 'tertiary', size: 'compact', onClick: props.onUndo, disabled: saving }, __( 'Undo', 'waas-client' ) ),
				el(
					wp.components.Button,
					{ variant: 'primary', size: 'compact', onClick: props.onSave, isBusy: saving, disabled: saving },
					saving ? __( 'Saving…', 'waas-client' ) : __( 'Save', 'waas-client' )
				)
			)
		);
	}

	function Chat() {
		const frame = useRef( null );
		const signingIn = useRef( false );
		const [ items, setItems ] = useState( [] );
		const [ saved, setSaved ] = useState( false );
		const [ , redraw ] = useState( 0 );

		// The card follows the editor: it clears once nothing is unsaved, and redraws while a save runs.
		useEffect( function () {
			let wasSaving = false;
			return subscribe( function () {
				const editor = select( 'core/editor' );
				const savingNow = editor.isSavingPost() && ! editor.isAutosavingPost();
				if ( savingNow !== wasSaving ) {
					wasSaving = savingNow;
					redraw( function ( n ) {
						return n + 1;
					} );
				}
				if ( ! editor.isEditedPostDirty() ) {
					setItems( function ( list ) {
						return list.length ? [] : list;
					} );
				}
			} );
		}, [] );

		useEffect( function () {
			if ( ! saved ) {
				return undefined;
			}
			const timer = setTimeout( function () {
				setSaved( false );
			}, 3000 );
			return function () {
				clearTimeout( timer );
			};
		}, [ saved ] );

		const undo = function () {
			dispatch( 'core/editor' ).undo();
			setItems( function ( list ) {
				return list.slice( 0, -1 );
			} );
		};
		const save = function () {
			Promise.resolve( dispatch( 'core/editor' ).savePost() ).then( function () {
				const editor = select( 'core/editor' );
				if ( ! editor.didPostSaveRequestFail() ) {
					setItems( [] );
					setSaved( true );
				}
			} );
		};

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
							warn( ( error && error.message ) || __( 'The assistant can’t be reached right now.', 'waas-client' ) );
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
						const done = apply( data.change );
						setSaved( false );
						setItems( function ( list ) {
							return list.concat( [ done.label ] );
						} );
						show( done.shown );
						send( { type: 'applied', id: data.id, ok: true } );
					} catch ( error ) {
						warn( error.message );
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

		return el(
			'div',
			{ className: 'waas-editor-assistant__body' },
			el( Changes, { items, saved, onUndo: undo, onSave: save } ),
			el( 'iframe', {
				ref: frame,
				className: 'waas-editor-assistant__frame',
				title: __( 'Website assistant', 'waas-client' ),
				src: config.assistantUrl + '/editor',
				allow: 'clipboard-write; microphone',
			} )
		);
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
