<?php
/**
 * Import/export parsing and serialization for snippet data.
 *
 * Every piece of code that has to touch an externally-supplied
 * import file lives here, so the security-sensitive parsing logic
 * has exactly one home. Two input/output shapes are supported: the
 * plugin's own JSON export shape, and an equivalent XML shape.
 *
 * Parsing never inserts anything — it only ever returns data for the
 * admin's review (see SnipCore_Admin::handle_import_upload()). The
 * eventual insert always goes back through SnipCore_Snippets::insert(),
 * which sanitizes independently, so nothing parsed here is trusted
 * further than "worth previewing".
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Import_Export
 */
class SnipCore_Import_Export {

	/**
	 * Hard cap on a single uploaded file's size, in bytes. Generous
	 * for a text-based snippet export, small enough to keep parsing
	 * cheap and bound memory use regardless of what a file claims to
	 * contain.
	 *
	 * @var int
	 */
	const MAX_FILE_BYTES = 2097152; // 2 MB.

	/**
	 * Hard cap on the number of snippets carried into a single
	 * preview/import batch, across all uploaded files combined.
	 *
	 * @var int
	 */
	const MAX_ITEMS = 200;

	/**
	 * Snippet fields accepted from an imported record. Anything else
	 * present in the source file is ignored outright — imported data
	 * is rebuilt field by field rather than merged in wholesale.
	 *
	 * Deliberately excludes id/snip_num_id/shortcode_id/status/trashed/
	 * modified/created: identity and activation fields must only ever
	 * be assigned by this site itself (see sanitize_preview() and
	 * SnipCore_Admin::handle_import_confirm()), never taken from an
	 * import file, so a cloned/exported/re-imported snippet can never
	 * collide with or impersonate an existing one.
	 *
	 * @var string[]
	 */
	const FIELDS = array(
		'name',
		'type',
		'location',
		'code',
		'description',
		'tags',
		'priority',
		'display_mode',
		'display_post_ids',
		'device_display',
		'schedule_enabled',
		'schedule_start',
		'schedule_end',
	);

	/**
	 * Every field carried for a snippet in a "Complete JSON Export"
	 * (see build_complete_export()) — the full stored record, not
	 * just the subset an import will accept.
	 *
	 * @var string[]
	 */
	const COMPLETE_SNIPPET_FIELDS = array(
		'id',
		'name',
		'type',
		'status',
		'location',
		'priority',
		'description',
		'tags',
		'code',
		'display_mode',
		'display_post_ids',
		'device_display',
		'schedule_enabled',
		'schedule_start',
		'schedule_end',
		'created',
		'modified',
	);

	/**
	 * Format version for the complete-export envelope. Only bumped if
	 * the top-level envelope shape changes in a way older parsers
	 * could not safely ignore; new optional keys don't require a bump.
	 *
	 * @var string
	 */
	const EXPORT_FORMAT_VERSION = '1.0';

	/**
	 * Builds the full, pretty-printed JSON body for a "Complete JSON
	 * Export": every non-trashed snippet, every field, wrapped in a
	 * small metadata envelope so the file is self-describing and
	 * works as a real backup rather than just a snippet list.
	 *
	 * The envelope is deliberately forward-compatible: parse() below
	 * only ever reads the keys it currently recognizes and ignores
	 * anything else, so a future version of SnipCore can add new
	 * top-level keys (or new per-snippet fields) to this shape
	 * without breaking an older install trying to import it, and an
	 * older export missing a newer field imports fine with that field
	 * simply defaulted.
	 *
	 * @return string
	 */
	public static function build_complete_export() {

		$snippets = array_map(
			static function ( $snippet ) {
				$complete = array();
				foreach ( self::COMPLETE_SNIPPET_FIELDS as $field ) {
					$complete[ $field ] = isset( $snippet[ $field ] ) ? $snippet[ $field ] : null;
				}
				return $complete;
			},
			SnipCore_Snippets::get_all( false )
		);

		$payload = array(
			'snipcore_export' => array(
				'format_version' => self::EXPORT_FORMAT_VERSION,
				'plugin_version' => defined( 'SNIPCORE_VERSION' ) ? SNIPCORE_VERSION : '',
				'generated'      => gmdate( 'c' ),
			),
			// Reserved for forward compatibility: future SnipCore
			// versions can grow this (or add sibling top-level keys)
			// for additional site-level configuration without
			// breaking older parsers, which ignore keys they don't
			// recognize. Populated with the current General settings
			// today so a complete export doubles as a full backup.
			'settings'        => SnipCore_Settings::get_all(),
			'snippets'        => $snippets,
		);

		return (string) wp_json_encode( $payload, JSON_PRETTY_PRINT );
	}

