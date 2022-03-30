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
					'Mailercloud Subscription stopped by conditional logic',
					$fields,
					array(
						'type'    => array( 'provider', 'conditional_logic' ),
						'parent'  => $entry_id,
						'form_id' => $form_data['id'],
					)
				);

				continue;
			}

			$body = array(
				'email'	=> $email,
				'name' => $first_name,
				'last_name'	=> $last_name,
				'list_id'	=> $list_id
			);

			/**
			 * Add a subscriber.
			 *
			 * @link https://apidoc.mailercloud.com/docs/mailercloud-api/b3A6MTA4NjcwNTA-create-contact
			 */
	
			$providers = get_option( 'wpforms_providers' );

			$api = ! empty( $providers[ $this->slug ][ $account_id ]['api'] ) ? $providers[ $this->slug ][ $account_id ]['api'] : '';

			$url = 'https://cloudapi.mailercloud.com/v1/contacts';

			// @todo Make use of wp_remote_post instead.
			$curl = curl_init($url);
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

			$headers = array(
				"Authorization: " . $api,
				"Content-Type: application/json",
			);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

			curl_setopt($curl, CURLOPT_POSTFIELDS, wp_json_encode( $body ) );

			//for debug only!
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

			$resp = curl_exec($curl);
			curl_close($curl);
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

		$lists = $this->mailercloud_lists( $data['apikey'] );

		if ( empty( $lists ) ) {
			return $this->error( 'Could not connect or fetch lists. Please make sure the API key is correct and the account has lists, or contact support.' );
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
	 * Get lists from Mailercloud.
	 *
	 * @since 1.0.0
	 * 
	 * @return mixed.
	 */
	public function mailercloud_lists( $api_key ) {

		// Ping to Mailercloud to authenticate the api.
		$url = "https://cloudapi.mailercloud.com/v1/lists/search";

		// @todo Make use of wp_remote_post instead.
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		$headers = array(
			"Authorization: " . $api_key,
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

		// The result is a collection of object, but the base class is expecting the array. So, we're casting each list into array.
		
		$lists = [];

		if ( ! empty( $result->data ) ) {

			foreach( $result->data as $list ) {
				$list = (array) $list;
				$lists[] = $list;
			}
		}

		return (array) $lists;
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
		$providers = get_option( 'wpforms_providers' );

		$key = ! empty( $providers[ $this->slug ][ $account_id ]['api'] ) ? $providers[ $this->slug ][ $account_id ]['api'] : '';

		return ! empty( $this->mailercloud_lists( $key ) ) ? $this->mailercloud_lists( $key ) : [];
	}

	/**
	 * Retrieve provider account fields.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function api_fields( $connection_id = '', $account_id = '', $list_id = '' ) {

		return array(
			array(
				'name'       => 'Email',
				'req'        => true,
				'tag'        => 'email',
				'field_type' => 'email',
			),
			array(
				'name'       => 'First Name',
				'req'        => false,
				'tag'        => 'first_name',
				'field_type' => 'text',
			),
			array(
				'name'       => 'Last Name',
				'req'        => false,
				'tag'        => 'last_name',
				'field_type' => 'text',
			),
		);
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
