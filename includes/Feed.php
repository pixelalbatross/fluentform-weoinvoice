<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Models\Form;
use FluentForm\Framework\Helpers\ArrayHelper;

/**
 * The WeoInvoice Fluent Forms integration feed.
 *
 * Provides the global-settings tab (api_key, VAT M-code, default country) and the
 * per-form field-mapping UI (form fields -> WeoInvoice client). PaymentTrigger reads these
 * saved feeds on `fluentform/after_payment_status_change` and emits the document.
 */
class Feed extends IntegrationManagerController {

	/**
	 * Option key holding the global settings.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'os_ff_weoinvoice_settings';

	/**
	 * Integration key.
	 *
	 * @var string
	 */
	public const INTEGRATION_KEY = 'os_ff_weoinvoice';

	/**
	 * Per-form feed meta key.
	 *
	 * @var string
	 */
	public const SETTINGS_KEY = 'os_ff_weoinvoice_feed';

	/**
	 * Constructor.
	 *
	 * @param mixed $app Fluent Forms application instance (null resolves the singleton).
	 */
	public function __construct( $app = null ) {
		parent::__construct(
			$app,
			'WeoInvoice',
			self::INTEGRATION_KEY,
			self::OPTION_KEY,
			self::SETTINGS_KEY,
			90
		);

		$this->category    = 'payment';
		$this->description = __( 'Issue a WeoInvoice fiscal document when a payment completes.', 'fluentform-weoinvoice' );
	}

	/**
	 * Global-settings fields shown on the Fluent Forms integration settings tab.
	 *
	 * @param  array<string,mixed> $fields Incoming fields.
	 * @return array<string,mixed>
	 */
	public function getGlobalFields( $fields ): array {
		return [
			'logo'             => $this->logo,
			'menu_title'       => __( 'WeoInvoice Settings', 'fluentform-weoinvoice' ),
			'menu_description' => __( 'Provide your WeoInvoice API key. The document type (invoice, receipt, donation receipt) is decided by your WeoInvoice account/plan — this plugin cannot select it.', 'fluentform-weoinvoice' ),
			'valid_message'    => __( 'Your WeoInvoice API key is saved.', 'fluentform-weoinvoice' ),
			'invalid_message'  => __( 'Your WeoInvoice API key is not set.', 'fluentform-weoinvoice' ),
			'save_button_text' => __( 'Save Settings', 'fluentform-weoinvoice' ),
			'fields'           => [
				'api_key'   => [
					'type'        => 'text',
					'label'       => __( 'API Key', 'fluentform-weoinvoice' ),
					'label_tips'  => __( 'Your WeoInvoice API key.', 'fluentform-weoinvoice' ),
					'placeholder' => __( 'WeoInvoice API Key', 'fluentform-weoinvoice' ),
				],
				'taxreason' => [
					'type'        => 'text',
					'label'       => __( 'VAT Exemption Code', 'fluentform-weoinvoice' ),
					'label_tips'  => __( 'The Portuguese VAT exemption M-code applied to each line (default M07).', 'fluentform-weoinvoice' ),
					'placeholder' => 'M07',
				],
				'country'   => [
					'type'        => 'text',
					'label'       => __( 'Default Country', 'fluentform-weoinvoice' ),
					'label_tips'  => __( 'ISO country code used when the form has no country field (default PT).', 'fluentform-weoinvoice' ),
					'placeholder' => 'PT',
				],
			],
			'hide_on_valid'    => false,
			'discard_settings' => [
				'section_description' => __( 'Your WeoInvoice integration is active.', 'fluentform-weoinvoice' ),
				'button_text'         => __( 'Disconnect', 'fluentform-weoinvoice' ),
				'data'                => [ 'api_key' => '' ],
				'show_verify'         => false,
			],
		];
	}

	/**
	 * Reads the stored global settings, merged with defaults.
	 *
	 * @param  array<string,mixed> $settings Incoming settings.
	 * @return array<string,mixed>
	 */
	public function getGlobalSettings( $settings ): array {
		return self::settings();
	}

	/**
	 * Persists the global settings.
	 *
	 * @param  array<string,mixed> $settings Submitted settings.
	 * @return void
	 */
	public function saveGlobalSettings( $settings ): void {
		$api_key   = sanitize_text_field( $settings['api_key'] ?? '' );
		$taxreason = sanitize_text_field( $settings['taxreason'] ?? '' );
		$country   = sanitize_text_field( $settings['country'] ?? '' );

		if ( '' === $taxreason ) {
			$taxreason = 'M07';
		}

		if ( '' === $country ) {
			$country = 'PT';
		}

		$integration_settings = [
			'api_key'   => $api_key,
			'taxreason' => $taxreason,
			'country'   => $country,
			'status'    => '' !== $api_key,
		];

		update_option( self::OPTION_KEY, $integration_settings, 'no' );

		wp_send_json_success(
			[
				'message' => __( 'WeoInvoice settings saved.', 'fluentform-weoinvoice' ),
				'status'  => '' !== $api_key,
			],
			200
		);
	}