	/**
	 * Parses one uploaded file's contents into preview-ready snippet
	 * records. Detects JSON vs XML from the content itself (not the
	 * filename), so a mislabeled extension can't skip validation.
	 *
	 * @param string $contents     Raw file contents.
	 * @param string $source_label Human-readable label (e.g. original filename) used in messages shown to the admin.
	 * @param array  $seen_names   Lowercased names already claimed earlier in the same import batch (by reference — updated as items are found so duplicates *within* the batch, not just against existing snippets, are also flagged).
	 * @return array {
	 *     @type array $items  Zero or more records: array{source: string, snippet: array, is_duplicate: bool}.
	 *     @type array $errors Human-readable strings describing anything skipped or invalid.
	 * }
	 */
	public static function parse( $contents, $source_label, array &$seen_names = array() ) {
		$errors  = array();
		$trimmed = ltrim( (string) $contents );

		if ( '' === $trimmed ) {
			$errors[] = sprintf(
				/* translators: %s: file name. */
				__( '"%s" is empty and was skipped.', 'snipcore' ),
				$source_label
			);
			return array(
				'items'  => array(),
				'errors' => $errors,
			);
		}

		$raw_items = ( '<' === $trimmed[0] )
			? self::parse_xml_string( $contents, $source_label, $errors )
			: self::parse_json_string( $contents, $source_label, $errors );

		$items = array();

		foreach ( $raw_items as $raw_item ) {

			if ( ! is_array( $raw_item ) || empty( $raw_item['name'] ) ) {
				continue;
			}

			$fields = array();
			foreach ( self::FIELDS as $field ) {
				if ( isset( $raw_item[ $field ] ) ) {
					$fields[ $field ] = $raw_item[ $field ];
				}
			}

			$snippet = SnipCore_Snippets::sanitize_preview( $fields );

			$name_key = function_exists( 'mb_strtolower' )
				? mb_strtolower( trim( $snippet['name'] ) )
				: strtolower( trim( $snippet['name'] ) );

			// Flags name collisions both against snippets that already
			// exist in the store and against other items earlier in
			// this same import batch (e.g. two files defining the same
			// snippet name). Either way the admin sees it before
			// anything is written — see handle_import_confirm(), which
			// never overwrites regardless of what's selected here.
			$is_duplicate = ( '' !== $name_key )
				&& ( SnipCore_Snippets::name_exists( $snippet['name'] ) || in_array( $name_key, $seen_names, true ) );

			if ( '' !== $name_key ) {
				$seen_names[] = $name_key;
			}

			$items[] = array(
				'source'       => $source_label,
				// Runs the same validation/sanitization used everywhere
				// else, so what the admin previews matches exactly what
				// gets stored if they confirm.
				'snippet'      => $snippet,
				'is_duplicate' => $is_duplicate,
			);
		}

		if ( empty( $items ) && empty( $errors ) ) {
			$errors[] = sprintf(
				/* translators: %s: file name. */
				__( '"%s" did not contain any recognizable snippets.', 'snipcore' ),
				$source_label
			);
		}

		return array(
			'items'  => $items,
			'errors' => $errors,
		);
	}

	/**
	 * Decodes a JSON export (single snippet object, or an array of them).
	 *
	 * @param string   $contents     Raw file contents.
	 * @param string   $source_label File label for messages.
	 * @param string[] $errors       Error list, appended to by reference.
	 * @return array[] Raw (unsanitized) snippet arrays.
	 */
	private static function parse_json_string( $contents, $source_label, array &$errors ) {

		// Depth-limited decode: a snippet export is never more than a
		// couple of levels deep, so a bound here costs nothing and
		// rules out deeply-nested-structure resource exhaustion.
		$decoded = json_decode( (string) $contents, true, 20 );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$errors[] = sprintf(
				/* translators: %s: file name. */
				__( '"%s" could not be read as valid JSON and was skipped.', 'snipcore' ),
				$source_label
			);
			return array();
		}

		if ( ! is_array( $decoded ) ) {
			$errors[] = sprintf(
				/* translators: %s: file name. */
				__( '"%s" did not contain any snippet data.', 'snipcore' ),
				$source_label
			);
			return array();
		}

		// A "Complete JSON Export" wraps its snippets in an envelope
		// (see build_complete_export()); unwrap it if present. Falls
		// through to the older shapes otherwise: a single exported
		// snippet object, or a flat array of them.
		if ( isset( $decoded['snippets'] ) && is_array( $decoded['snippets'] ) ) {
			$items = $decoded['snippets'];
		} else {
			$items = isset( $decoded['name'] ) ? array( $decoded ) : $decoded;
		}

