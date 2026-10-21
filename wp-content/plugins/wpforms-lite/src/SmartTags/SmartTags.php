<?php

// phpcs:disable Generic.Commenting.DocComment.MissingShort
/** @noinspection PhpIllegalPsrClassPathInspection */
/** @noinspection PhpUndefinedClassInspection */
// phpcs:enable Generic.Commenting.DocComment.MissingShort

namespace WPForms\SmartTags;

use ActionScheduler_Action;
use WPForms\SmartTags\SmartTag\Generic;
use WPForms\SmartTags\SmartTag\SmartTag;

/**
 * Class SmartTags.
 *
 * @since 1.6.7
 */
class SmartTags {

	/**
	 * Core contexts that print a smart tag value into markup, so its quotes and shortcode
	 * delimiters are made inert. Addons extend the list through `get_markup_contexts()`.
	 *
	 * The User Registration message replaces the form inside the same block output, so it needs
	 * the same treatment as the form description.
	 *
	 * @since 2.0.2.1
	 *
	 * @var string[]
	 */
	private const MARKUP_CONTEXTS = [
		'field-properties',
		'form-description',
		'confirmation',
		'user-registration-frontend-message',
	];

	/**
	 * One `name=value` pair of an HTML tag, whichever way the value is quoted.
	 *
	 * @since 2.0.2.1
	 *
	 * @var string
	 */
	private const ATTRIBUTE_PATTERN = '#(?P<name>[a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"(?P<double>[^"]*)"|\'(?P<single>[^\']*)\'|(?P<bare>[^\s>]+))#';

	/**
	 * Attributes whose value the browser parses as a whole HTML document after decoding it.
	 *
	 * The value is a document of its own, so the tag is substituted inside it with the same
	 * positional escaping as outside, and the result is encoded once more for the attribute.
	 *
	 * @since 2.0.2.2
	 *
	 * @var string[]
	 */
	private const DOCUMENT_ATTRIBUTES = [ 'srcdoc' ];

	/**
	 * Prefix every event handler attribute carries, `onclick`, `onload` and the rest.
	 *
	 * @since 2.0.2.2
	 *
	 * @var string
	 */
	private const SCRIPT_ATTRIBUTE_PREFIX = 'on';

	/**
	 * Opening tag of a script element.
	 *
	 * Only whitespace, `/` or `>` ends a tag name, so `<script-x>` is another element. PCRE `\s`
	 * also matches a vertical tab, which the HTML tokenizer does not treat as whitespace.
	 *
	 * @since 2.0.2.2
	 *
	 * @var string
	 */
	private const SCRIPT_START_PATTERN = '#^<script[\t\n\f\r />]#i';

	/**
	 * End tag of a script element, which the browser recognizes only when the name is complete,
	 * so `</scriptx>` stays script text.
	 *
	 * @since 2.0.2.2
	 *
	 * @var string
	 */
	private const SCRIPT_END_PATTERN = '#^</script[\t\n\f\r />]#i';

	/**
	 * URI attributes `wp_kses_uri_attributes()` does not list.
	 *
	 * @since 2.0.2.2
	 *
	 * @var string[]
	 */
	private const URI_ATTRIBUTES = [ 'xlink:href' ];

	/**
	 * SVG animation elements, which can write any value into `href` while the page runs.
	 *
	 * @since 2.0.2.2
	 *
	 * @var string[]
	 */
	private const ANIMATION_ELEMENTS = [ 'set', 'animate' ];

	/**
	 * Attributes holding the value an animation element writes. `values` is a `;`-separated list.
	 *
	 * @since 2.0.2.2
	 *
	 * @var string[]
	 */
	private const ANIMATION_VALUE_ATTRIBUTES = [ 'to', 'from', 'by', 'values' ];

	/**
	 * List of smart tags.
	 *
	 * @since 1.6.7
	 *
	 * @var array
	 */
	protected $smart_tags = [];

	/**
	 * AS task action arguments.
	 * Temporarily store them to use in the filter.
	 *
	 * @since 1.9.4
	 *
	 * @var array|null
	 */
	private $action_args;

	/**
	 * Fallback for entry meta.
	 * Temporary store callback to remove it after AS task execution.
	 *
	 * @since 1.9.4
	 *
	 * @var callable|null
	 */
	private $fallback;

	/**
	 * Markup contexts after the filter. Every smart tag in the content asks for the list.
	 *
	 * @since 2.0.2.1
	 *
	 * @var string[]|null
	 */
	private $markup_contexts;

	/**
	 * Hooks.
	 *
	 * @since 1.6.7
	 */
	public function hooks(): void {

		add_filter( 'wpforms_process_smart_tags', [ $this, 'process' ], 10, 6 );
		add_filter( 'wpforms_builder_enqueues_smart_tags', [ $this, 'builder' ] );
		add_filter( 'wpforms_builder_strings', [ $this, 'add_builder_strings' ], 10, 2 );

		add_action(
			'wpforms_process_entry_saved',
			function () {

				// Save super globals only after successes processing.
				add_filter( 'wpforms_tasks_task_register_async_args', [ $this, 'save_smart_tags_tasks_meta' ] );
			}
		);

		add_action( 'wpforms_tasks_start_executing', [ $this, 'maybe_add_entry_meta_fallback_value' ], 1, 2 );
		add_action( 'wpforms_tasks_stop_executing', [ $this, 'maybe_remove_entry_meta_fallback_value' ], 1 );
	}