	/**
	 * Adds the WeoInvoice integration to a form's available integrations list.
	 *
	 * @param  array<string,mixed> $integrations Incoming integrations.
	 * @param  int                 $form_id      Form id.
	 * @return array<string,mixed>
	 */
	public function pushIntegration( $integrations, $form_id ): array {
		$integrations[ self::INTEGRATION_KEY ] = [
			'title'                 => __( 'WeoInvoice', 'fluentform-weoinvoice' ),
			'logo'                  => $this->logo,
			'is_active'             => $this->isConfigured(),
			'configure_title'       => __( 'Configuration required!', 'fluentform-weoinvoice' ),
			'global_configure_url'  => admin_url( 'admin.php?page=fluent_forms_settings#general-' . self::INTEGRATION_KEY . '-settings' ),
			'configure_message'     => __( 'WeoInvoice is not configured yet. Please add your API key first.', 'fluentform-weoinvoice' ),
			'configure_button_text' => __( 'Set WeoInvoice API Key', 'fluentform-weoinvoice' ),
		];

		return $integrations;
	}

	/**
	 * Default per-form feed values.
	 *
	 * @param  array<string,mixed> $settings Incoming settings.
	 * @param  int                 $form_id  Form id.
	 * @return array<string,mixed>
	 */
	public function getIntegrationDefaults( $settings, $form_id ): array {
		return [
			'name'           => '',
			'name_field'     => '',
			'entity_field'   => '',
			'email_field'    => '',
			'nif_field'      => '',
			'address_field'  => '',
			'postcode_field' => '',
			'city_field'     => '',
			'country_field'  => '',
			'item_label'     => '',
			'enabled'        => true,
			'conditionals'   => [
				'conditions' => [],
				'status'     => false,
				'type'       => 'all',
			],
		];
	}

	/**
	 * Per-form feed settings fields (the field-mapping UI).
	 *
	 * @param  array<string,mixed> $settings Incoming settings.
	 * @param  int                 $form_id  Form id.
	 * @return array<string,mixed>
	 */
	public function getSettingsFields( $settings, $form_id ): array {
		$field_options = $this->field_options( (int) $form_id );

		$map = static function ( string $key, string $label ) use ( $field_options ): array {
			return [
				'key'       => $key,
				'label'     => $label,
				'component' => 'select',
				'options'   => $field_options,
			];
		};

		return [
			'fields'              => [
				[
					'key'         => 'name',
					'label'       => __( 'Feed Name', 'fluentform-weoinvoice' ),
					'required'    => true,
					'placeholder' => __( 'Feed Name', 'fluentform-weoinvoice' ),
					'component'   => 'text',
				],
				$map( 'name_field', __( 'Client Name Field', 'fluentform-weoinvoice' ) ),
				$map( 'entity_field', __( 'Company / Entity Field', 'fluentform-weoinvoice' ) ),
				$map( 'email_field', __( 'Email Field', 'fluentform-weoinvoice' ) ),
				$map( 'nif_field', __( 'NIF (Tax Number) Field', 'fluentform-weoinvoice' ) ),
				$map( 'address_field', __( 'Address Field', 'fluentform-weoinvoice' ) ),
				$map( 'postcode_field', __( 'Postcode Field', 'fluentform-weoinvoice' ) ),
				$map( 'city_field', __( 'City Field', 'fluentform-weoinvoice' ) ),
				$map( 'country_field', __( 'Country Field', 'fluentform-weoinvoice' ) ),
				[
					'key'         => 'item_label',
					'label'       => __( 'Line Item Label', 'fluentform-weoinvoice' ),
					'required'    => true,
					'placeholder' => __( 'e.g. Donativo', 'fluentform-weoinvoice' ),
					'component'   => 'text',
				],
				[
					'key'            => 'enabled',
					'label'          => __( 'Status', 'fluentform-weoinvoice' ),
					'component'      => 'checkbox-single',
					'checkbox_label' => __( 'Enable this feed', 'fluentform-weoinvoice' ),
				],
				[
					'key'       => 'conditionals',
					'label'     => __( 'Conditional Logics', 'fluentform-weoinvoice' ),
					'tips'      => __( 'Issue the invoice conditionally based on submission values.', 'fluentform-weoinvoice' ),
					'component' => 'conditional_block',
				],
			],
			'button_require_list' => false,
			'integration_title'   => $this->title,
		];
	}

	/**
	 * No remote list to fetch.
	 *
	 * @param  mixed $merge_list Unused.
	 * @param  mixed $list_id    Unused.
	 * @param  int   $form_id    Unused.
	 * @return array<mixed>
	 */
	public function getMergeFields( $merge_list, $list_id, $form_id ): array {
		return [];
	}