		return array_values( array_filter( $items, 'is_array' ) );
	}

	/**
	 * Parses an XML export (root <snipcore-snippets> containing one or
	 * more <snippet> elements, or a single top-level <snippet>).
	 *
	 * @param string   $contents     Raw file contents.
	 * @param string   $source_label File label for messages.
	 * @param string[] $errors       Error list, appended to by reference.
	 * @return array[] Raw (unsanitized) snippet arrays.
	 */
	private static function parse_xml_string( $contents, $source_label, array &$errors ) {

		$dom = self::load_secure_dom( $contents );

		if ( null === $dom ) {
			$errors[] = sprintf(
				/* translators: %s: file name. */
				__( '"%s" could not be read as valid XML and was skipped.', 'snipcore' ),
				$source_label
			);
			return array();
		}

		$root = $dom->documentElement;

		if ( null === $root ) {
			$errors[] = sprintf(
				/* translators: %s: file name. */
				__( '"%s" did not contain any snippet data.', 'snipcore' ),
				$source_label
			);
			return array();
		}

		$snippet_nodes = array();

		if ( 'snippet' === $root->nodeName ) {
			$snippet_nodes[] = $root;
		} else {
			foreach ( $root->getElementsByTagName( 'snippet' ) as $node ) {
				$snippet_nodes[] = $node;
			}
		}

		$items = array();
		foreach ( $snippet_nodes as $node ) {
			$items[] = self::xml_node_to_array( $node );
		}

		return $items;
	}

	/**
	 * Loads XML into a DOMDocument with every external-entity and
	 * external-DTD pathway closed off, regardless of what the document
	 * itself declares. This is the only place in the plugin that
	 * parses XML from an untrusted source, so the hardening lives
	 * entirely in one, easy-to-audit place:
	 *
	 * - A null external entity loader is installed for the duration of
	 *   the parse, so no external entity (file://, http://, etc.) can
	 *   ever be resolved, whatever flags end up being honored.
	 * - LIBXML_NONET blocks network access outright.
	 * - LIBXML_NOENT/LIBXML_DTDLOAD are deliberately never passed, so
	 *   entities are never substituted and external DTDs are never
	 *   fetched.
	 * - Any document that declares a DOCTYPE at all is rejected
	 *   outright. A well-formed export never has one, and refusing it
	 *   removes the entity-expansion ("billion laughs") and external
	 *   subset attack surface entirely rather than relying on flags.
	 *
	 * @param string $contents Raw XML contents.
	 * @return DOMDocument|null The parsed document, or null if it is not safe/valid XML.
	 */
	private static function load_secure_dom( $contents ) {

		if ( '' === trim( (string) $contents ) ) {
			return null;
		}

		$entity_loader_supported = function_exists( 'libxml_set_external_entity_loader' );
		$previous_loader         = null;

		if ( $entity_loader_supported ) {
			$previous_loader = libxml_set_external_entity_loader(
				static function () {
					return null;
				}
			);
		}

		$previous_use_errors = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$dom                          = new DOMDocument();
		$dom->resolveExternalEntities = false;
		$dom->substituteEntities      = false;

		// phpcs:ignore Generic.PHP.NoSilencedErrors -- malformed input is expected from user uploads; errors are read via libxml_get_errors() below, not swallowed.
		$loaded = @$dom->loadXML( $contents, LIBXML_NONET );

		$parse_errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_use_errors );

		if ( $entity_loader_supported ) {
			libxml_set_external_entity_loader( $previous_loader );
		}

		if ( ! $loaded || ! empty( $parse_errors ) ) {
			return null;
		}

		foreach ( $dom->childNodes as $node ) {
			if ( XML_DOCUMENT_TYPE_NODE === $node->nodeType ) {
				return null;
			}
		}

		return $dom;
	}

	/**
	 * Converts a single <snippet> element into a raw associative array
	 * matching the JSON export shape.
	 *
	 * @param DOMElement $node A <snippet> element.
	 * @return array
	 */
	private static function xml_node_to_array( DOMElement $node ) {
		$item = array();

		foreach ( $node->childNodes as $child ) {

			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			if ( 'tags' === $child->nodeName ) {
				$tags = array();
				foreach ( $child->getElementsByTagName( 'tag' ) as $tag_node ) {
					$tags[] = $tag_node->textContent;
				}
				$item['tags'] = $tags;
				continue;
			}

			if ( 'display_post_ids' === $child->nodeName ) {
				$post_ids = array();
				foreach ( $child->getElementsByTagName( 'post_id' ) as $post_id_node ) {
					$post_ids[] = $post_id_node->textContent;
				}
				$item['display_post_ids'] = $post_ids;
				continue;
			}

			$item[ $child->nodeName ] = $child->textContent;
		}

		return $item;
	}

	/**
	 * Serializes a list of (already-sanitized) snippet arrays to a
	 * pretty-printed JSON export.
	 *
	 * @param array $snippets Snippet records.
	 * @return string
	 */
	public static function to_json( array $snippets ) {
		return (string) wp_json_encode( $snippets, JSON_PRETTY_PRINT );
	}

	/**
	 * Serializes a list of (already-sanitized) snippet arrays to an
	 * XML export matching the shape parse_xml_string() reads back.
	 *
	 * @param array $snippets Snippet records.
	 * @return string
	 */
	public static function to_xml( array $snippets ) {
		$dom               = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true;

		$root = $dom->createElement( 'snipcore-snippets' );
		$dom->appendChild( $root );

		foreach ( $snippets as $snippet ) {
			$root->appendChild( self::snippet_to_xml_node( $dom, $snippet ) );
		}

		return (string) $dom->saveXML();
	}

	/**
	 * Builds a single <snippet> element. Every value is written via
	 * createTextNode(), which DOM escapes markup characters (&, <, >)
	 * automatically on output. Values are additionally passed through
	 * strip_illegal_xml_chars() first: XML 1.0 forbids most control
	 * characters in text content outright (not just markup ones), and
	 * DOMDocument does not sanitize those — passing one straight to
	 * createTextNode() throws and would turn an Export click into a
	 * fatal error instead of a download. Snippet code is free-form
	 * text that can plausibly contain a stray control character (e.g.
	 * pasted from a binary-ish source), so this applies to every
	 * field, not just code.
	 *
	 * @param DOMDocument $dom      Owner document.
	 * @param array       $snippet  Snippet record.
	 * @return DOMElement
	 */
	private static function snippet_to_xml_node( DOMDocument $dom, array $snippet ) {
		$node = $dom->createElement( 'snippet' );

		$simple_fields = array(
			'name',
			'type',
			'location',
			'priority',
			'description',
			'code',
			'display_mode',
			'device_display',
			'schedule_enabled',
			'schedule_start',
			'schedule_end',
		);

		foreach ( $simple_fields as $field ) {
			$value = isset( $snippet[ $field ] ) ? self::stringify_xml_field( $field, $snippet[ $field ] ) : '';
			$el    = $dom->createElement( $field );
			$el->appendChild( $dom->createTextNode( self::strip_illegal_xml_chars( $value ) ) );
			$node->appendChild( $el );
		}

		$tags_el = $dom->createElement( 'tags' );
		if ( ! empty( $snippet['tags'] ) && is_array( $snippet['tags'] ) ) {
			foreach ( $snippet['tags'] as $tag ) {
				$tag_el = $dom->createElement( 'tag' );
				$tag_el->appendChild( $dom->createTextNode( self::strip_illegal_xml_chars( (string) $tag ) ) );
				$tags_el->appendChild( $tag_el );
			}
		}
		$node->appendChild( $tags_el );

		$display_post_ids_el = $dom->createElement( 'display_post_ids' );
		if ( ! empty( $snippet['display_post_ids'] ) && is_array( $snippet['display_post_ids'] ) ) {
			foreach ( $snippet['display_post_ids'] as $post_id ) {
				$post_id_el = $dom->createElement( 'post_id' );
				$post_id_el->appendChild( $dom->createTextNode( (string) absint( $post_id ) ) );
				$display_post_ids_el->appendChild( $post_id_el );
			}
		}
		$node->appendChild( $display_post_ids_el );

		return $node;
	}

	/**
	 * Converts a single field's stored value to the plain-text form
	 * written into its XML element. Every field but this handful is
	 * already a string; a boolean (schedule_enabled) needs an explicit
	 * "1"/"" so xml_node_to_array()'s plain textContent read-back
	 * matches what sanitize()'s `! empty()` check expects.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw field value.
	 * @return string
	 */
	private static function stringify_xml_field( $field, $value ) {
		if ( 'schedule_enabled' === $field ) {
			return $value ? '1' : '';
		}
		return (string) $value;
	}

	/**
	 * Removes characters that are not legal anywhere in an XML 1.0
	 * document (most C0 control characters other than tab/LF/CR).
	 * DOMDocument does not do this itself, so without it a stray
	 * control character in a snippet's code/description/tag would
	 * make createTextNode() throw and turn an Export into a fatal
	 * error rather than a downloaded file.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private static function strip_illegal_xml_chars( $value ) {
		$stripped = preg_replace( '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value );

		// preg_replace() with the /u modifier returns null if $value is
		// not valid UTF-8, rather than throwing — falling back to a
		// byte-level ASCII-control strip keeps content instead of
		// silently discarding it in that edge case.
		if ( null === $stripped ) {
			$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		}

		return (string) $stripped;
	}
}
