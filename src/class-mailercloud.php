<?php

/**
 * Mailercloud integration.
 *
 * @since 1.0.0
 */
class Integrate_WPForms_And_Mailercloud extends WPForms_Provider {

	/**
	 * Initialize.
	 *
	 * @since 1.0.0
	 */
	public function init() {

		$this->version  = INTEGRATE_WPFORMS_AND_MAILERCLOUD_VERSION;
		$this->name     = 'Mailercloud';
		$this->slug     = 'mailercloud';
		$this->priority = 0.5;
		$this->icon     = plugins_url( 'assets/mailercloud.png', INTEGRATE_WPFORMS_AND_MAILERCLOUD_PLUGIN_FILE );
	}

	/**
	 * Process and submit entry to provider.
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields
	 * @param array $entry
	 * @param array $form_data
	 * @param int   $entry_id
	 */
	public function process_entry( $fields, $entry, $form_data, $entry_id = 0 ) {

		// Only run if this form has a connections for this provider.
		if ( empty( $form_data['providers'][ $this->slug ] ) ) {
			return;
		}

		// Fire for each connection.
		foreach ( $form_data['providers'][ $this->slug ] as $connection ) {
			$account_id      = $connection['account_id'];
			$list_id         = $connection['list_id'];
			$email_data      = explode( '.', $connection['fields']['email'] );
			$first_name_data = explode( '.', $connection['fields']['first_name'] );
			$last_name_data  = explode( '.', $connection['fields']['last_name'] );

			$email      = $fields[ $email_data[0] ]['value'];
			$first_name = $fields[ $first_name_data[0] ]['first'];
			$last_name  = $fields[ $last_name_data[0] ]['last'];

			if ( empty( $email ) ) {
				continue;
			}

			// Check for conditionals.
			$pass = $this->process_conditionals( $fields, $entry, $form_data, $connection );

			if ( ! $pass ) {
				wpforms_log(
					'Mailcloud Subscription stopped by conditional logic',
					$fields,
					array(
						'type'    => array( 'provider', 'conditional_logic' ),
						'parent'  => $entry_id,
						'form_id' => $form_data['id'],
					)
				);

				continue;
			}

			/**
			 * Add a subscriber.
			 *
			 * @link https://github.com/mailpoet/mailpoet/blob/master/mailpoet/doc/api_methods/AddSubscriber.md.
			 */
			$this->connect->addSubscriber(
				array(
					'email'      => $email,
					'first_name' => $first_name,
					'last_name'  => $last_name,
				),

				array( $connection['list_id'] )				
			);
		}
	}

	/**
	 * Authenticate with the API.
	 *
	 * @param array  $data
	 * @param string $form_id
	 *
	 * @since 1.0.0
	 *
	 * @return id
	 */
	public function api_auth( $data = array(), $form_id = '' ) {

		// Ping to Mailercloud to authenticate the api.
		$url = "https://cloudapi.mailercloud.com/v1/lists/search";

		// @todo Make use of wp_remote_post instead.
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		$headers = array(
			"Authorization: " . $data['apikey'],
			"Content-Type: application/json",
		);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		$body = '{"limit":10,"list_type":1,"page":1,"search_name":"","sort_field":"name","sort_order":"asc"}';

		curl_setopt($curl, CURLOPT_POSTFIELDS, $body);

		//for debug only!
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

		$resp = curl_exec($curl);
		curl_close($curl);

		$result = json_decode( $resp );


		if ( isset( $result->errors ) || empty( $result->data ) ) {
			return $this->error( 'API authorization failed. Please make sure the API key is correct or contact support.' );
		}

		$id                              = uniqid();
		$providers                       = get_option( 'wpforms_providers', array() );
		$providers[ $this->slug ][ $id ] = array(
			'api'       => trim( $data['apikey'] ),
			'label'     => sanitize_text_field( $data['label'] ),
			'date'      => time(),
		);
		update_option( 'wpforms_providers', $providers );

		return $id;
	}

	/**
	 * Retrieve provider account lists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id
	 * @param string $account_id
	 *
	 * @return mixed array or WP_Error object.
	 */
	public function api_lists( $connection_id = '', $account_id = '' ) {

		$this->api_connect( $account_id );

		try {
			$lists = $this->api[ $account_id ]->get_lists();

			return $lists;
		} catch ( Exception $e ) {
			wpforms_log(
				'Campaign Monitor API error',
				$e->getMessage(),
				array(
					'type' => array( 'provider', 'error' ),
				)
			);

			return $this->error( 'API list error: ' . $e->getMessage() );
		}
	}

	/**
	 * Retrieve provider account list groups.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id
	 * @param string $account_id
	 * @param string $list_id
	 *
	 * @return mixed array or error object.
	 */
	public function api_groups( $connection_id = '', $account_id = '', $list_id = '' ) {

		return new WP_Error( esc_html__( 'Groups do not exist.', 'integrate-wpforms-and-mailpoet' ) );
	}

	/**
	 * Provider account authorize fields HTML.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function output_auth() {

		$providers = get_option( 'wpforms_providers' );
		$class     = ! empty( $providers[ $this->slug ] ) ? 'hidden' : '';

		$output = '<div class="wpforms-provider-account-add ' . $class . ' wpforms-connection-block">';

		$output .= '<h4>' . esc_html__( 'Add New Account', 'integrate-wpforms-and-mailpoet' ) . '</h4>';

		$output .= sprintf(
			'<input type="text" data-name="label" placeholder="%s" class="wpforms-required">',
			sprintf(
				/* translators: %s - current provider name. */
				esc_html__( '%s Account Nickname', 'integrate-wpforms-and-mailpoet' ),
				$this->name
			)
		);

		$output .= sprintf(
			'<input type="text" data-name="apikey" placeholder="%s" class="wpforms-required">',
			sprintf(
				/* translators: %s - current provider name. */
				esc_html__( '%s API Key', 'wpforms-campaign-monitor' ),
				$this->name
			)
		);

		$output .= sprintf( '<button data-provider="%s">%s</button>', esc_attr( $this->slug ), esc_html__( 'Connect', 'integrate-wpforms-and-mailpoet' ) );

		$output .= '</div>';

		return $output;
	}

	/**
	 * Provider account list options HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id
	 * @param array  $connection
	 *
	 * @return string
	 */
	public function output_options( $connection_id = '', $connection = array() ) {
		return '';
	}

	/**
	 * Form fields to add a new provider account.
	 *
	 * @since 1.0.0
	 */
	public function integrations_tab_new_form() {

		printf(
			'<input type="text" name="label" placeholder="%s">',
			sprintf(
				/* translators: %s - current provider name. */
				esc_html__( '%s Account Nickname', 'integrate-wpforms-and-mailpoet' ),
				$this->name
			)
		);

		printf(
			'<input type="text" name="apikey" placeholder="%s">',
			sprintf(
				/* translators: %s - current provider name. */
				esc_html__( '%s API Key', 'wpforms-campaign-monitor' ),
				$this->name
			)
		);
	}
}

new Integrate_WPForms_And_Mailercloud();