	/**
	 * Not used — emission is triggered on payment status change, not on submission.
	 *
	 * @param  array<string,mixed> $feed      Feed.
	 * @param  array<string,mixed> $form_data Form data.
	 * @param  object              $entry     Entry.
	 * @param  object              $form      Form.
	 * @return void
	 */
	public function notify( $feed, $form_data, $entry, $form ): void {}

	/**
	 * Whether the global API key is set.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return '' !== self::global_api_key();
	}

	/**
	 * The configured WeoInvoice API key.
	 *
	 * @return string
	 */
	public static function global_api_key(): string {
		return (string) self::settings()['api_key'];
	}

	/**
	 * The configured VAT exemption M-code.
	 *
	 * @return string
	 */
	public static function global_taxreason(): string {
		$taxreason = (string) self::settings()['taxreason'];

		if ( '' === $taxreason ) {
			$taxreason = 'M07';
		}

		return $taxreason;
	}

	/**
	 * The default country ISO code for clients without a mapped country field.
	 *
	 * @return string
	 */
	public static function global_country(): string {
		$country = (string) self::settings()['country'];

		if ( '' === $country ) {
			$country = 'PT';
		}

		return $country;
	}

	/**
	 * Maps a saved feed + a submission's form data to the emitter's client + lines shape.
	 *
	 * The document has one line: the feed's item label at the charged amount.
	 *
	 * @param  array<string,mixed> $feed_settings The saved feed settings (field-name mappings).
	 * @param  array<string,mixed> $form_data     The submission response data, keyed by field name.
	 * @param  string              $amount        The charged amount, as a dot-decimal string.
	 * @return array{client:array<string,string>,lines:array<int,array<string,mixed>>}
	 */
	public static function map_submission( array $feed_settings, array $form_data, string $amount ): array {
		$pull = static function ( string $mapping_key ) use ( $feed_settings, $form_data ): string {
			$field = (string) ( $feed_settings[ $mapping_key ] ?? '' );

			return self::pull_value( $form_data, $field );
		};

		$client = [
			'name'     => $pull( 'name_field' ),
			'entity'   => $pull( 'entity_field' ),
			'email'    => $pull( 'email_field' ),
			'nif'      => $pull( 'nif_field' ),
			'address'  => $pull( 'address_field' ),
			'postcode' => $pull( 'postcode_field' ),
			'city'     => $pull( 'city_field' ),
			'country'  => $pull( 'country_field' ),
		];

		$lines = [
			[
				'item'     => (string) ( $feed_settings['item_label'] ?? '' ),
				'quantity' => 1,
				'price'    => $amount,
			],
		];

		return [
			'client' => $client,
			'lines'  => $lines,
		];
	}

	/**
	 * The stored global settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	private static function settings(): array {
		$stored = get_option( self::OPTION_KEY );
		$stored = is_array( $stored ) ? $stored : [];

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Default global-settings values.
	 *
	 * @return array<string,mixed>
	 */
	private static function defaults(): array {
		return [
			'api_key'   => '',
			'taxreason' => 'M07',
			'country'   => 'PT',
			'status'    => false,
		];
	}

	/**
	 * Reads a mapped field from the submission data, supporting Fluent Forms
	 * dot-notation keys for compound fields (name first/last, address subfields).
	 *
	 * @param  array<string,mixed> $form_data The submission data.
	 * @param  string              $field     The mapped field key (may be dotted).
	 * @return string
	 */
	private static function pull_value( array $form_data, string $field ): string {
		if ( '' === $field ) {
			return '';
		}

		if ( class_exists( ArrayHelper::class ) ) {
			$value = ArrayHelper::get( $form_data, $field );
		} else {
			$value = $form_data[ $field ] ?? '';
		}

		if ( is_array( $value ) ) {
			$scalars = array_filter( $value, static fn( $item ) => ! is_array( $item ) );

			return trim( implode( ' ', array_map( 'strval', $scalars ) ) );
		}

		return (string) $value;
	}

	/**
	 * Builds the form-field selector options for the mapping UI.
	 *
	 * @param  int $form_id Form id.
	 * @return array<string,string>
	 */
	private function field_options( int $form_id ): array {
		$options = [ '' => __( '— Select a field —', 'fluentform-weoinvoice' ) ];

		if ( ! $form_id || ! class_exists( FormFieldsParser::class ) || ! class_exists( Form::class ) ) {
			return $options;
		}

		try {
			$form = Form::find( $form_id );
			if ( ! $form ) {
				return $options;
			}

			$inputs = FormFieldsParser::getEntryInputs( $form, [ 'admin_label' ] );
			foreach ( (array) $inputs as $key => $input ) {
				$label           = is_array( $input ) ? (string) ( $input['admin_label'] ?? $key ) : (string) $key;
				$options[ $key ] = '' !== $label ? $label : (string) $key;
			}
		} catch ( \Throwable $e ) {
			return $options;
		}

		return $options;
	}
}