	/**
	 * Get the list of smart tags.
	 *
	 * @since 1.6.7
	 *
	 * @return array
	 */
	public function get_smart_tags(): array {

		if ( ! empty( $this->smart_tags ) ) {
			return $this->smart_tags;
		}

		/**
		 * Modify the smart tags' list.
		 *
		 * @since 1.4.0
		 *
		 * @param array $tags The list of smart tags.
		 */
		$this->smart_tags = (array) apply_filters( // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName
			'wpforms_smart_tags',
			$this->smart_tags_list()
		);

		return $this->smart_tags;
	}

	/**
	 * Get the list of registered smart tags.
	 *
	 * @since 1.6.7
	 *
	 * @return array
	 */
	protected function smart_tags_list() {

		return [
			'admin_email'       => esc_html__( 'Site Administrator Email', 'wpforms-lite' ),
			'field_id'          => esc_html__( 'Field ID', 'wpforms-lite' ),
			'field_html_id'     => esc_html__( 'Field HTML ID', 'wpforms-lite' ),
			'field_value_id'    => esc_html__( 'Field Value', 'wpforms-lite' ),
			'form_id'           => esc_html__( 'Form ID', 'wpforms-lite' ),
			'form_name'         => esc_html__( 'Form Name', 'wpforms-lite' ),
			'page_title'        => esc_html__( 'Embedded Post/Page Title', 'wpforms-lite' ),
			'page_url'          => esc_html__( 'Embedded Post/Page URL', 'wpforms-lite' ),
			'page_id'           => esc_html__( 'Embedded Post/Page ID', 'wpforms-lite' ),
			'date'              => esc_html__( 'Date', 'wpforms-lite' ),
			'query_var'         => esc_html__( 'Query String Variable', 'wpforms-lite' ),
			'user_ip'           => esc_html__( 'User IP Address', 'wpforms-lite' ),
			'user_id'           => esc_html__( 'User ID', 'wpforms-lite' ),
			'user_display'      => esc_html__( 'User Display Name', 'wpforms-lite' ),
			'user_full_name'    => esc_html__( 'User Full Name', 'wpforms-lite' ),
			'user_first_name'   => esc_html__( 'User First Name', 'wpforms-lite' ),
			'user_last_name'    => esc_html__( 'User Last Name', 'wpforms-lite' ),
			'user_email'        => esc_html__( 'Logged-in User\'s Email', 'wpforms-lite' ),
			'user_meta'         => esc_html__( 'User Meta', 'wpforms-lite' ),
			'author_id'         => esc_html__( 'Author ID', 'wpforms-lite' ),
			'author_display'    => esc_html__( 'Author Name', 'wpforms-lite' ),
			'author_email'      => esc_html__( 'Author Email', 'wpforms-lite' ),
			'url_referer'       => esc_html__( 'Referrer URL', 'wpforms-lite' ),
			'url_login'         => esc_html__( 'Login URL', 'wpforms-lite' ),
			'url_logout'        => esc_html__( 'Logout URL', 'wpforms-lite' ),
			'url_register'      => esc_html__( 'Register URL', 'wpforms-lite' ),
			'url_lost_password' => esc_html__( 'Lost Password URL', 'wpforms-lite' ),
			'unique_value'      => esc_html__( 'Unique Value', 'wpforms-lite' ),
			'site_name'         => esc_html__( 'Site Name', 'wpforms-lite' ),
			'order_summary'     => esc_html__( 'Order Summary', 'wpforms-lite' ),
		];
	}

	/**
	 * Add the Form Builder strings.
	 *
	 * @since 1.9.5
	 *
	 * @param array   $strings Localized strings.
	 * @param WP_Post $form    Form object.
	 *
	 * @return array
	 * @noinspection HtmlUnknownTarget
	 * @noinspection PhpMissingParamTypeInspection
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function add_builder_strings( $strings, $form ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		$strings = (array) $strings;

		/**
		 * Smart Tags.
		 *
		 * @since 1.6.7
		 *
		 * @param array $smart_tags Array of smart tags.
		 */
		$smart_tags = (array) apply_filters( 'wpforms_builder_enqueues_smart_tags', $this->get_smart_tags() ); // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName

		$st_strings = [
			'smart_tags_dropdown_mce_icon'          => WPFORMS_PLUGIN_URL . 'assets/images/icon-tags.svg',
			'smart_tags'                            => $smart_tags,
			'smart_tags_disabled_for_fields'        => [ 'entry_id' ],
			'smart_tags_edit_ok_button'             => esc_html__( 'Apply changes', 'wpforms-lite' ),
			'smart_tags_delete_button'              => esc_html__( 'Delete smart tag', 'wpforms-lite' ),
			'smart_tags_edit'                       => esc_html__( 'edit', 'wpforms-lite' ),
			'smart_tags_arg'                        => esc_html__( 'argument', 'wpforms-lite' ),
			'smart_tags_unknown_field'              => esc_html__( 'Unknown Field', 'wpforms-lite' ),
			'smart_tags_templates'                  => [
				/* translators: %1$s - field ID, %2$s - field label. */
				'field_id'       => esc_html__( 'Field %1$s', 'wpforms-lite' ),
				/* translators: %1$s - field ID, %2$s - field label. */
				'field_value_id' => esc_html__( 'Field value %1$s', 'wpforms-lite' ),
				/* translators: %1$s - field ID, %2$s - field label. */
				'field_html_id'  => esc_html__( 'Field HTML %1$s', 'wpforms-lite' ),
				/* translators: %1$s - Query String Variable. */
				'query_var'      => esc_html__( 'Query String Variable: %1$s', 'wpforms-lite' ),
				/* translators: %1$s - User meta key. */
				'user_meta'      => esc_html__( 'User Meta: %1$s', 'wpforms-lite' ),
				/* translators: %1$s - Date format. */
				'date'           => esc_html__( 'Date: %1$s', 'wpforms-lite' ),
				/* translators: %1$s - Date format. */
				'entry_date'     => esc_html__( 'Entry Date: %1$s', 'wpforms-lite' ),
			],
			/**
			 * Filters the list of Smart Tags that are disabled for confirmations.
			 *
			 * @since 1.9.3
			 *
			 * @param array $disabled List of disabled Smart Tags.
			 */
			'smart_tags_disabled_for_confirmations' => apply_filters( 'wpforms_builder_smart_tags_disabled_for_confirmations', [] ), // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName
		];

