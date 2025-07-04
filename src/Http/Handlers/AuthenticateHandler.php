<?php

namespace OpenIDConnectServer\Http\Handlers;

use OAuth2\Request;
use OAuth2\Response;
use OpenIDConnectServer\Http\RequestHandler;
use OpenIDConnectServer\Http\Router;
use OpenIDConnectServer\Storage\ClientCredentialsStorage;
use OpenIDConnectServer\Storage\ConsentStorage;

class AuthenticateHandler extends RequestHandler {
	private ConsentStorage $consent_storage;
	private ClientCredentialsStorage $clients;

	public function __construct( ConsentStorage $consent_storage, ClientCredentialsStorage $clients ) {
		$this->consent_storage = $consent_storage;
		$this->clients         = $clients;
	}

	public function handle( Request $request, Response $response ): Response {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$client_id = $request->query( 'client_id' );

		$client_name = $this->clients->getClientName( $client_id );
		if ( empty( $client_name ) ) {
			$response->setStatusCode( 404 );

			return $response;
		}

		if (
		! $this->clients->clientRequiresConsent( $client_id )
		|| ! $this->consent_storage->needs_consent( get_current_user_id(), $client_id )
		) {
			$this->redirect( $request );
			// TODO: return response instead of exiting.
			exit;
		}

		$data = array(
			'user'            => wp_get_current_user(),
			'client_name'     => $client_name,
			'body_class_attr' => implode( ' ', array_diff( get_body_class(), array( 'error404' ) ) ),
			'cancel_url'      => $this->get_cancel_url( $request ),
			'form_url'        => Router::make_rest_url( 'authorize' ),
			'form_fields'     => $request->getAllQueryParameters(),
		);

		$cap = current_user_can( OIDC_DEFAULT_MINIMAL_CAPABILITY );

		$has_permission = apply_filters( 'oidc_minimal_capability', $cap, $data );

		if ( ! $has_permission ) {
			login_header( 'OIDC Connect', null, new \WP_Error( 'OIDC_NO_PERMISSION', __( "You don't have permission to use OpenID Connect.", 'openid-connect-server' ) ) );
			$this->render_no_permission_screen( $data );
		} else {
			login_header( 'OIDC Connect' );
			$this->render_consent_screen( $data );
		}

		login_footer();

		return $response;
	}

	private function render_no_permission_screen( $data ) {
		?>
		<div id="openid-connect-authenticate">
			<div id="openid-connect-authenticate-form-container" class="login">
				<form class="wp-core-ui">
					<h2>
						<?php
						echo esc_html(
							sprintf(
							// translators: %s is a username.
								__( 'Hi %s!', 'openid-connect-server' ),
								$data['user']->user_nicename
							)
						);
						?>
					</h2>
					<br/>
					<p><?php esc_html_e( "You don't have permission to use OpenID Connect.", 'openid-connect-server' ); ?></p>
					<br/>
					<p><?php esc_html_e( 'Contact your administrator for more details.', 'openid-connect-server' ); ?></p>
					<br/>
					<p class="submit">
						<a class="button button-large" href="<?php echo esc_url( $data['cancel_url'] ); ?>" target="_top">
							<?php esc_html_e( 'Cancel', 'openid-connect-server' ); ?>
						</a>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_consent_screen( $data ) {
		?>
		<div id="openid-connect-authenticate">
			<div id="openid-connect-authenticate-form-container" class="login">
				<form method="post" action="<?php echo esc_url( $data['form_url'] ); ?>" class="wp-core-ui">
					<h2>
						<?php
						echo esc_html(
							sprintf(
							// translators: %s is a username.
								__( 'Hi %s!', 'openid-connect-server' ),
								$data['user']->user_nicename
							)
						);
						?>
					</h2>
					<br/>
					<p>
						<label>
							<?php
							echo wp_kses(
								sprintf(
								// translators: %1$s is the site name, %2$s is the username.
									__( 'Do you want to log in to <em>%1$s</em> with your <em>%2$s</em> account?', 'openid-connect-server' ),
									$data['client_name'],
									get_bloginfo( 'name' )
								),
								array(
									'em' => array(),
								)
							);
							?>
						</label>
					</p>
					<br/>
					<?php wp_nonce_field( 'wp_rest' ); /* The nonce will give the REST call the userdata. */ ?>
					<?php foreach ( $data['form_fields'] as $key => $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"/>
					<?php endforeach; ?>
					<p class="submit">
						<input type="submit" name="authorize" class="button button-primary button-large" value="<?php esc_attr_e( 'Authorize', 'openid-connect-server' ); ?>"/>
						<a href="<?php echo esc_url( $data['cancel_url'] ); ?>" target="_top">
							<?php esc_html_e( 'Cancel', 'openid-connect-server' ); ?>
						</a>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	private function redirect( Request $request ) {
		// Rebuild request with all parameters and send to authorize endpoint.
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array( '_wpnonce' => wp_create_nonce( 'wp_rest' ) ),
					$request->getAllQueryParameters()
				),
				Router::make_rest_url( 'authorize' )
			)
		);
	}

	private function get_cancel_url( Request $request ) {
		return add_query_arg(
			array(
				'error'             => 'access_denied',
				'error_description' => 'Access denied! Permission not granted.',
				'state'             => $request->query( 'state' ),
			),
			$request->query( 'redirect_uri' ),
		);
	}
}