		$st_strings['smart_tags_button_tooltip'] = sprintf(
			wp_kses( /* translators: %1$s - link to the WPForms.com doc article. */
				__( 'Easily add dynamic information from various sources with <a href="%1$s" target="_blank" rel="noopener noreferrer">Smart Tags</a>.', 'wpforms-lite' ),
				[
					'a' => [
						'href'   => [],
						'rel'    => [],
						'target' => [],
					],
				]
			),
			esc_url(
				wpforms_utm_link(
					'https://wpforms.com/docs/how-to-use-smart-tags-in-wpforms/',
					'Builder Settings',
					'Smart Tags Documentation'
				)
			)
		);

		return array_merge( $strings, $st_strings );
	}

	/**
	 * Process smart tags.
	 *
	 * @since 1.6.7
	 * @since 1.8.7   Added `$context` parameter.
	 * @since 1.9.9.2 Added `$context_data` parameter.
	 *
	 * @param string $content      Content.
	 * @param array  $form_data    Form data.
	 * @param array  $fields       List of fields.
	 * @param string $entry_id     Entry ID.
	 * @param string $context      Context.
	 * @param array  $context_data Context data.
	 *
	 * @return string
	 */
	public function process( $content, $form_data, $fields = [], $entry_id = '', $context = '', array $context_data = [] ) {

		// We shouldn't process smart tags in different WordPress editors
		// since it produce unexpected results.
		if ( wpforms_is_editor_page() ) {
			return $content;
		}

		$smart_tags = wpforms_get_all_smart_tags( $content );

		if ( empty( $smart_tags ) ) {
			return $content;
		}

		foreach ( $smart_tags as $smart_tag => $tag_name ) {
			$class_name       = $this->get_smart_tag_class_name( $tag_name );
			$smart_tag_object = new $class_name( $smart_tag, $context, $context_data );
			$value            = $smart_tag_object->get_value( $form_data, $fields, $entry_id );
			$field_id         = $smart_tag_object->get_attributes()['field_id'] ?? 0;
			$field_id         = (int) explode( '|', $field_id )[0];

			if (
				$context === 'confirmation_redirect' &&
				$field_id > 0 &&
				in_array(
					$fields[ $field_id ]['type'],
					wpforms_get_multi_fields(),
					true
				)
			) {
				// Protect from the case where the user already placed a pipe in the value.
				$value = str_replace(
					[ "\r\n", "\r", "\n", '|' ],
					[ rawurlencode( '|' ), '|', '|', '|' ],
					$value
				);
			}

			/**
			 * Modify the smart tag value.
			 *
			 * @since 1.6.7
			 * @since 1.6.7.1 Added the 5th argument.
			 * @since 1.9.0 Added the 6th argument.
			 *
			 * @param scalar|null $value            Smart Tag value.
			 * @param array       $form_data        Form data.
			 * @param array       $fields           List of fields.
			 * @param int         $entry_id         Entry ID.
			 * @param SmartTag    $smart_tag_object The smart tag object or the Generic object for those cases when class unregistered.
			 * @param string      $context          Context.
			 */
			$value = apply_filters( // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName
				"wpforms_smarttags_process_{$tag_name}_value",
				$value,
				$form_data,
				$fields,
				$entry_id,
				$smart_tag_object,
				$context
			);

			/**
			 * Modify a smart tag value.
			 *
			 * @since 1.6.7.1
			 * @since 1.9.7.3 Added the 7th argument.
			 *
			 * @param scalar|null $value            Smart Tag value.
			 * @param string      $tag_name         Smart tag name.
			 * @param array       $form_data        Form data.
			 * @param array       $fields           List of fields.
			 * @param int         $entry_id         Entry ID.
			 * @param SmartTag    $smart_tag_object The smart tag object or the Generic object for those cases when class unregistered.
			 * @param string      $context          Context.
			 */
			$value = apply_filters( // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName
				'wpforms_smarttags_process_value',
				$value,
				$tag_name,
				$form_data,
				$fields,
				$entry_id,
				$smart_tag_object,
				$context
			);

			if ( $value !== null ) {
				$content = $this->replace( $smart_tag, $value, $content, $context );
			}

			/**
			 * Modify content with smart tags.
			 *
			 * @since      1.4.0
			 * @since      1.6.7.1 Added 3rd, 4th, 5th, 6th arguments.
			 *
			 * @param string   $content          Content of the Smart Tag.
			 * @param string   $tag_name         Tag name of the Smart Tag.
			 * @param array    $form_data        Form data.
			 * @param string   $fields           List of fields.
			 * @param int      $entry_id         Entry ID.
			 * @param SmartTag $smart_tag_object The smart tag object or the Generic object for those cases when class unregistered.
			 */
			$content = (string) apply_filters( // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName
				'wpforms_smart_tag_process',
				$content,
				$tag_name,
				$form_data,
				$fields,
				$entry_id,
				$smart_tag_object
			);
		}

		return $content;
	}

	/**
	 * Determine if the smart tag is registered.
	 *
	 * @since 1.6.7
	 *
	 * @param string $smart_tag_name Smart tag name.
	 *
	 * @return bool
	 */
	protected function has_smart_tag( $smart_tag_name ) {

		return array_key_exists( $smart_tag_name, $this->get_smart_tags() );
	}

	/**
	 * Get a smart tag class name.
	 *
	 * @since 1.6.7
	 *
	 * @param string $smart_tag_name Smart tag name.
	 *
	 * @return string
	 */
	protected function get_smart_tag_class_name( $smart_tag_name ) {

		if ( ! $this->has_smart_tag( $smart_tag_name ) ) {
			return Generic::class;
		}

		$class_name = str_replace( ' ', '', ucwords( str_replace( '_', ' ', $smart_tag_name ) ) );

		$full_class_name = '\\WPForms\\SmartTags\\SmartTag\\' . $class_name;

		if ( class_exists( $full_class_name ) ) {
			return $full_class_name;
		}

		/**
		 * Modify a smart tag class name that describes the smart tag logic.
		 *
		 * @since 1.6.7
		 *
		 * @param string $class_name     The value.
		 * @param string $smart_tag_name Smart tag name.
		 */
		$full_class_name = apply_filters( 'wpforms_smarttags_get_smart_tag_class_name', '', $smart_tag_name ); // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName

		return class_exists( $full_class_name ) ? $full_class_name : Generic::class;
	}

	/**
	 * Retrieve the builder's special tags.
	 *
	 * @since 1.6.7
	 *
	 * @return array
	 */
	protected function get_replacement_builder_tags() {

		return [
			'date'      => 'date format="m/d/Y"',
			'query_var' => 'query_var key=""',
			'user_meta' => 'user_meta key=""',
		];
	}

	/**
	 * Hide smart tags in the builder.
	 *
	 * @since 1.6.7
	 *
	 * @return array
	 */
	protected function get_hidden_builder_tags() {

		return [
			'field_id',
			'field_html_id',
			'field_value_id',
		];
	}

	/**
	 * Builder tags.
	 *
	 * @since 1.6.7
	 *
	 * @return array
	 */
	public function builder() {

		$smart_tags       = $this->get_smart_tags();
		$replacement_tags = $this->get_replacement_builder_tags();
		$hidden_tags      = $this->get_hidden_builder_tags();

		foreach ( $replacement_tags as $tag => $replacement_tag ) {
			$smart_tags = wpforms_array_insert( $smart_tags, [ $replacement_tag => $smart_tags[ $tag ] ], $tag );

			unset( $smart_tags[ $tag ] );
		}

		foreach ( $hidden_tags as $hidden_tag ) {
			unset( $smart_tags[ $hidden_tag ] );
		}

		return $smart_tags;
	}

	/**
	 * Replace a found smart tag with the final value.
	 *
	 * @since 1.6.7
	 * @since 2.0.2.1 Added the $context parameter.
	 *
	 * @param string $tag     The tag.
	 * @param string $value   The value.
	 * @param string $content Content.
	 * @param string $context Context.
	 *
	 * @return string
	 */
	private function replace( $tag, $value, $content, string $context = '' ) {

		$value = strip_shortcodes( $value );

		if ( ! in_array( $context, $this->get_markup_contexts(), true ) ) {
			return str_replace( $tag, $value, $content );
		}

		// One pass turns the escaped `[[tag]]` form into `[tag]` rather than removing it, so the
		// contexts whose output do_shortcode() re-scans need the leftover delimiters made inert.
		$value = wpforms_encode_shortcode_delimiters( $value );

		return $this->replace_in_markup( (string) $tag, (string) $value, (string) $content );
	}

	/**
	 * Get the contexts whose smart tag values are printed as markup.
	 *
	 * @since 2.0.2.1
	 *
	 * @return string[]
	 */
	private function get_markup_contexts(): array {

		if ( $this->markup_contexts !== null ) {
			return $this->markup_contexts;
		}

		/**
		 * Filter the contexts whose smart tag values are printed as markup.
		 *
		 * An addon that prints a smart tag value into markup of its own adds its context here,
		 * so the value is escaped for the attribute it may land in.
		 *
		 * @since 2.0.2.1
		 *
		 * @param string[] $contexts Context names.
		 */
		$contexts = (array) apply_filters( 'wpforms_smart_tags_get_markup_contexts', self::MARKUP_CONTEXTS ); // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName

		// Dropping a core context here would silently stop escaping it, so the list is added back.
		$this->markup_contexts = array_unique( array_merge( self::MARKUP_CONTEXTS, $contexts ) );

		return $this->markup_contexts;
	}

	/**
	 * Replace a smart tag in content that is printed as markup.
	 *
	 * A tag value is escaped only where the tag sits inside an HTML tag or inside a script element,
	 * so a value carrying a quote cannot close an attribute or a string literal the form author
	 * opened. Text positions keep the value untouched, which is what lets the tags that emit markup
	 * on purpose keep working.
	 *
	 * @since 2.0.2.1
	 * @since 2.0.2.2 Substitutes inside the attributes the browser parses again, and inside a script element.
	 *
	 * @param string $tag     The tag.
	 * @param string $value   The value.
	 * @param string $content Content.
	 *
	 * @return string
	 */
	private function replace_in_markup( string $tag, string $value, string $content ): string {

		$tag_length = strlen( $tag );

		if ( $tag_length === 0 ) {
			return $content;
		}

		$scan = $this->get_attribute_positions( $content, $tag );

		if ( ! $scan['in_tag'] && ! $scan['script_text'] ) {
			return str_replace( $tag, $value, $content );
		}

		// One form of the value per kind of position, built once for all the tag's occurrences.
		$attribute = $this->escape_for_attribute( $value );
		$values    = [
			'text'                => $value,
			'attribute'           => $attribute,
			'name'                => str_replace( '=', '&#61;', $attribute ),
			'script'              => $scan['script_spans'] ? $this->escape_for_script_attribute( $value, false ) : '',
			'script_literal'      => $scan['script_spans'] ? $this->escape_for_script_attribute( $value, true ) : '',
			'script_text'         => $scan['script_text'] ? $this->escape_for_script( $value, false ) : '',
			'script_text_literal' => $scan['script_text'] ? $this->escape_for_script( $value, true ) : '',
		];
		$result    = '';
		$offset    = 0;

		while ( true ) {
			$position = strpos( $content, $tag, $offset );

			if ( $position === false ) {
				break;
			}

			$span = isset( $scan['in_tag'][ $position ] ) ? $this->find_span( $scan['whole_spans'], $position ) : null;

			if ( $span !== null ) {
				$result .= substr( $content, $offset, $span[0] - $offset );
				$result .= $this->sanitize_whole_value( $span[2], substr( $content, $span[0], $span[1] ), $tag, $values );
				$offset  = $span[0] + $span[1];

				continue;
			}

			$result .= substr( $content, $offset, $position - $offset );
			$result .= $this->get_replacement( $scan, $position, $values, $content );
			$offset  = $position + $tag_length;
		}

		return $result . substr( $content, $offset );
	}

	/**
	 * Pick the form of the value that is inert at the position the tag sits in.
	 *
	 * @since 2.0.2.2
	 *
	 * @param array  $scan     Positions and spans collected for the content.
	 * @param int    $position Position of the tag.
	 * @param array  $values   The value as text, and made inert for an attribute value, an attribute
	 *                         name, and for an event handler and a script element inside and outside
	 *                         a string literal, under those keys.
	 * @param string $content  Content.
	 *
	 * @return string
	 */
	private function get_replacement( array $scan, int $position, array $values, string $content ): string {

		if ( isset( $scan['script_text'][ $position ] ) ) {
			$code = substr( $content, $scan['script_text'][ $position ], $position - $scan['script_text'][ $position ] );

			return $this->is_inside_script_literal( $code, false ) ? $values['script_text'] : $values['script_text_literal'];
		}

		if ( ! isset( $scan['in_tag'][ $position ] ) ) {
			return $values['text'];
		}

		$span = $this->find_span( $scan['script_spans'], $position );

		if ( $span !== null ) {
			$code = substr( $content, $span[0], $position - $span[0] );

			return $this->is_inside_script_literal( $code, true ) ? $values['script'] : $values['script_literal'];
		}

		// Outside every attribute value the tag stands where an attribute name goes. A reference
		// is not decoded there, so `=` written as one cannot give the value an attribute of its own.
		return $this->find_span( $scan['value_spans'], $position ) !== null ? $values['attribute'] : $values['name'];
	}

	/**
	 * Find the attribute value a position falls in.
	 *
	 * @since 2.0.2.1
	 * @since 2.0.2.2 Renamed from `find_uri_span()`, since every kind of value span uses it.
	 *
	 * @param array $spans    Value spans as offset and length pairs, with the kind as a third element where the caller stores one.
	 * @param int   $position Position of the tag.
	 *
	 * @return array|null
	 */
	private function find_span( array $spans, int $position ): ?array {

		foreach ( $spans as $span ) {
			if ( $position >= $span[0] && $position < $span[0] + $span[1] ) {
				return $span;
			}
		}

		return null;
	}

	/**
	 * Substitute a smart tag inside an attribute value the browser reads as a whole, and make the
	 * assembled value inert.
	 *
	 * A URI is decoded and resolved as one string, so a scheme can be split across two tags that
	 * are each harmless alone; only the finished value shows it. A document attribute holds a
	 * whole HTML document: it is decoded once, the tag is substituted inside it with the same
	 * positional escaping as outside, and the result is encoded once more for the attribute. The
	 * tags the author wrote that still wait for their own pass are put back exactly as written, so
	 * their pass still finds them; a tag-like string carried in by the value stays encoded text.
	 *
	 * @since 2.0.2.1
	 * @since 2.0.2.2 Renamed from `sanitize_uri_value()`; handles document attributes too.
	 *
	 * @param string $kind   Kind of the value, `uri`, `uri_list` or `document`.
	 * @param string $span   The attribute value.
	 * @param string $tag    The tag.
	 * @param array  $values The value as text and made inert for an attribute, under those keys.
	 *
	 * @return string
	 */
	private function sanitize_whole_value( string $kind, string $span, string $tag, array $values ): string {

		if ( $kind === 'uri' ) {
			return wp_kses_bad_protocol( str_replace( $tag, $values['attribute'], $span ), wp_allowed_protocols() );
		}

		if ( $kind === 'uri_list' ) {
			// The browser splits the list after decoding it, so `&#59;` separates items too. The value
			// is rewritten only when an item loses its scheme, to keep the author's spelling otherwise.
			$assembled = str_replace( $tag, $values['attribute'], $span );
			$items     = explode( ';', html_entity_decode( $assembled, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$safe      = [];

			foreach ( $items as $item ) {
				$safe[] = wp_kses_bad_protocol( $item, wp_allowed_protocols() );
			}

			return $safe === $items ? $assembled : $this->encode_attribute_whitespace( htmlspecialchars( implode( ';', $safe ), ENT_QUOTES, 'UTF-8' ) );
		}

		// The other tags are masked in the author's own spelling before anything is decoded, so they
		// come back exactly as written and their own pass still finds them, and nothing the value
		// carries can pass for one of them. The marker is grown until neither side contains it.
		$pending = array_values( array_diff( array_keys( wpforms_get_all_smart_tags( $span ) ), [ $tag ] ) );
		$marker  = 'wpformspendingtag';

		while ( strpos( $span . $values['text'], $marker ) !== false ) {
			$marker .= 'x';
		}

		foreach ( $pending as $index => $smart_tag ) {
			$span = str_replace( $smart_tag, "{$marker}_{$index}_", $span );
		}

		// The author encodes the inner markup for the attribute, so the tag reads decoded in there too.
		$decoded  = html_entity_decode( $span, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$tag      = html_entity_decode( $tag, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$document = $this->replace_in_markup( $tag, $values['text'], $decoded );
		$encoded  = $this->encode_attribute_whitespace( htmlspecialchars( $document, ENT_QUOTES, 'UTF-8' ) );

		foreach ( $pending as $index => $smart_tag ) {
			$encoded = str_replace( "{$marker}_{$index}_", $smart_tag, $encoded );
		}

		return $encoded;
	}

	/**
	 * Make a smart tag value inert inside an HTML attribute.
	 *
	 * @since 2.0.2.1
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private function escape_for_attribute( string $value ): string {

		return $this->encode_attribute_whitespace( esc_attr( $value ) );
	}

	/**
	 * Make a smart tag value inert inside an event handler attribute.
	 *
	 * The browser decodes the attribute once and then compiles the result as JavaScript, so an
	 * entity is of no help: `&#039;` comes back as a quote and ends the string literal the author
	 * put the tag in. The value is written as JavaScript instead, which the attribute decoding
	 * leaves alone.
	 *
	 * @since 2.0.2.2
	 *
	 * @param string $value      The value.
	 * @param bool   $as_literal Whether to write the value as a complete literal.
	 *
	 * @return string
	 */
	private function escape_for_script_attribute( string $value, bool $as_literal ): string {

		return esc_attr( $this->escape_for_script( $value, $as_literal ) );
	}

	/**
	 * Make a smart tag value inert inside JavaScript.
	 *
	 * @since 2.0.2.2
	 *
	 * @param string $value      The value.
	 * @param bool   $as_literal Whether to write the value as a complete literal.
	 *
	 * @return string
	 */
	private function escape_for_script( string $value, bool $as_literal ): string {

		$text = wp_specialchars_decode( $value, ENT_QUOTES );

		// A leading zero would read as an octal literal, so such a value is a string like any other.
		if ( $as_literal && preg_match( '/^-?(0|[1-9]\d*)(\.\d+)?$/', $text ) === 1 ) {
			return $text;
		}

		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( $chars === false ) {
			return '';
		}

		$body = '';

		foreach ( $chars as $char ) {
			if ( preg_match( '/^[A-Za-z0-9_]$/', $char ) === 1 ) {
				$body .= $char;

				continue;
			}

			// A character outside ASCII is one or two \uXXXX units, which wp_json_encode() already writes.
			$body .= strlen( $char ) === 1 ? sprintf( '\\u%04X', ord( $char ) ) : substr( wp_json_encode( $char ), 1, -1 );
		}

		return $as_literal ? '"' . $body . '"' : $body;
	}

	/**
	 * Tell whether the script code written before a smart tag leaves a string literal open.
	 *
	 * A comment is stepped over, since a quote inside one opens no literal. A regular expression
	 * literal is not told apart from a division, so a quote inside one misjudges the position; both
	 * forms of the value are inert either way, so a misjudgment only garbles the text. Character
	 * references are decoded for a handler attribute, since that is the code the browser compiles,
	 * and left alone in a script element, where the browser does not decode them.
	 *
	 * @since 2.0.2.2
	 *
	 * @param string $code         Script code from where it begins up to the tag.
	 * @param bool   $in_attribute Whether the code is the value of an event handler attribute.
	 *
	 * @return bool
	 */
	private function is_inside_script_literal( string $code, bool $in_attribute ): bool {

		foreach ( array_keys( wpforms_get_all_smart_tags( $code ) ) as $smart_tag ) {
			$code = str_replace( $smart_tag, str_repeat( 'x', strlen( $smart_tag ) ), $code );
		}

		if ( $in_attribute ) {
			$code = html_entity_decode( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$length = strlen( $code );
		$quote  = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $code[ $i ];

			if ( $quote !== '' ) {
				$quote = $char === $quote ? '' : $quote;
				$i    += $char === '\\' ? 1 : 0;

				continue;
			}

			if ( $char === '"' || $char === "'" || $char === '`' ) {
				$quote = $char;

				continue;
			}

			// A comment that runs up to the tag holds the tag itself, so the tag is outside every literal.
			if ( $char === '/' && preg_match( '#\G(?://[^\n]*|/\*.*?(?:\*/|$))#s', $code, $comment, 0, $i ) === 1 ) {
				$i += strlen( $comment[0] ) - 1;
			}
		}

		return $quote !== '';
	}

	/**
	 * Encode the whitespace of an attribute value.
	 *
	 * Whitespace terminates an unquoted attribute value, and a character reference is decoded
	 * only after the browser has tokenized the tag, so encoding it changes nothing visually.
	 *
	 * @since 2.0.2.2
	 *
	 * @param string $value The escaped value.
	 *
	 * @return string
	 */
	private function encode_attribute_whitespace( string $value ): string {

		return str_replace(
			[ ' ', "\t", "\n", "\r", "\f" ],
			[ '&#32;', '&#09;', '&#10;', '&#13;', '&#12;' ],
			$value
		);
	}

	/**
	 * Collect the positions at which a smart tag sits inside an HTML tag or inside a script element.
	 *
	 * Quote state is tracked because `>` does not close a tag inside an attribute value, and
	 * because the tag itself is usually written between quotes. Inside a script element nothing
	 * but its end tag is markup, so the scan reads through to `</script` and records the tags it
	 * passes as script code.
	 *
	 * @since 2.0.2.1
	 * @since 2.0.2.2 Collects every kind of attribute value span and the script element positions.
	 *
	 * @param string $content Content.
	 * @param string $tag     The tag.
	 *
	 * @return array {
	 *     @type array $in_tag       Positions inside an HTML tag, as keys.
	 *     @type array $script_text  Positions inside a script element, as keys, each holding the position its code starts at.
	 *     @type array $whole_spans  Offset, length and kind of every URI and document attribute value holding the tag.
	 *     @type array $script_spans Offset and length of every event handler attribute value holding the tag.
	 *     @type array $value_spans  Offset and length of every other attribute value holding the tag.
	 * }
	 */
	private function get_attribute_positions( string $content, string $tag ): array {

		$tag_length  = strlen( $tag );
		$length      = strlen( $content );
		$in_tag      = [];
		$script_text = [];
		$spans       = [
			'whole'  => [],
			'script' => [],
			'value'  => [],
		];
		$element     = -1;
		$script      = -1;
		$quote       = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char   = $content[ $i ];
			$is_tag = $char === $tag[0] && substr( $content, $i, $tag_length ) === $tag;

			if ( $script >= 0 ) {
				if ( $is_tag ) {
					$script_text[ $i ] = $script;
					$i                += $tag_length - 1;
				} elseif ( $char === '<' && preg_match( self::SCRIPT_END_PATTERN, substr( $content, $i, 9 ) ) === 1 ) {
					$element = $i;
					$script  = -1;
				}

				continue;
			}

			if ( $element < 0 ) {
				// A bare `<` in prose opens no tag, so `5 < 6 {page_title}` stays a text position.
				$element = $char === '<' && preg_match( '#^</?[a-zA-Z]#', substr( $content, $i, 3 ) ) === 1 ? $i : -1;

				continue;
			}

			if ( $is_tag ) {
				$in_tag[ $i ] = true;
				$i           += $tag_length - 1;

				continue;
			}

			if ( $quote !== '' ) {
				$quote = $char === $quote ? '' : $quote;

				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$quote = $char;

				continue;
			}

			if ( $char === '>' ) {
				foreach ( $this->get_attribute_value_spans( $content, $element, $i, $in_tag ) as $kind => $kind_spans ) {
					$spans[ $kind ] = array_merge( $spans[ $kind ], $kind_spans );
				}

				$script  = preg_match( self::SCRIPT_START_PATTERN, substr( $content, $element, 8 ) ) === 1 ? $i + 1 : -1;
				$element = -1;
			}
		}

		return [
			'in_tag'       => $in_tag,
			'script_text'  => $script_text,
			'whole_spans'  => $spans['whole'],
			'script_spans' => $spans['script'],
			'value_spans'  => $spans['value'],
		];
	}

	/**
	 * Collect the attribute values of one element that hold the tag being replaced, by kind.
	 *
	 * Every smart tag in the element is masked with a same-length filler first: a tag carries
	 * quotes and spaces of its own, `{query_var key="a"}`, which would otherwise end the very
	 * attribute value it sits in and hide the rest of that value from the pattern.
	 *
	 * @since 2.0.2.1
	 * @since 2.0.2.2 Renamed from `get_uri_value_spans()`; collects every attribute value, by kind.
	 *
	 * @param string $content Content.
	 * @param int    $start   Position of the element's `<`.
	 * @param int    $end     Position of the element's `>`.
	 * @param array  $in_tag  Tag positions inside an HTML tag, as keys.
	 *
	 * @return array {
	 *     @type array $whole  Offset, length and kind of URI and document attribute values, which are rewritten as a whole.
	 *     @type array $script Offset and length pairs of event handler attribute values.
	 *     @type array $value  Offset and length pairs of every other attribute value.
	 * }
	 */
	private function get_attribute_value_spans( string $content, int $start, int $end, array $in_tag ): array {

		$spans   = [
			'whole'  => [],
			'script' => [],
			'value'  => [],
		];
		$element = substr( $content, $start, $end - $start + 1 );

		foreach ( array_keys( wpforms_get_all_smart_tags( $element ) ) as $smart_tag ) {
			$element = str_replace( $smart_tag, str_repeat( 'x', strlen( $smart_tag ) ), $element );
		}

		if ( ! preg_match_all( self::ATTRIBUTE_PATTERN, $element, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $spans;
		}

		preg_match( '#^<([a-zA-Z][^\t\n\f\r />]*)#', $element, $element_name );

		$element_name = strtolower( $element_name[1] ?? '' );

		foreach ( $matches['name'] as $index => $name ) {
			$kind = $this->get_attribute_kind( $name[0], $element_name ) ?? 'value';
			$span = $this->get_matched_value_span( $matches, $index, $start );

			if ( $span === null || ! $this->has_tag_in_span( $in_tag, $span ) ) {
				continue;
			}

			// A URI and a document are rewritten as a whole, so they share one list and carry their kind.
			$list = in_array( $kind, [ 'uri', 'uri_list', 'document' ], true ) ? 'whole' : $kind;

			$spans[ $list ][] = [ $span[0], $span[1], $kind ];
		}

		return $spans;
	}

	/**
	 * Tell whether an attribute takes a URI, a list of URIs, a whole document or a script, the kinds
	 * that need more than plain attribute escaping.
	 *
	 * @since 2.0.2.2
	 *
	 * @param string $name         Attribute name as written.
	 * @param string $element_name Lower case name of the element the attribute belongs to.
	 *
	 * @return string|null `uri`, `uri_list`, `document`, `script`, or null for any other attribute.
	 */
	private function get_attribute_kind( string $name, string $element_name ): ?string {

		$name = strtolower( $name );

		if ( in_array( $name, self::DOCUMENT_ATTRIBUTES, true ) ) {
			return 'document';
		}

		if ( in_array( $element_name, self::ANIMATION_ELEMENTS, true ) && in_array( $name, self::ANIMATION_VALUE_ATTRIBUTES, true ) ) {
			return $name === 'values' ? 'uri_list' : 'uri';
		}

		if ( strpos( $name, self::SCRIPT_ATTRIBUTE_PREFIX ) === 0 ) {
			return 'script';
		}

		$uri_attributes = array_merge( self::URI_ATTRIBUTES, wp_kses_uri_attributes() );

		return in_array( $name, $uri_attributes, true ) ? 'uri' : null;
	}

	/**
	 * Read the value span out of whichever quoting alternative the pattern matched.
	 *
	 * @since 2.0.2.1
	 *
	 * @param array $matches Pattern matches captured with their offsets.
	 * @param int   $index   Index of the attribute.
	 * @param int   $start   Position the element begins at.
	 *
	 * @return array|null
	 */
	private function get_matched_value_span( array $matches, int $index, int $start ): ?array {

		foreach ( [ 'double', 'single', 'bare' ] as $quoting ) {
			[ $matched, $offset ] = $matches[ $quoting ][ $index ];

			if ( $offset >= 0 && $matched !== '' ) {
				return [ $start + $offset, strlen( $matched ) ];
			}
		}

		return null;
	}

	/**
	 * Determine whether the tag being replaced lands in a value span.
	 *
	 * @since 2.0.2.1
	 *
	 * @param array $in_tag Tag positions inside an HTML tag, as keys.
	 * @param array $span   Offset and length of the value.
	 *
	 * @return bool
	 */
	private function has_tag_in_span( array $in_tag, array $span ): bool {

		foreach ( array_keys( $in_tag ) as $position ) {
			if ( $position >= $span[0] && $position < $span[0] + $span[1] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filter arguments passed to the async task.
	 *
	 * @since 1.9.4
	 *
	 * @param array|mixed $args Arguments passed to the async task.
	 */
	public function save_smart_tags_tasks_meta( $args ): array {

		$args    = (array) $args;
		$process = wpforms()->obj( 'process' );

		if ( ! $process || empty( $process->form_data['entry_meta'] ) ) {
			return $args;
		}

		$args['entry_meta'] = $process->form_data['entry_meta'];

		return $args;
	}

	/**
	 * Maybe add a fallback for entry meta for WPForms Action Scheduler tasks meta.
	 *
	 * @since 1.9.4
	 *
	 * @param int|mixed              $action_id Action ID.
	 * @param ActionScheduler_Action $action    Action Scheduler action object.
	 *
	 * @noinspection PhpUnusedParameterInspection
	 * @noinspection PhpMissingParamTypeInspection
	 */
	public function maybe_add_entry_meta_fallback_value( $action_id, $action ): void { // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks

		$this->action_args = $action->get_args();
		$this->fallback    = function ( $value, $var_name ) {

			if ( ! wpforms_is_empty_string( $value ) ) {
				return $value;
			}

			return $this->action_args['entry_meta'][ $var_name ] ?? $value;
		};

		add_filter( 'wpforms_smart_tags_smart_tag_get_meta_value', $this->fallback, 10, 2 );
	}

	/**
	 * Maybe remove a fallback for entry meta for WPForms Action Scheduler tasks meta.
	 *
	 * @since 1.9.4
	 */
	public function maybe_remove_entry_meta_fallback_value(): void { // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks

		if ( ! $this->fallback ) {
			return;
		}

		remove_filter( 'wpforms_smart_tags_smart_tag_get_meta_value', $this->fallback );
	}
}
