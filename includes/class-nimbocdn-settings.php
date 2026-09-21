<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Settings {

	const PAGE_SLUG = 'nimbocdn';
	const NONCE     = 'nimbocdn_action';

	const MIN_REQUESTS_FOR_RATES = 20;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_after_activation' ) );
		add_action( 'admin_post_nimbocdn_recheck', array( __CLASS__, 'handle_recheck' ) );
		add_action( 'admin_post_nimbocdn_upgrade', array( __CLASS__, 'handle_upgrade' ) );
		add_action( 'admin_post_nimbocdn_manage', array( __CLASS__, 'handle_manage' ) );
		add_action( 'admin_post_nimbocdn_switch', array( __CLASS__, 'handle_switch' ) );
		add_action( 'admin_post_nimbocdn_email', array( __CLASS__, 'handle_email' ) );
		add_action( 'admin_post_nimbocdn_account', array( __CLASS__, 'handle_account' ) );
		add_action( 'admin_post_nimbocdn_toggle', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'wp_ajax_nimbocdn_recheck', array( __CLASS__, 'ajax_recheck' ) );
		add_action( 'wp_ajax_nimbocdn_panel', array( __CLASS__, 'ajax_panel' ) );
		add_action( 'wp_ajax_nimbocdn_panel_work', array( __CLASS__, 'ajax_panel_work' ) );
		add_action( 'wp_ajax_nimbocdn_refresh', array( __CLASS__, 'ajax_refresh' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dunning_notice' ) );
		add_action( 'wp_ajax_nimbocdn_dismiss_dunning', array( __CLASS__, 'ajax_dismiss_dunning' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NIMBOCDN_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	const DISMISS_META = 'nimbocdn_dunning_dismissed';

	public static function dunning_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && 'settings_page_' . self::PAGE_SLUG === $screen->id ) {
			return;
		}

		$account = Settings_Store::account();
		$user    = get_current_user_id();

		if ( empty( $account['in_dunning'] ) ) {
			if ( '' !== (string) get_user_meta( $user, self::DISMISS_META, true ) ) {
				delete_user_meta( $user, self::DISMISS_META );
			}
			return;
		}

		if ( '' !== (string) get_user_meta( $user, self::DISMISS_META, true ) ) {
			return;
		}

		$days = (int) $account['past_due'];
		?>
		<div class="notice notice-error is-dismissible nimbocdn-dunning-notice">
			<p>
				<b><?php esc_html_e( 'NimboCDN: we could not charge your card', 'nimbocdn' ); ?></b>
				<?php
				if ( $days > 0 ) {
					printf(
						' ' . /* translators: %s: number of days remaining */
						esc_html( _n( 'You have %s day to update your payment method before this site returns to the free plan.', 'You have %s days to update your payment method before this site returns to the free plan.', $days, 'nimbocdn' ) ),
						esc_html( number_format_i18n( $days ) )
					);
				} else {
					echo ' ' . esc_html__( 'Update your payment method today to keep this site on Pro.', 'nimbocdn' );
				}
				?>
				<a href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Review it now', 'nimbocdn' ); ?></a>
			</p>
		</div>
		<?php
		wp_register_script( 'nimbocdn-dunning', false, array(), VERSION, true );
		wp_enqueue_script( 'nimbocdn-dunning' );
		wp_add_inline_script(
			'nimbocdn-dunning',
			'document.addEventListener("click",function(e){'
			. 'if(!e.target.classList.contains("notice-dismiss")){return;}'
			. 'if(!e.target.closest(".nimbocdn-dunning-notice")){return;}'
			. 'var body=new FormData();'
			. 'body.append("action","nimbocdn_dismiss_dunning");'
			. 'body.append("nonce",' . wp_json_encode( wp_create_nonce( self::AJAX_NONCE ) ) . ');'
			. 'fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",credentials:"same-origin",body:body});'
			. '});'
		);
	}

	public static function ajax_dismiss_dunning() {
		self::guard_ajax();
		update_user_meta( get_current_user_id(), self::DISMISS_META, '1' );
		wp_send_json_success();
	}

	const AJAX_NONCE = 'nimbocdn_ajax';

	const REFRESH_PENDING_MS = 4000;

	const REFRESH_READ_MS = 1500;

	const CHECK_COOLDOWN = MINUTE_IN_SECONDS;

	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		add_action( 'admin_head', array( __CLASS__, 'preconnect' ) );
		wp_register_script( 'nimbocdn-panel', false, array(), VERSION, true );
		wp_enqueue_script( 'nimbocdn-panel' );
		wp_add_inline_script(
			'nimbocdn-panel',
			'window.nimbocdnPanel = ' . wp_json_encode(
				array(
					'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( self::AJAX_NONCE ),
					'pendingEvery'      => self::REFRESH_PENDING_MS,
					'readEvery'         => self::REFRESH_READ_MS,
					'checking'          => __( 'Measuring…', 'nimbocdn' ),
					/* translators: %s: seconds */
					'cooldown'          => __( 'Wait %ss', 'nimbocdn' ),
					'whoami'            => '' !== Settings_Store::cdn_host() ? 'https://' . Settings_Store::cdn_host() . '/whoami' : '',
					/* translators: 1: data center city, 2: milliseconds, for example "9 ms" */
					'netLine'           => __( 'Right now, this computer receives your images from %1$s, in %2$s.', 'nimbocdn' ),
					/* translators: 1: data center city, 2: milliseconds, for example "9 ms" */
					'netLinePaused'     => __( 'Optimization paused. This computer is answered from %1$s, %2$s away — resume and your images are delivered from there.', 'nimbocdn' ),
					/* translators: %s: data center city */
					'netLineNoMs'       => __( 'Right now, this computer receives your images from %s.', 'nimbocdn' ),
					/* translators: %s: data center city */
					'netLinePausedNoMs' => __( 'Optimization paused. This computer is answered from %s — resume and your images are delivered from there.', 'nimbocdn' ),
					'netError'          => __( "We couldn't locate this request. The network is the same either way.", 'nimbocdn' ),
					'paused'            => Settings_Store::is_paused(),
					/* translators: %s: number of milliseconds */
					'ms'                => __( '%s ms', 'nimbocdn' ),
					'check'             => _x( 'Measure now', 'button: measure again now', 'nimbocdn' ),
					'failed'            => __( 'Could not measure right now. Your images keep working; try again in a moment.', 'nimbocdn' ),
					/* translators: %s: formatted price, for example "R$ 49,90" */
					'getProPriced'      => __( 'Get Pro — %s/month', 'nimbocdn' ),
					/* translators: %s: formatted price, for example "R$ 499" */
					'getProYear'        => __( 'Get Pro — %s/year', 'nimbocdn' ),
					/* translators: %s: formatted price, for example "R$ 49,90" */
					'priceMonth'        => __( '%s/month', 'nimbocdn' ),
					/* translators: %s: formatted price, for example "R$ 499" */
					'priceYear'         => __( '%s/year', 'nimbocdn' ),
					/* translators: 1: annual price, 2: what that works out to per month, 3: total saved */
					'noteYear'          => __( '%1$s billed once a year — that is %2$s a month, and you save %3$s.', 'nimbocdn' ),
					/* translators: %s: monthly price */
					'noteMonth'         => __( '%s charged every month. Cancel whenever you want.', 'nimbocdn' ),
					/* translators: %s: formatted price, for example "R$ 499" */
					'switchYear'        => __( 'Switch to annual — %s/year', 'nimbocdn' ),
					/* translators: 1: amount charged today, 2: the credit already applied */
					'confirmYear'       => __( "Switch to the annual plan?\n\nYou pay %1\$s today, with %2\$s already credited for the days you paid for and have not used. The next charge is a year from now.", 'nimbocdn' ),
					'confirmMonth'      => __( "Switch to monthly billing?\n\nYour annual plan runs to the end of the period you already paid for, and billing becomes monthly from then on. Nothing is charged today, and there is no refund for time already paid.", 'nimbocdn' ),
					'prices'            => self::price_table(),
				)
			) . ';',
			'before'
		);
		wp_add_inline_script( 'nimbocdn-panel', self::script() );
		wp_register_style( 'nimbocdn-panel', false, array(), VERSION );
		wp_enqueue_style( 'nimbocdn-panel' );
		wp_add_inline_style( 'nimbocdn-panel', self::styles() );
	}

	public static function preconnect() {
		$host = Settings_Store::cdn_host();
		if ( '' === $host ) {
			return;
		}
		printf( '<link rel="preconnect" href="%s" crossorigin />' . "\n", esc_url( 'https://' . $host ) );
	}

	private static function guard_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
	}

	public static function ajax_recheck() {
		self::guard_ajax();
		if ( self::cooldown_left() > 0 ) {
			wp_send_json_success( self::panel_payload() );
		}
		self::run_check();
		wp_send_json_success( self::panel_payload() );
	}

	public static function ajax_refresh() {
		self::guard_ajax();

		Health::refresh_account();
		wp_send_json_success( self::panel_payload() );
	}

	private static function cooldown_left() {
		$until = (int) get_transient( 'nimbocdn_check_cooldown' );
		return max( 0, $until - time() );
	}

	private static function run_check() {
		set_transient( 'nimbocdn_check_cooldown', time() + self::CHECK_COOLDOWN, self::CHECK_COOLDOWN );
		Health::run( false, self::POLL_SLICE );
	}

	public static function ajax_panel() {
		self::guard_ajax();
		wp_send_json_success( self::panel_payload() );
	}

	public static function ajax_panel_work() {
		self::guard_ajax();
		if ( Probe::in_flight() ) {
			if ( Settings_Store::is_configured() ) {
				Probe::run( false, self::POLL_SLICE );
			} else {
				Health::run( false, self::POLL_SLICE );
			}
		}
		wp_send_json_success( self::panel_payload() );
	}

	const POLL_SLICE = 10;

	private static function panel_payload() {
		ob_start();
		$pending = self::panel();
		$html    = (string) ob_get_clean();
		ob_start();
		self::render_why( Probe::last(), Settings_Store::account() );
		$host = Settings_Store::cdn_host();
		return array(
			'html'    => $html,
			'why'     => (string) ob_get_clean(),
			'pending' => $pending,
			'whoami'  => '' !== $host ? 'https://' . $host . '/whoami' : '',
			'prices'  => self::price_table(),
		);
	}

	public static function add_page() {
		add_options_page(
			__( 'NimboCDN', 'nimbocdn' ),
			__( 'NimboCDN', 'nimbocdn' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function redirect_after_activation() {
		$user = get_transient( 'nimbocdn_activated' );
		if ( false === $user || get_current_user_id() !== (int) $user ) {
			return;
		}
		delete_transient( 'nimbocdn_activated' );

		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Activation::register();

		wp_safe_redirect( self::page_url() );
		exit;
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nimbocdn' ), 403 );
		}
		check_admin_referer( self::NONCE );
	}

	private static function is_allowed_redirect_host( $host ) {
		$control = wp_parse_url( Api::base(), PHP_URL_HOST );
		if ( is_string( $control ) && strtolower( $control ) === $host ) {
			return true;
		}
		if ( 'stripe.com' === $host || ( strlen( $host ) > 11 && '.stripe.com' === substr( $host, -11 ) ) ) {
			return true;
		}
		$extra = apply_filters( 'nimbocdn_allowed_redirect_hosts', array() );
		return is_array( $extra ) && in_array( $host, array_map( 'strtolower', array_filter( $extra, 'is_string' ) ), true );
	}

	private static function redirect_external( $url, $notice ) {
		$url  = esc_url_raw( (string) $url, array( 'https' ) );
		$host = '' !== $url ? wp_parse_url( $url, PHP_URL_HOST ) : null;
		if ( ! is_string( $host ) || '' === $host || ! self::is_allowed_redirect_host( strtolower( $host ) ) ) {
			self::back( $notice );
		}
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				return $hosts;
			}
		);
		wp_safe_redirect( $url );
		exit;
	}

	private static function back( $notice = '' ) {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $notice ) {
			$args['nimbocdn_notice'] = $notice;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	private static function page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'nimbocdn' ) . '</a>' );
		return $links;
	}

	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( NIMBOCDN_FILE ) === $file ) {
			$meta[] = '<a href="' . esc_url( self::support_mailto() ) . '">' . esc_html__( 'Support', 'nimbocdn' ) . '</a>';
		}
		return $meta;
	}

	private static function support_mailto() {
		$subject = sprintf(
			/* translators: %s: this site's domain */
			__( 'A question about the NimboCDN plugin on %s (WordPress)', 'nimbocdn' ),
			(string) wp_parse_url( home_url(), PHP_URL_HOST )
		);
		$body = home_url() . ' · NimboCDN ' . VERSION . ' · WordPress ' . get_bloginfo( 'version' );
		return 'mailto:hello@nimbocdn.net?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $body );
	}

	public static function handle_recheck() {
		self::guard();
		if ( self::cooldown_left() > 0 ) {
			self::back( 'cooldown' );
		}
		self::run_check();
		self::back( 'rechecked' );
	}

	public static function handle_upgrade() {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified capability and nonce two lines above.
		$interval = isset( $_POST['interval'] ) && 'year' === sanitize_key( wp_unslash( $_POST['interval'] ) ) ? 'year' : 'month';
		$reason   = '';
		$url      = Api::checkout_url( self::page_url(), $interval, $reason );
		if ( null === $url ) {
			self::back( 'unverified' === $reason ? 'unverified' : 'checkout_failed' );
		}
		self::redirect_external( $url, 'checkout_failed' );
	}

	public static function handle_switch() {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified capability and nonce above.
		$interval = isset( $_POST['interval'] ) && 'month' === sanitize_key( wp_unslash( $_POST['interval'] ) ) ? 'month' : 'year';

		$preview = Api::plan_preview( $interval );
		if ( ! empty( $preview['unverified'] ) ) {
			self::back( 'unverified' );
		}
		if ( $preview['no_subscription'] ) {
			self::back( $preview['manual_pro'] ? 'manual_pro' : 'no_subscription' );
		}
		if ( ! $preview['ok'] ) {
			self::back( 'switch_failed' );
		}

		$switch = Api::plan_switch( $interval, self::page_url() );
		if ( ! $switch['ok'] ) {
			self::back( 'switch_failed' );
		}

		$status = isset( $switch['data']['status'] ) ? (string) $switch['data']['status'] : '';

		if ( 'checkout' === $status && ! empty( $switch['data']['url'] ) ) {
			self::redirect_external( (string) $switch['data']['url'], 'switch_failed' );
		}

		if ( 'pending' === $status && ! empty( $switch['data']['invoiceUrl'] ) ) {
			self::redirect_external( (string) $switch['data']['invoiceUrl'], 'switch_failed' );
		}

		self::back( 'scheduled' === $status ? 'switch_scheduled' : 'switch_ok' );
	}

	public static function handle_manage() {
		self::guard();
		$portal = Api::portal_url( self::page_url() );
		if ( $portal['no_subscription'] ) {
			self::back( $portal['manual_pro'] ? 'manual_pro' : 'no_subscription' );
		}
		if ( null === $portal['url'] ) {
			self::back( 'portal_failed' );
		}
		self::redirect_external( $portal['url'], 'portal_failed' );
	}

	public static function handle_email() {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified capability and nonce two lines above.
		$raw   = isset( $_POST['nimbocdn_email'] ) ? sanitize_email( wp_unslash( $_POST['nimbocdn_email'] ) ) : '';
		$email = is_email( $raw ) ? $raw : '';

		if ( '' === $email ) {
			self::back( 'email_invalid' );
			return;
		}

		$result = Api::update_email( $email );

		if ( ! $result['ok'] ) {
			self::back( 'email_failed' );
			return;
		}

		update_option( 'nimbocdn_email', $email, false );
		self::back( $result['synced'] ? 'email_saved' : 'email_partial' );
	}

	public static function handle_account() {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified capability and nonce above.
		$raw   = isset( $_POST['nimbocdn_email'] ) ? sanitize_email( wp_unslash( $_POST['nimbocdn_email'] ) ) : '';
		$email = is_email( $raw ) ? $raw : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same as above.
		$wants   = isset( $_POST['nimbocdn_marketing'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['nimbocdn_marketing'] ) );
		$opt_out = ! $wants;

		$previous_email = (string) get_option( 'nimbocdn_email', '' );
		$prefs          = Settings_Store::marketing();

		$email_changes = '' !== $raw && $email !== $previous_email;
		$pref_changes  = $opt_out !== $prefs['opt_out'];

		if ( '' !== $raw && '' === $email ) {
			self::back( 'email_invalid' );
			return;
		}
		if ( ! $email_changes && ! $pref_changes ) {
			self::back( 'account_nochange' );
			return;
		}

		$email_ok = true;
		$sincro   = true;
		if ( $email_changes ) {
			$r        = Api::update_email( $email );
			$email_ok = $r['ok'];
			$sincro   = $r['synced'];
			if ( $email_ok ) {
				update_option( 'nimbocdn_email', $email, false );
			}
		}

		$pref_ok = true;
		if ( $pref_changes ) {
			$pref_ok = Api::update_marketing( $opt_out );
			if ( $pref_ok ) {
				Settings_Store::save_marketing(
					array(
						'opt_out' => $opt_out,
						'notice'  => true,
						'synced'  => true,
					)
				);
				Settings_Store::save_account( array( 'marketing_opt_out' => $opt_out ) );
			}
		}

		if ( $email_changes && $pref_changes && $email_ok && ! $pref_ok ) {
			self::back( 'account_solo_email' );
		} elseif ( $email_changes && $pref_changes && ! $email_ok && $pref_ok ) {
			self::back( 'account_solo_pref' );
		} elseif ( ! $email_ok ) {
			self::back( 'email_failed' );
		} elseif ( ! $pref_ok ) {
			self::back( 'marketing_failed' );
		} elseif ( $email_changes && ! $sincro ) {
			self::back( 'email_partial' );
		} elseif ( $pref_changes && ! $email_changes ) {
			self::back( $opt_out ? 'marketing_off' : 'marketing_on' );
		} else {
			self::back( 'account_saved' );
		}
	}

	public static function handle_toggle() {
		self::guard();

		$paused = Settings_Store::is_paused();
		update_option( 'nimbocdn_paused', ! $paused, false );

		Probe::run();

		self::back( $paused ? 'resumed' : 'paused' );
	}

	private static function price_table() {
		$account = Settings_Store::account();
		$raw     = isset( $account['prices'] ) && is_array( $account['prices'] ) ? $account['prices'] : array();
		$out     = array();
		foreach ( array(
			'usd' => '$',
			'brl' => 'R$',
		) as $currency => $symbol ) {
			if ( empty( $raw[ $currency ] ) || ! is_array( $raw[ $currency ] ) ) {
				continue;
			}
			$row = array();
			foreach ( array( 'month', 'year', 'save', 'monthly_equivalent' ) as $key ) {
				if ( isset( $raw[ $currency ][ $key ] ) ) {
					$row[ $key ] = self::money( $symbol, (int) $raw[ $currency ][ $key ] );
				}
			}
			if ( ! empty( $row ) ) {
				$out[ $currency ] = $row;
			}
		}
		return $out;
	}

	private static function money( $symbol, $minor ) {
		$decimals = ( 0 === $minor % 100 ) ? 0 : 2;

		$decimal   = ( '$' === $symbol && 0 === strpos( determine_locale(), 'en' ) ) ? '.' : ',';
		$formatted = number_format( $minor / 100, $decimals, $decimal, '' );

		return $symbol . ' ' . $formatted;
	}

	private static function action_button( $action, $label, $css_class = 'button', $id = '', array $fields = array() ) {
		$classes = ( false !== strpos( $css_class, 'button-link' ) ) ? $css_class : trim( 'button ' . $css_class );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nimbo-action">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php echo 'interval' === $name ? ' data-interval-field' : ''; ?> />
			<?php endforeach; ?>
			<?php wp_nonce_field( self::NONCE ); ?>
			<button type="submit" class="<?php echo esc_attr( $classes ); ?>"<?php echo '' !== $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
			</button>
		</form>
		<?php
	}

	private static function render_unverified() {
		if ( ! Settings_Store::is_configured() || Activation::is_verified() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Domain not verified yet.', 'nimbocdn' ),
			esc_html__( 'Your images are being optimized on the free plan all the same. Upgrading to Pro and managing billing stay locked until the service confirms this site is yours. It checks by itself, with nothing for you to do, and retries automatically twice a day.', 'nimbocdn' )
		);
	}

	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of an outcome code, changes nothing.
		$code = isset( $_GET['nimbocdn_notice'] ) ? sanitize_key( wp_unslash( $_GET['nimbocdn_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'rechecked'          => array( 'info', __( 'Measured. The result is below.', 'nimbocdn' ) ),
			'cooldown'           => array( 'info', __( 'Measured less than a minute ago. Visitor data takes up to a minute to arrive — try again shortly.', 'nimbocdn' ) ),
			'no_subscription'    => array( 'info', __( 'This site is on the free plan. Upgrade to manage a subscription.', 'nimbocdn' ) ),
			'manual_pro'         => array( 'info', __( 'Your Pro plan was activated directly by our team, so there is no online subscription to manage. For any change, write to hello@nimbocdn.net.', 'nimbocdn' ) ),
			'checkout_failed'    => array( 'error', __( 'Could not reach the billing service. Your images keep working; try again in a moment.', 'nimbocdn' ) ),
			'portal_failed'      => array( 'error', __( 'Could not open the subscription portal. Your images keep working; try again in a moment.', 'nimbocdn' ) ),
			'email_saved'        => array( 'success', __( 'Billing email updated.', 'nimbocdn' ) ),
			'email_invalid'      => array( 'error', __( 'That does not look like an email address.', 'nimbocdn' ) ),
			'email_failed'       => array( 'error', __( 'Could not reach the service. The email was not changed — try again in a moment.', 'nimbocdn' ) ),
			'email_partial'      => array( 'warning', __( 'Saved, but the billing provider could not be updated. Please try again so payment notices reach the new address.', 'nimbocdn' ) ),
			'account_saved'      => array( 'success', __( 'Account updated.', 'nimbocdn' ) ),
			'account_nochange'   => array( 'info', __( 'Nothing to save — neither field changed.', 'nimbocdn' ) ),
			'account_solo_email' => array( 'warning', __( 'Billing email saved, but your email preference was not. Try that one again.', 'nimbocdn' ) ),
			'account_solo_pref'  => array( 'warning', __( 'Email preference saved, but the billing email was not. Try that one again.', 'nimbocdn' ) ),
			'marketing_on'       => array( 'success', __( 'You will get occasional emails about NimboCDN.', 'nimbocdn' ) ),
			'marketing_off'      => array( 'success', __( 'No more product emails. Payment notices still arrive — they are part of the service.', 'nimbocdn' ) ),
			'marketing_failed'   => array( 'error', __( 'Could not reach the service. Your email preference was not changed — try again in a moment.', 'nimbocdn' ) ),
			'billing_ok'         => array( 'success', __( 'Payment received — welcome to Pro. Your whole library is being optimized from now on; the change can take a couple of minutes to reach every data center.', 'nimbocdn' ) ),
			'billing_failed'     => array( 'error', __( 'Checkout could not be opened. Nothing was charged; your images keep working. Try again in a moment.', 'nimbocdn' ) ),
			'switch_ok'          => array( 'success', __( 'You are on the annual plan. The days you had already paid for were credited, and the next charge is a year from today.', 'nimbocdn' ) ),
			'switch_scheduled'   => array( 'info', __( 'Noted. Your annual plan runs to the end of the period you paid for, and billing becomes monthly from then on. Nothing is charged today.', 'nimbocdn' ) ),
			'switch_failed'      => array( 'error', __( 'The plan change could not be completed. Nothing was charged and your plan is unchanged; try again in a moment.', 'nimbocdn' ) ),
			'unverified'         => array( 'info', __( 'This site has not proved it controls its domain yet, so billing stays closed. Press Measure now above; it usually takes a few seconds.', 'nimbocdn' ) ),
			'paused'             => array( 'info', __( 'Optimization paused. Your site keeps working — WordPress serves the images, exactly as it did before NimboCDN.', 'nimbocdn' ) ),
			'resumed'            => array( 'success', __( 'Optimization resumed. Your images are again delivered to each visitor in the best size and format their device supports — lighter pages, faster experience.', 'nimbocdn' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		list( $type, $text ) = $messages[ $code ];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only triggers a read of the account state; it changes nothing.
		if ( isset( $_GET['nimbocdn_notice'] ) && in_array( sanitize_key( wp_unslash( $_GET['nimbocdn_notice'] ) ), array( 'billing_ok', 'switch_ok', 'switch_scheduled' ), true ) ) {
			Health::refresh_account();
		}

		?>
		<div class="wrap nimbocdn">
						<div class="nimbo-brand">
				<?php self::brand_mark(); ?>
				<h1><?php esc_html_e( 'NimboCDN', 'nimbocdn' ); ?></h1>
								<a class="nimbo-brand-home" href="<?php echo esc_url( self::site_url() ); ?>"
					target="_blank" rel="noopener"
					aria-label="<?php esc_attr_e( 'Open nimbocdn.net in a new tab', 'nimbocdn' ); ?>"
					title="<?php esc_attr_e( 'Open nimbocdn.net in a new tab', 'nimbocdn' ); ?>">
					<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5.5h4V20"/></svg>
				</a>
			</div>
						<hr class="wp-header-end">

			<?php self::render_notice(); ?>
			<?php self::render_unverified(); ?>

			<?php
			ob_start();
			$pending = self::panel();
			$html    = (string) ob_get_clean();
			?>
			<?php if ( $pending ) : ?>
				<noscript><meta http-equiv="refresh" content="5" /></noscript>
			<?php endif; ?>
			<div class="nimbo-layout">
				<div class="nimbo-main">
					<div id="nimbocdn-panel" data-pending="<?php echo $pending ? '1' : '0'; ?>" aria-live="polite">
						<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by panel(), which escapes every value where it prints it. ?>
					</div>
										<p class="nimbo-note">
						<?php esc_html_e( 'NimboCDN never modifies your original files. Deactivate the plugin and your site returns to exactly how it was.', 'nimbocdn' ); ?>
					</p>
				</div>
				<aside class="nimbo-side">
					<div id="nimbocdn-why"><?php self::render_why( Probe::last(), Settings_Store::account() ); ?></div>
					<?php self::render_network(); ?>
					<?php self::render_support(); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	private static function panel() {
		$paused  = Settings_Store::is_paused();
		$probe   = Probe::last();
		$account = Settings_Store::account();
		$traffic = $account['traffic'];
		$images  = $account['images'];
		$is_pro  = 'pro' === $account['plan'];

		$pending = 'pending' === $probe['state'];
		if ( $pending && '' !== (string) $probe['stage'] && time() - max( (int) $probe['at'], (int) $probe['progress_at'] ) > Probe::PENDING_MAX ) {
			$pending         = false;
			$probe['state']  = 'error';
			$probe['detail'] = 'timeout';
		}
		$busy        = $pending || Probe::in_flight();
		$has_traffic = '' !== $traffic['updated_at'] && (int) $traffic['last_30d']['requests'] > 0;
		?>
			<div class="nimbo-card nimbo-hero-card<?php echo $paused ? ' nimbo-paused' : ''; ?>">
				<div class="nimbo-head">
					<p class="nimbo-title">
						<?php esc_html_e( 'Your visitors', 'nimbocdn' ); ?>
					</p>
					<div class="nimbo-head-right">
						<?php self::render_status( $probe, $paused, $pending ); ?>
						<?php
						if ( $paused || ! $busy ) {
							self::action_button(
								'nimbocdn_toggle',
								$paused ? __( 'Resume', 'nimbocdn' ) : __( 'Pause', 'nimbocdn' ),
								$paused ? 'button-primary nimbo-resume' : 'button-link nimbo-pause'
							);
						}
						?>
					</div>
				</div>
				<?php
				if ( $has_traffic ) {
					self::render_hero_traffic( $traffic );
				} else {
					self::render_hero_home( $probe, $pending );
				}
				self::render_tiles( $traffic, $has_traffic, $probe );
				self::render_formats( $traffic, $images, $is_pro, $has_traffic, $probe );
				?>
			</div>

			<div class="nimbo-card nimbo-home-card">
				<div class="nimbo-head">
					<p class="nimbo-title"><?php esc_html_e( 'Your home page', 'nimbocdn' ); ?></p>
					<?php
					if ( $paused ) {
						echo '<span class="nimbo-paused-tag">' . esc_html__( '— paused', 'nimbocdn' ) . '</span>';
					} else {
						self::measured_tag();
					}
					?>
				</div>
				<?php self::render_home( $probe, $pending, $paused, $is_pro, $account['home'] ); ?>
			</div>

			<div id="nimbocdn-plan" class="nimbo-card<?php echo $is_pro ? ' nimbo-card-pro' : ''; ?>">
				<div class="nimbo-head">
					<p class="nimbo-title"><?php esc_html_e( 'Your plan', 'nimbocdn' ); ?></p>
					<?php if ( $is_pro ) : ?>
						<span class="nimbo-plan-badge"><?php self::crown(); ?><?php esc_html_e( 'Pro plan', 'nimbocdn' ); ?></span>
					<?php else : ?>
						<span class="nimbo-plan-badge nimbo-plan-badge--free"><?php esc_html_e( 'Free plan', 'nimbocdn' ); ?></span>
					<?php endif; ?>
				</div>
				<?php
				if ( ! empty( $account['in_dunning'] ) ) :
					$days = (int) $account['past_due'];
					?>
					<div class="nimbo-dunning">
						<p class="nimbo-dunning-title"><?php esc_html_e( 'We could not charge your card', 'nimbocdn' ); ?></p>
						<p class="nimbo-dunning-body">
							<?php
							if ( $days > 0 ) {
								printf(
									/* translators: %s: number of days remaining */
									esc_html( _n( 'Update your payment method within %s day to keep Pro. Your images keep being delivered optimized in the meantime.', 'Update your payment method within %s days to keep Pro. Your images keep being delivered optimized in the meantime.', $days, 'nimbocdn' ) ),
									esc_html( number_format_i18n( $days ) )
								);
							} else {
								esc_html_e( 'Update your payment method today to keep Pro. Your images keep being delivered optimized in the meantime.', 'nimbocdn' );
							}
							?>
						</p>
						<?php self::action_button( 'nimbocdn_manage', __( 'Update payment method', 'nimbocdn' ), 'button-primary' ); ?>
					</div>
				<?php endif; ?>
				<?php self::render_plan( $is_pro, $account['home'], $images, (int) $probe['library'], is_array( $account['grant'] ) ? $account['grant'] : array(), $probe ); ?>
			</div>

			<?php self::render_technical(); ?>
		<?php
		return $busy;
	}

	private static function render_why( array $probe, array $account ) {
		$is_pro   = 'pro' === $account['plan'];
		$m        = $account['traffic']['last_30d'];
		$measured = 'serving' === $probe['state'] && (int) $probe['before'] > 0;

		$speed = $measured
			? sprintf(
				/* translators: %s: percentage, for example "88%" */
				__( 'Home page %s lighter · measured', 'nimbocdn' ),
				number_format_i18n( round( ( 1 - (int) $probe['after'] / (int) $probe['before'] ) * 100 ) ) . '%'
			)
			: __( 'Measured on the first visit to your home page.', 'nimbocdn' );

		$share = null;
		if ( (int) $m['requests'] >= self::MIN_REQUESTS_FOR_RATES && (int) $m['bytes_out'] > 0 ) {
			$share = round( (int) $m['bytes_cached'] / (int) $m['bytes_out'] * 100 );
		} elseif ( 'serving' === $probe['state'] && (int) $probe['after'] > 0 ) {
			$share = round( (int) $probe['cached_bytes'] / (int) $probe['after'] * 100 );
		}
		$server = null !== $share
			? sprintf(
				/* translators: %s: percentage, for example "91%" */
				__( '%s of your images are served from our cache, not from your server.', 'nimbocdn' ),
				number_format_i18n( $share ) . '%'
			)
			: __( 'Each image leaves your server once; after that, our cache serves it.', 'nimbocdn' );

		$outside = max( 0, (int) $probe['library'] - max( (int) $account['home']['count'], (int) $probe['page'] ) );
		?>
		<div class="nimbo-card nimbo-why">
			<div class="nimbo-head">
				<p class="nimbo-title"><?php esc_html_e( 'Why NimboCDN', 'nimbocdn' ); ?></p>
			</div>
			<p class="nimbo-why-lead">
				<?php esc_html_e( 'Every visitor gets smaller images, in the best format their browser accepts, delivered by our global network. Your WordPress stays exactly as it is.', 'nimbocdn' ); ?>
			</p>
			<ul class="nimbo-why-list">
				<li>
					<?php self::icon( 'bolt' ); ?>
					<span><b><?php esc_html_e( 'Faster site', 'nimbocdn' ); ?></b><br><?php echo esc_html( $speed ); ?></span>
				</li>
				<li>
					<?php self::icon( 'cloud' ); ?>
					<span><b><?php esc_html_e( 'Lighter server', 'nimbocdn' ); ?></b><br><?php echo esc_html( $server ); ?></span>
				</li>
				<li>
					<?php self::icon( 'trending-up' ); ?>
										<span><b><?php esc_html_e( 'Helps you rank on Google', 'nimbocdn' ); ?></b><br><?php esc_html_e( 'Google measures how fast your page loads, and images are what slow it down most.', 'nimbocdn' ); ?>
					<?php
					self::help( __( 'Google uses loading speed as one of its ranking signals. The lighter your images, the faster your page loads.', 'nimbocdn' ) );
					?>
					</span>
				</li>
			</ul>
						<?php if ( $is_pro ) : ?>
								<div class="nimbo-why-foot">
					<div class="nimbo-why-row">
						<?php self::why_safe(); ?>
						<span class="nimbo-why-pro"><?php self::crown(); ?><?php esc_html_e( 'Pro active · your whole site optimized', 'nimbocdn' ); ?></span>
					</div>
				</div>
			<?php else : ?>
			<dl class="nimbo-why-plans">
				<div><dt><?php esc_html_e( 'Free', 'nimbocdn' ); ?></dt><dd><?php esc_html_e( 'Your home page optimized, for good', 'nimbocdn' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Pro', 'nimbocdn' ); ?></dt><dd>
					<?php
					printf(
						/* translators: %s: the AVIF tag */
						wp_kses( __( 'Your whole site, in %s', 'nimbocdn' ), self::TAG_HTML ),
						wp_kses( self::avif_tag(), self::TAG_HTML )
					);
					echo wp_kses( '<span class="nimbo-why-price"></span>', self::TAG_HTML );
					?>
				</dd></div>
			</dl>
			<div class="nimbo-why-foot">
				<?php if ( $outside > 0 ) : ?>
					<span>
					<?php
					printf(
						/* translators: %s: number of images */
						esc_html( _n( '%s image outside your home page still reaches your visitors heavy.', '%s images outside your home page still reach your visitors heavy.', $outside, 'nimbocdn' ) ),
						'<b>' . esc_html( number_format_i18n( $outside ) ) . '</b>'
					);
					echo ' ';
					esc_html_e( 'With Pro, all of them would be optimized.', 'nimbocdn' );
					?>
					</span>
				<?php endif; ?>
				<div class="nimbo-why-row">
					<?php self::why_safe(); ?>
					<a href="#nimbocdn-plan"><?php esc_html_e( 'See Pro ↓', 'nimbocdn' ); ?></a>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A Tabler icon (tabler.io/icons, MIT), inline so the screen loads nothing.
	 *
	 * @param string $name One of bolt, cloud, trending-up, shield-check, mail.
	 */
	private static function icon( $name ) {
		$paths = array(
			'bolt'         => '<path d="M13 3l0 7l6 0l-8 11l0 -7l-6 0l8 -11"/>',
			'cloud'        => '<path d="M6.657 18c-2.572 0 -4.657 -2.007 -4.657 -4.483c0 -2.475 2.085 -4.482 4.657 -4.482c.393 -1.762 1.794 -3.2 3.675 -3.773c1.88 -.572 3.956 -.193 5.444 1c1.488 1.19 2.162 3.007 1.77 4.769h.99c1.913 0 3.464 1.56 3.464 3.486c0 1.927 -1.551 3.487 -3.465 3.487h-11.878"/>',
			'trending-up'  => '<path d="M3 17l6 -6l4 4l8 -8"/><path d="M14 7l7 0l0 7"/>',
			'shield-check' => '<path d="M11.46 20.846a12 12 0 0 1 -7.96 -14.846a12 12 0 0 0 8.5 -3a12 12 0 0 0 8.5 3a12 12 0 0 1 -.09 7.06"/><path d="M15 19l2 2l4 -4"/>',
			'mail'         => '<path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z"/><path d="M3 7l9 6l9 -6"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return;
		}
		echo '<svg class="nimbo-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG path data.
	}

	const NETWORK_CITIES    = 335;
	const NETWORK_COUNTRIES = 125;

	private static function render_network() {
		require_once NIMBOCDN_DIR . 'includes/data-world-dots.php';
		require_once NIMBOCDN_DIR . 'includes/data-network-pops.php';
		$dots = '';
		foreach ( explode( ';', WORLD_DOTS ) as $row ) {
			list( $r, $cols ) = explode( ':', $row, 2 );
			foreach ( explode( ',', $cols ) as $c ) {
				$dots .= sprintf( '<circle cx="%d" cy="%d" r="2.6"/>', (int) $c * 10 + 5, (int) $r * 10 + 5 );
			}
		}
		$cells = array();
		foreach ( explode( ';', NETWORK_POPS ) as $pop ) {
			list( $code, $at ) = explode( ':', $pop, 2 );
			list( $lat, $lon ) = explode( ',', $at, 2 );
			if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
				continue;
			}
			$col                          = max( 0, min( 119, (int) floor( ( (float) $lon + 180 ) / 3 ) ) );
			$row                          = max( 0, min( 57, (int) floor( ( 85 - (float) $lat ) / 3 ) ) );
			$cells[ $row . ':' . $col ][] = $code;
		}
		$pops = '';
		foreach ( $cells as $at => $codes ) {
			list( $row, $col ) = explode( ':', $at, 2 );
			sort( $codes );
			$pops .= sprintf(
				'<circle data-colo="%s" cx="%d" cy="%d" r="3.4"/>',
				implode( ' ', $codes ),
				(int) $col * 10 + 5,
				(int) $row * 10 + 5
			);
		}
		?>
		<div class="nimbo-card nimbo-network<?php echo Settings_Store::is_paused() ? ' nimbo-paused' : ''; ?>">
			<div class="nimbo-head">
				<p class="nimbo-title"><?php esc_html_e( 'Our global network', 'nimbocdn' ); ?></p>
			</div>
			<div class="nimbo-map-wrap">
				<svg class="nimbo-map" viewBox="0 0 1200 580" role="img" aria-label="<?php esc_attr_e( 'World map with our data centers and the one serving this computer', 'nimbocdn' ); ?>">
					<g class="nimbo-land"><?php echo $dots; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integers formatted by sprintf above. ?></g>
										<g class="nimbo-pops"><?php echo $pops; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- three-letter codes filtered by the regex above, coordinates formatted as floats by sprintf. ?></g>
				</svg>
								<span id="nimbocdn-you" class="nimbo-pulse ok nimbo-you" hidden></span>
			</div>
			<p class="nimbo-net-line" id="nimbocdn-net-line"><?php esc_html_e( 'Finding where your images are delivered from…', 'nimbocdn' ); ?></p>
			<p class="nimbo-hint">
				<span id="nimbocdn-net-cities">
					<?php
					printf(
						/* translators: 1: number of cities, 2: number of countries */
						esc_html__( '%1$s cities in more than %2$s countries deliver your images.', 'nimbocdn' ),
						esc_html( number_format_i18n( self::NETWORK_CITIES ) ),
						esc_html( number_format_i18n( self::NETWORK_COUNTRIES ) )
					);
					?>
				</span>
				<?php self::help( __( 'Approximate location from the IP address of this computer. Nothing is stored: it is shown and discarded.', 'nimbocdn' ) ); ?>
			</p>
		</div>
		<?php
	}

	private static function render_support() {
		?>
		<div class="nimbo-card nimbo-support">
			<div class="nimbo-head">
				<p class="nimbo-title"><?php esc_html_e( 'Talk to us', 'nimbocdn' ); ?></p>
			</div>
			<p class="nimbo-support-line">
				<?php esc_html_e( 'A question, a problem, an idea — write and a person answers.', 'nimbocdn' ); ?>
			</p>
						<a class="nimbo-support-mail" href="<?php echo esc_url( self::support_mailto() ); ?>">
				<?php self::icon( 'mail' ); ?>
				<b>hello@nimbocdn.net</b>
			</a>
			<p class="nimbo-support-line nimbo-support-path">
				<?php
				printf(
					/* translators: %s: the "Settings → NimboCDN" menu path, linked to this screen */
					esc_html__( 'This screen always lives under %s.', 'nimbocdn' ),
					'<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings → NimboCDN', 'nimbocdn' ) . '</a>'
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the sentence is escaped and the link is built and escaped right above.
				?>
			</p>
		</div>
		<?php
	}

	private static function script() {
		return <<<'JS'
(function () {
	var cfg = window.nimbocdnPanel;
	var root = document.getElementById('nimbocdn-panel');
	if (!cfg || !root || !window.fetch || !window.FormData) { return; }
	var timer = null, busy = false;
	var payerCountry = null;

	function post(action) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	function apply(data) {
		var open = {};
		[].forEach.call(root.querySelectorAll('details[data-key]'), function (d) { open[d.getAttribute('data-key')] = d.open; });
		var input = root.querySelector('input[name="nimbocdn_email"]');
		var typed = (input && document.activeElement === input) ? input.value : null;
		var chosen = selectedInterval();
		root.innerHTML = data.html;
		var why = document.getElementById('nimbocdn-why');
		if (why && typeof data.why === 'string') { why.innerHTML = data.why; }
		[].forEach.call(root.querySelectorAll('details[data-key]'), function (d) { var k = d.getAttribute('data-key'); d.open = open[k] === true; });
		if (typed !== null) {
			var again = root.querySelector('input[name="nimbocdn_email"]');
			if (again) { again.value = typed; again.focus(); }
		}
		var period = root.querySelector('.nimbo-period-in[value="' + chosen + '"]');
		if (period) {
			period.checked = true;
			var field = root.querySelector('[data-interval-field]');
			if (field) { field.value = chosen; }
		}
		root.setAttribute('data-pending', data.pending ? '1' : '0');
		root.classList.remove('nimbo-busy');
		if (typeof data.whoami === 'string' && data.whoami !== '' && data.whoami !== cfg.whoami) { cfg.whoami = data.whoami; locate(); }
		if (data.prices && typeof data.prices === 'object') { cfg.prices = data.prices; }
		bind();
		countdown();
		schedule();
		bindPeriod();
		bindSwitch();
		priceUpgrade();
	}

	function fail(message) {
		var note = document.createElement('div');
		note.className = 'notice notice-error is-dismissible nimbo-inline-notice';
		note.innerHTML = '<p></p>';
		note.firstChild.textContent = message;
		root.parentNode.insertBefore(note, root);
		setTimeout(function () { if (note.parentNode) { note.parentNode.removeChild(note); } }, 8000);
	}

	var working = false;
	function work() {
		if (working || document.hidden || root.getAttribute('data-pending') !== '1') { return; }
		working = true;
		post('nimbocdn_panel_work')
			.then(function (r) { working = false; if (r && r.success) { apply(r.data); } })
			.catch(function () { working = false; })
			.then(function () { if (root.getAttribute('data-pending') === '1') { setTimeout(work, 300); } });
	}
	function refresh() {
		if (busy || document.hidden) { schedule(); return; }
		post('nimbocdn_panel')
			.then(function (r) { if (r && r.success) { apply(r.data); } else { schedule(); } })
			.catch(schedule);
	}

	function schedule() {
		clearTimeout(timer);
		if (root.getAttribute('data-pending') === '1') { timer = setTimeout(refresh, cfg.readEvery); work(); }
	}

	var lastAction = 0;
	function actionRefresh() {
		if (busy || document.hidden || Date.now() - lastAction < 10000) { return; }
		lastAction = Date.now();
		post('nimbocdn_refresh').then(function (r) { if (r && r.success) { apply(r.data); } }).catch(function () {});
	}

	function countdown() {
		var button = root.querySelector('button[data-cooldown]');
		if (!button) { return; }
		var left = parseInt(button.getAttribute('data-cooldown'), 10);
		var tick = setInterval(function () {
			left -= 1;
			if (!document.body.contains(button)) { clearInterval(tick); return; }
			if (left <= 0) { clearInterval(tick); refresh(); return; }
			button.textContent = cfg.cooldown.replace('%s', left);
		}, 1000);
	}

	function bind() {
		var field = root.querySelector('form.nimbo-action input[value="nimbocdn_recheck"]');
		if (!field) { return; }
		field.form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			if (busy) { return; }
			busy = true;
			var button = field.form.querySelector('button');
			var label = button.textContent;
			button.disabled = true;
			button.textContent = cfg.checking;
			root.classList.add('nimbo-busy');
			post('nimbocdn_recheck')
				.then(function (r) {
					busy = false;
					if (r && r.success) { apply(r.data); return; }
					button.disabled = false; button.textContent = label; root.classList.remove('nimbo-busy');
					fail(cfg.failed);
				})
				.catch(function () {
					busy = false;
					button.disabled = false; button.textContent = label; root.classList.remove('nimbo-busy');
					fail(cfg.failed);
				});
		});
	}

	document.documentElement.classList.add('nimbo-js');
	var tipEl = document.createElement('div');
	tipEl.className = 'nimbo-tooltip';
	tipEl.setAttribute('role', 'tooltip');
	document.body.appendChild(tipEl);
	function showTip(target) {
		var text = target.getAttribute('data-tip');
		if (!text) { return; }
		tipEl.textContent = text;
		tipEl.style.left = '0px'; tipEl.style.top = '0px';
		tipEl.className = 'nimbo-tooltip on';
		var r = target.getBoundingClientRect(), t = tipEl.getBoundingClientRect();
		var margin = 8, gap = 7;
		var left = r.left + r.width / 2 - t.width / 2;
		left = Math.max(margin, Math.min(window.innerWidth - t.width - margin, left));
		var top = r.top - t.height - gap;
		if (top < margin) { top = r.bottom + gap; }
		if (top + t.height > window.innerHeight - margin) { top = Math.max(margin, r.top - t.height - gap); }
		tipEl.style.left = Math.round(left) + 'px';
		tipEl.style.top = Math.round(top) + 'px';
	}
	function hideTip() { tipEl.className = 'nimbo-tooltip'; }
	function tipTarget(ev) { return ev.target.closest ? ev.target.closest('.nimbo-tip[data-tip]') : null; }
	document.addEventListener('mouseover', function (ev) { var t = tipTarget(ev); if (t) { showTip(t); } });
	document.addEventListener('mouseout', function (ev) { if (tipTarget(ev)) { hideTip(); } });
	document.addEventListener('focusin', function (ev) { var t = tipTarget(ev); if (t) { showTip(t); } });
	document.addEventListener('focusout', function (ev) { if (tipTarget(ev)) { hideTip(); } });
	document.addEventListener('scroll', hideTip, true);
	window.addEventListener('resize', hideTip);

	var sparkTip = document.createElement('span');
	sparkTip.className = 'nimbo-spark-tip';
	document.body.appendChild(sparkTip);
	function hideTips() { sparkTip.className = 'nimbo-spark-tip'; }
	root.addEventListener('mousemove', function (ev) {
		var bar = ev.target.closest ? ev.target.closest('.nimbo-spark g[data-tip]') : null;
		var wrap = ev.target.closest ? ev.target.closest('.nimbo-spark-wrap') : null;
		if (!wrap) { hideTips(); return; }
		var tip = sparkTip;
		if (!bar) { tip.className = 'nimbo-spark-tip'; return; }
		var text = bar.getAttribute('data-tip');
		if (!text) { hideTips(); return; }
		var box = bar.getBoundingClientRect(), host = wrap.getBoundingClientRect();
		tip.textContent = text;
		tip.className = 'nimbo-spark-tip on';
		var half = tip.offsetWidth / 2, margin = 8;
		var centre = box.left + box.width / 2;
		centre = Math.max(margin + half, Math.min(window.innerWidth - margin - half, centre));
		tip.style.left = centre + 'px';
		tip.style.top = (host.top - tip.offsetHeight - 6) + 'px';
	});
	root.addEventListener('mouseleave', hideTips);
	document.addEventListener('scroll', hideTips, true);

	var NET_MS_THRESHOLD = 100;
	var NET_SAMPLES = 3;
	var NET_RETRY_MS = 600;

	function netFetch(url) {
		var opts = { cache: 'no-store', mode: 'cors' };
		return fetch(url, opts).then(function (r) { return r.json(); }).catch(function () {
			return new Promise(function (ok) { setTimeout(ok, NET_RETRY_MS); }).then(function () {
				return fetch(url, opts).then(function (r) { return r.json(); });
			});
		});
	}

	function netTiming(url, seen) {
		if (!window.performance || !performance.getEntriesByName) { return null; }
		var all = performance.getEntriesByName(url);
		if (all.length <= seen) { return null; }
		var e = all[all.length - 1];
		if (e.requestStart > 0 && e.responseStart > 0) { return e.responseStart - e.requestStart; }
		return e.duration > 0 ? e.duration : null;
	}

	function netSample(url, out) {
		var seen = (window.performance && performance.getEntriesByName) ? performance.getEntriesByName(url).length : 0;
		var t0 = window.performance ? performance.now() : 0;
		return netFetch(url).then(function (d) {
			var ms = netTiming(url, seen);
			if (ms === null) { ms = window.performance ? performance.now() - t0 : 1; }
			out.push({ d: d, ms: Math.max(1, Math.round(ms)) });
		}).catch(function () {});
	}

	function netMeasure(url) {
		var out = [];
		function samples(warm) {
			var chain = Promise.resolve();
			for (var i = 0; i < NET_SAMPLES; i++) { chain = chain.then(function () { return netSample(url, out); }); }
			return chain.then(function () {
				if (!out.length) { return warm ? { d: warm, ms: null } : null; }
				var ms = out[0].ms, d = out[out.length - 1].d;
				for (var j = 1; j < out.length; j++) { if (out[j].ms < ms) { ms = out[j].ms; } }
				return { d: d, ms: ms };
			});
		}
		return netFetch(url).then(samples, function () { return samples(null); });
	}

	function locate() {
		if (!cfg.whoami || !window.performance) { return; }
		netMeasure(cfg.whoami).then(function (res) {
			if (res && typeof res.d.country === 'string' && res.d.country.length === 2) { payerCountry = res.d.country; }
			priceUpgrade();
			paintNetwork(res);
		}).catch(function () { paintNetwork(null); });
	}

	function paintNetwork(res) {
		var line = document.getElementById('nimbocdn-net-line');
		var you = document.getElementById('nimbocdn-you');
		if (!line) { return; }
		if (!res) { line.textContent = cfg.netError; return; }
		var d = res.d, ms = res.ms;
		var where = d.colo_city || d.colo;
		if (!where) { return; }
		if (ms !== null && ms <= NET_MS_THRESHOLD) {
			line.textContent = (cfg.paused ? cfg.netLinePaused : cfg.netLine).replace('%1$s', where).replace('%2$s', cfg.ms.replace('%s', ms));
		} else {
			line.textContent = (cfg.paused ? cfg.netLinePausedNoMs : cfg.netLineNoMs).replace('%s', where);
		}
		if (!you) { return; }
		var lat = typeof d.colo_latitude === 'number' ? d.colo_latitude : d.latitude;
		var lon = typeof d.colo_longitude === 'number' ? d.colo_longitude : d.longitude;
		if (typeof lat === 'number' && typeof lon === 'number') {
			var col = Math.max(0, Math.min(119, Math.floor((lon + 180) / 3)));
			var row = Math.max(0, Math.min(57, Math.floor((85 - lat) / 3)));
			you.style.left = ((col * 10 + 5) / 12).toFixed(2) + '%';
			you.style.top = ((row * 10 + 5) / 5.8).toFixed(2) + '%';
			you.hidden = false;
			if (/^[A-Z]{3}$/.test(d.colo)) {
				var mine = document.querySelector('.nimbo-pops [data-colo~="' + d.colo + '"]');
				if (mine) { mine.setAttribute('class', 'nimbo-me'); }
			}
		}
	}

	function priceUpgrade() {
		if (payerCountry === null) { return; }
		var p = (cfg.prices || {})[payerCountry === 'BR' ? 'brl' : 'usd'];
		if (!p) { return; }
		var year = selectedInterval() === 'year';
		var price = year ? p.year : p.month;

		var withPeriod = (year ? cfg.priceYear : cfg.priceMonth).replace('%s', price);
		[].forEach.call(document.querySelectorAll('.nimbo-why-price'), function (el) {
			el.textContent = withPeriod;
		});

		var note = document.querySelector('[data-period-note]');
		if (note) {
			note.innerHTML = '';
			if (year && p.monthly_equivalent) {
				note.appendChild(document.createTextNode(
					cfg.noteYear.replace('%1$s', p.year).replace('%2$s', p.monthly_equivalent)
						.replace('%3$s', p.save)
				));
			} else if (!year) {
				note.appendChild(document.createTextNode(cfg.noteMonth.replace('%s', p.month)));
			}
		}

		var sw = document.getElementById('nimbocdn-switch');
		if (sw && cfg.switchYear && intervalOf(sw) === 'year') {
			sw.textContent = cfg.switchYear.replace('%s', p.year);
		}
		var btn = document.getElementById('nimbocdn-upgrade');
		if (!btn) { return; }
		btn.textContent = (year ? cfg.getProYear : cfg.getProPriced).replace('%s', price);
	}

	function selectedInterval() {
		var checked = document.querySelector('.nimbo-period-in:checked');
		if (checked) { return checked.value; }
		var btn = document.getElementById('nimbocdn-upgrade');
		return btn ? intervalOf(btn) : 'month';
	}

	function intervalOf(btn) {
		var form = btn.closest ? btn.closest('form') : null;
		var field = form ? form.querySelector('[data-interval-field]') : null;
		return field ? field.value : 'month';
	}

	function bindPeriod() {
		[].forEach.call(document.querySelectorAll('.nimbo-period-in'), function (input) {
			input.addEventListener('change', function () {
				var btn = document.getElementById('nimbocdn-upgrade');
				var form = btn && btn.closest ? btn.closest('form') : null;
				var field = form ? form.querySelector('[data-interval-field]') : null;
				if (field) { field.value = input.value; }
				priceUpgrade();
			});
		});
	}

	function bindSwitch() {
		var btn = document.getElementById('nimbocdn-switch');
		if (!btn || !btn.form) { return; }
		btn.form.addEventListener('submit', function (e) {
			var year = intervalOf(btn) === 'year';
			var p = (cfg.prices || {})[payerCountry === 'BR' ? 'brl' : 'usd'];
			var msg;
			if (!year) {
				msg = cfg.confirmMonth;
			} else if (p && payerCountry !== null) {
				msg = cfg.confirmYear.replace('%1$s', p.year).replace('%2$s', p.save);
			} else {
				return;
			}
			if (!window.confirm(msg)) { e.preventDefault(); }
		});
	}

	document.addEventListener('visibilitychange', function () { if (!document.hidden) { actionRefresh(); } });
	bind();
	bindPeriod();
	bindSwitch();
	countdown();
	schedule();
	actionRefresh();
	if (window.requestIdleCallback) { requestIdleCallback(locate, { timeout: 3000 }); }
	else if (document.readyState === 'complete') { setTimeout(locate, 300); }
	else { window.addEventListener('load', function () { setTimeout(locate, 300); }); }
})();
JS;
	}

	private static function render_status( array $probe, $paused, $pending ) {
		if ( $paused ) {
			$class = 'idle';
			$text  = __( 'Optimization paused', 'nimbocdn' );
		} elseif ( $pending ) {
			$class = 'busy';
			$text  = __( 'Measuring…', 'nimbocdn' );
		} elseif ( 'serving' === $probe['state'] ) {
			$class = 'ok';
			$text  = __( 'Delivering optimized images', 'nimbocdn' );
		} elseif ( 'fallback' === $probe['state'] ) {
			$class = 'warn';
			$text  = __( 'New images not optimized', 'nimbocdn' );
		} elseif ( 'error' === $probe['state'] ) {
			$class = 'warn';
			$text  = __( 'Could not measure', 'nimbocdn' );
		} else {
			$class = 'idle';
			$text  = __( 'Not measured yet', 'nimbocdn' );
		}

		printf(
			'<span class="nimbo-status"><span class="nimbo-pulse %s"></span><b>%s</b></span>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}

	private static function render_hero_traffic( array $traffic ) {
		$m        = $traffic['last_30d'];
		$before   = (int) $m['bytes_wp'];
		$after    = (int) $m['bytes_out_weighed'];
		$saved    = $before - $after;
		$life     = (int) $traffic['lifetime']['bytes_wp'] - (int) $traffic['lifetime']['bytes_out_weighed'];
		$requests = (int) $m['requests'];
		$weighed  = (int) $m['weighed'];
		?>
		<div class="nimbo-hero">
			<div class="nimbo-hero-text">
								<p class="nimbo-kicker"><?php esc_html_e( 'In the last 30 days your visitors received each image', 'nimbocdn' ); ?></p>
				<?php if ( $saved > 0 ) : ?>
					<p class="nimbo-big">
						<b class="nimbo-gain"><?php echo esc_html( self::percent_lighter( $before, $after ) ); ?></b>
						<?php
						if ( (int) $m['sampled'] > 0 ) {
							self::estimated_tag();
						}
						?>
					</p>
					<p class="nimbo-sub">
						<?php
						/* translators: %s: amount of data, for example "1.8 GB" */
						printf( esc_html__( 'That is %s they did not have to download', 'nimbocdn' ), '<b>' . esc_html( size_format( $saved, 1 ) ) . '</b>' );
						?>
					</p>
				<?php else : ?>
					<p class="nimbo-big">
						<b><?php echo esc_html( size_format( $after, 1 ) ); ?></b>
					</p>
					<p class="nimbo-sub"><?php esc_html_e( 'of images — no saving measured yet against what WordPress would have served.', 'nimbocdn' ); ?></p>
				<?php endif; ?>
				<p class="nimbo-hint">
					<?php
					if ( $life > $saved ) {
						/* translators: %s: amount of data, for example "12.4 GB" */
						printf( esc_html__( 'Since you installed NimboCDN: %s less.', 'nimbocdn' ), esc_html( size_format( $life, 1 ) ) );
						echo ' ';
					}
					?>
				</p>
			</div>
			<?php self::render_sparkline( $traffic['series'] ); ?>
		</div>
		<?php
	}

	private static function render_hero_home( array $probe, $pending ) {
		if ( $pending ) {
			?>
			<div class="nimbo-hero">
				<div class="nimbo-hero-text">
					<p class="nimbo-big nimbo-measuring"><span class="nimbo-spin" aria-hidden="true"></span><?php esc_html_e( 'Measuring your home page…', 'nimbocdn' ); ?></p>
					<?php self::render_progress( $probe ); ?>
				</div>
								<?php self::render_sparkline( array() ); ?>
			</div>
			<?php
			return;
		}

		if ( 'serving' === $probe['state'] && $probe['before'] > 0 ) {
			$before = (int) $probe['before'];
			$after  = (int) $probe['after'];
			?>
			<div class="nimbo-hero">
				<div class="nimbo-hero-text">
										<p class="nimbo-kicker"><?php esc_html_e( 'Each image on your home page is now', 'nimbocdn' ); ?></p>
					<p class="nimbo-big">
						<b class="nimbo-gain"><?php echo esc_html( self::percent_lighter( $before, $after ) ); ?></b>
						<?php self::measured_tag(); ?>
					</p>
					<p class="nimbo-sub">
						<span class="was"><?php echo esc_html( size_format( $before, 1 ) ); ?></span>
						<span class="arw">&rarr;</span>
						<span class="now"><?php echo esc_html( size_format( $after, 1 ) ); ?></span>
					</p>
					<p class="nimbo-hint"><?php esc_html_e( 'As soon as the first visits arrive, this number updates with them.', 'nimbocdn' ); ?></p>
				</div>
								<?php self::render_sparkline( array() ); ?>
			</div>
			<?php
			return;
		}

		printf( '<p class="nimbo-hint nimbo-explain">%s</p>', esc_html( self::explain( $probe ) ) );
	}

	private static function render_progress( array $probe ) {
		$found   = (int) $probe['found'];
		$weighed = (int) $probe['weighed'];
		$line    = self::progress_line( $probe );
		$pct     = $found > 0 ? (int) round( $weighed / $found * 100 ) : 0;
		?>
		<p class="nimbo-sub nimbo-progress-line"><?php echo esc_html( $line ); ?></p>
		<span class="nimbo-track nimbo-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $pct; ?>"><i style="width:<?php echo (int) max( 2, $pct ); ?>%"></i></span>
		<p class="nimbo-hint"><?php esc_html_e( 'This is running in the background and can take up to a minute. This screen refreshes on its own — nothing to press.', 'nimbocdn' ); ?></p>
		<?php
	}

	private static function progress_line( array $probe ) {
		$found   = (int) $probe['found'];
		$weighed = (int) $probe['weighed'];
		$stage   = isset( $probe['stage'] ) ? (string) $probe['stage'] : '';
		$tries   = isset( $probe['tries'] ) ? (int) $probe['tries'] : 0;
		switch ( $stage ) {
			case 'register':
				$line = __( 'Step 1 of 4 — connecting this site to the service.', 'nimbocdn' );
				break;
			case 'page':
				$line = __( 'Step 1 of 4 — reading your home page and finding its images.', 'nimbocdn' );
				break;
			case 'sync':
				/* translators: %s: number of images */
				$line = sprintf( __( 'Step 2 of 4 — registering %s images with the service.', 'nimbocdn' ), number_format_i18n( max( $found, (int) $probe['page'] ) ) );
				break;
			case 'page_again':
				$line = __( 'Step 2 of 4 — reading the home page again, now optimized.', 'nimbocdn' );
				break;
			case 'fetch':
				/* translators: 1: images weighed so far, 2: images to weigh */
				$line = sprintf( __( 'Step 3 of 4 — weighing the images: %1$s of %2$s.', 'nimbocdn' ), number_format_i18n( $weighed ), number_format_i18n( $found ) );
				break;
			case 'retry_scope':
				/* translators: 1: images weighed so far, 2: images to weigh, 3: attempt number, 4: attempts */
				$line = sprintf( __( 'Step 3 of 4 — %1$s of %2$s weighed; waiting for the network to learn the new list (attempt %3$s of %4$s).', 'nimbocdn' ), number_format_i18n( $weighed ), number_format_i18n( $found ), number_format_i18n( $tries ), number_format_i18n( Probe::SCOPE_RETRIES ) );
				break;
			case 'retry_failed':
				/* translators: 1: images weighed so far, 2: images to weigh */
				$line = sprintf( __( 'Step 3 of 4 — %1$s of %2$s weighed; one more try for the rest.', 'nimbocdn' ), number_format_i18n( $weighed ), number_format_i18n( $found ) );
				break;
			case 'recache':
				$line = __( 'Step 4 of 4 — second pass, from the cache, to measure the response time.', 'nimbocdn' );
				break;
			default:
				$line = __( 'Starting…', 'nimbocdn' );
		}
		return $line;
	}

	private static function render_tiles( array $traffic, $has_traffic, array $probe = array() ) {
		$m        = $traffic['last_30d'];
		$requests = (int) $m['requests'];
		$rates    = $requests >= self::MIN_REQUESTS_FOR_RATES;
		$dash     = '—';
		$bytes_out = (int) $m['bytes_out'];

		$checked = ! empty( $probe ) && 'serving' === $probe['state'] && (int) $probe['images'] > 0;
		$sample  = ! $has_traffic && $checked;
		if ( $sample ) {
			$requests = (int) $probe['images'];
			$bytes_out = (int) $probe['after'];
		}
		if ( ! $rates && $checked ) {
			$m     = array(
				'cached'          => (int) $probe['cached'],
				'delivery_ms_p50' => (float) $probe['ms'],
				'bytes_cached'    => (int) $probe['cached_bytes'],
				'bytes_out'       => (int) $probe['after'],
			);
			$rates = true;
		}
		$rate_base = isset( $m['bytes_out'] ) ? (int) $m['bytes_out'] : 0;

		$tiles = array(
			array(
				'value' => ( $has_traffic || $sample ) ? number_format_i18n( $requests ) : $dash,
				'label' => __( 'images delivered', 'nimbocdn' ),
				'tip'   => $sample
					? __( 'Images delivered by NimboCDN in the latest measurement of your home page. Visitor traffic takes over as it arrives.', 'nimbocdn' )
					: __( 'Images NimboCDN delivered to your visitors in the last 30 days.', 'nimbocdn' ),
			),
			array(
				'value' => ( $has_traffic || $sample ) && $bytes_out > 0 ? size_format( $bytes_out, 1 ) : $dash,
				'label' => __( 'delivered by our network', 'nimbocdn' ),
				'tip'   => $sample
					? __( 'What NimboCDN delivered while weighing your home page. Visitor traffic takes over as it arrives.', 'nimbocdn' )
					: __( 'Everything NimboCDN sent to your visitors in the last 30 days. Your own server did not have to send any of it.', 'nimbocdn' ),
			),
			array(
				'value' => ( $rates && $rate_base > 0 ) ? number_format_i18n( round( (int) $m['bytes_cached'] / $rate_base * 100 ) ) . '%' : $dash,
				'label' => __( 'without touching your server', 'nimbocdn' ),
				'tip'   => $rates
					? __( 'They were already on the NimboCDN network: delivered without touching your server. This number tends to rise over time: as visitors browse, images that were already optimized are served straight from our global network.', 'nimbocdn' )
					: __( 'Shown once enough images have been delivered: on the first day almost everything is a first request.', 'nimbocdn' ),
			),
		);
		$delivery = isset( $m['delivery_ms_p50'] ) ? (float) $m['delivery_ms_p50'] : -1;
		if ( $rates && $delivery >= 0 ) {
			$tiles[] = array(
				'value' => number_format_i18n( round( $delivery ) ) . ' ms',
				'label' => __( 'network response time', 'nimbocdn' ),
				'tip'   => __( 'Median time for our network to answer with an image it already has optimized. It does not include the trip to the visitor.', 'nimbocdn' ),
			);
		}
		?>
		<div class="nimbo-tiles">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="nimbo-tile">
					<span class="nimbo-tile-value"><?php echo esc_html( $tile['value'] ); ?></span>
					<span class="nimbo-tile-label">
						<span><?php echo esc_html( $tile['label'] ); ?></span>
						<?php self::help( $tile['tip'] ); ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_formats( array $traffic, array $images, $is_pro, $has_traffic, array $probe = array() ) {
		$m        = $traffic['last_30d'];
		$requests = (int) $m['requests'];
		if ( ( ! $has_traffic || $requests < self::MIN_REQUESTS_FOR_RATES ) && ! empty( $probe['formats'] ) ) {
			$m = array(
				'avif' => (int) $probe['formats']['avif'],
				'webp' => (int) $probe['formats']['webp'],
				'jpeg' => (int) $probe['formats']['jpeg'],
				'png'  => (int) $probe['formats']['png'],
			);
		} elseif ( ! $has_traffic || $requests < self::MIN_REQUESTS_FOR_RATES ) {
			return;
		}

		$shares = array(
			'AVIF' => (int) $m['avif'],
			'WebP' => (int) $m['webp'],
			'JPEG' => (int) $m['jpeg'],
			'PNG'  => (int) $m['png'],
		);
		$total  = array_sum( $shares );
		if ( $total <= 0 ) {
			return;
		}
		?>
		<div class="nimbo-formats">
			<p class="nimbo-label"><?php esc_html_e( 'Format your visitors received', 'nimbocdn' ); ?></p>
			<div class="nimbo-share">
				<?php
				$offset = 0.0;
				foreach ( $shares as $name => $n ) :
					if ( 0 === $n ) {
						continue;
					}
					$pct = $n / $total * 100;
					/* translators: 1: format name, 2: number of images, 3: percentage */
					$tip     = sprintf( __( '%1$s: %2$s images (%3$s)', 'nimbocdn' ), $name, number_format_i18n( $n ), number_format_i18n( round( $pct, 1 ) ) . '%' );
					$offset += $pct;
					?>
					<span class="nimbo-share-seg nimbo-tip <?php echo esc_attr( strtolower( $name ) ); ?><?php echo $offset > 55 ? ' nimbo-tip-end' : ''; ?>"
						style="width:<?php echo esc_attr( (string) max( 1.5, $pct ) ); ?>%"
						tabindex="0" role="img" aria-label="<?php echo esc_attr( $tip ); ?>" data-tip="<?php echo esc_attr( $tip ); ?>">
						<?php if ( $pct >= 9 ) :?>
							<span><?php echo esc_html( $name . ' ' . number_format_i18n( round( $pct ) ) . '%' ); ?></span>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<ul class="nimbo-legend">
				<?php foreach ( $shares as $name => $n ) : ?>
					<?php
					if ( 0 === $n ) {
						continue;
					}
					?>
					<li><i class="<?php echo esc_attr( strtolower( $name ) ); ?>"></i><b><?php echo esc_html( $name ); ?></b>
						<?php echo esc_html( number_format_i18n( $n ) . ' · ' . number_format_i18n( round( $n / $total * 100 ) ) . '%' ); ?></li>
				<?php endforeach; ?>
			</ul>
					</div>
		<?php
	}

	private static function render_sparkline( array $series ) {
		$series = array_slice( array_pad( array_map( 'intval', $series ), -30, 0 ), -30 );
		$max    = max( 1, max( $series ) );
		$bars   = '';
		$today  = strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' );
		foreach ( array_values( $series ) as $i => $v ) {
			$h     = $v > 0 ? max( 1, round( $v / $max * 34 ) ) : 1;
			$day   = $today - ( 29 - $i ) * DAY_IN_SECONDS;
			$label = $v > 0
				/* translators: 1: date, 2: amount of data, for example "1.8 MB" */
				? sprintf( __( '%1$s: %2$s less', 'nimbocdn' ), date_i18n( self::date_format(), $day ), size_format( $v, 1 ) )
				/* translators: %s: date */
				: sprintf( __( '%s: no saving recorded', 'nimbocdn' ), date_i18n( self::date_format(), $day ) );
			$bars .= sprintf(
				'<g%1$s role="img" aria-label="%2$s"><rect x="%3$d" y="0" width="10" height="36" fill="transparent"/><rect x="%4$d" y="%5$d" width="8" height="%6$d" rx="1"/></g>',
				$v > 0 ? ' data-tip="' . esc_attr( $label ) . '"' : '',
				esc_attr( $label ),
				$i * 10,
				$i * 10,
				36 - $h,
				$h
			);
		}
		$empty = 0 === array_sum( $series )
			? '<span class="nimbo-spark-empty">' . esc_html__( 'Last 30 days — fills in with the first visits', 'nimbocdn' ) . '</span>'
			: '';
		printf(
			'<span class="nimbo-spark-wrap"><svg class="nimbo-spark" viewBox="0 0 298 36" preserveAspectRatio="none" role="img" aria-label="%s">%s</svg>%s</span>',
			esc_attr__( 'Data saved per day, last 30 days', 'nimbocdn' ),
			$bars, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integers formatted by sprintf above; the title text is escaped where it is built.
			$empty // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}

	private static function render_home( array $probe, $pending, $paused = false, $is_pro = false, array $home = array() ) {
		if ( $paused ) {
			printf( '<p class="nimbo-hint">%s</p>', esc_html( self::explain( array_merge( $probe, array( 'state' => 'paused' ) ) ) ) );
			return;
		}
		if ( $pending ) {
			if ( (int) $probe['found'] > 0 ) {
				printf(
					'<p class="nimbo-hint nimbo-measuring"><span class="nimbo-spin" aria-hidden="true"></span>%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: images weighed so far, 2: images found */
							__( 'Weighing: %1$s of %2$s images. The result appears here as soon as the first pass ends.', 'nimbocdn' ),
							number_format_i18n( (int) $probe['weighed'] ),
							number_format_i18n( (int) $probe['found'] )
						)
					)
				);
			} else {
				printf( '<p class="nimbo-hint nimbo-measuring"><span class="nimbo-spin" aria-hidden="true"></span>%s</p>', esc_html__( 'Measuring… the progress is shown above.', 'nimbocdn' ) );
			}
			return;
		}
		if ( 'serving' !== $probe['state'] || $probe['before'] <= 0 ) {
			printf( '<p class="nimbo-hint">%s</p>', esc_html( self::explain( $probe ) ) );
			self::render_allowance( $probe, $is_pro, $home );
			self::render_home_meta( $probe );
			return;
		}

		$before = (int) $probe['before'];
		$after  = (int) $probe['after'];
		?>
		<div class="nimbo-bars">
			<div class="nimbo-bar-row">
				<span class="nimbo-bar-name"><?php esc_html_e( 'Without NimboCDN', 'nimbocdn' ); ?></span>
				<span class="nimbo-track"><i style="width:100%"></i></span>
				<span class="nimbo-size"><?php echo esc_html( size_format( $before, 1 ) ); ?></span>
			</div>
			<div class="nimbo-bar-row here">
				<span class="nimbo-bar-name">
					<?php esc_html_e( 'With NimboCDN', 'nimbocdn' ); ?>
					<span class="nimbo-tag mine"><?php echo esc_html( self::format_name( $probe['format'] ) ); ?></span>
				</span>
				<span class="nimbo-track"><i style="width:<?php echo esc_attr( (string) max( 2, round( $after / $before * 100 ) ) ); ?>%"></i></span>
				<span class="nimbo-size"><?php echo esc_html( size_format( $after, 1 ) ); ?> <b class="nimbo-cut"><?php echo esc_html( self::percent_lighter( $before, $after ) ); ?></b></span>
			</div>
		</div>
		<?php
		self::render_allowance( $probe, $is_pro, $home );
		self::render_home_meta( $probe );
	}

	private static function render_allowance( array $probe, $is_pro, array $home ) {
		$unserved = (int) $probe['unserved'];
		if ( $is_pro || $unserved <= 0 || 'serving' !== $probe['state'] ) {
			return;
		}
		$limit     = isset( $home['limit'] ) ? (int) $home['limit'] : 0;
		$used      = isset( $home['used'] ) ? (int) $home['used'] : 0;
		$renews    = ! empty( $home['renews_at'] ) ? strtotime( $home['renews_at'] ) : false;
		$exhausted = $limit > 0 && $used >= $limit;
		?>
		<div class="nimbo-allowance">
			<p class="nimbo-allowance-title">
				<?php
				if ( $exhausted ) {
					esc_html_e( 'This month’s allowance is used up.', 'nimbocdn' );
				} else {
					esc_html_e( 'Part of your home page is not optimized yet.', 'nimbocdn' );
				}
				?>
			</p>
			<p class="nimbo-allowance-body">
				<?php
				printf(
					/* translators: %s: number of images */
					esc_html( _n( '%s image on your home page is still delivered by WordPress, as before NimboCDN.', '%s images on your home page are still delivered by WordPress, as before NimboCDN.', $unserved, 'nimbocdn' ) ),
					'<b>' . esc_html( number_format_i18n( $unserved ) ) . '</b>'
				);
				echo ' ';
				if ( $exhausted && $renews ) {
					printf(
						/* translators: %s: a date */
						esc_html__( 'Nothing is wrong. Two ways out, both fine: get Pro and they are optimized today, or wait — on %s the allowance renews and NimboCDN continues on its own.', 'nimbocdn' ),
						esc_html( date_i18n( self::date_format(), $renews ) )
					);
				} elseif ( $exhausted ) {
					esc_html_e( 'Nothing is wrong. Two ways out, both fine: get Pro and they are optimized today, or wait for next month, when the allowance renews and NimboCDN continues on its own.', 'nimbocdn' );
				} else {
					esc_html_e( 'The service registers home page images when the page changes. Press Measure now; if they stay out, get in touch.', 'nimbocdn' );
				}
				?>
			</p>
			<?php if ( $exhausted ) : ?>
				<?php self::action_button( 'nimbocdn_upgrade', __( 'Get Pro', 'nimbocdn' ), 'button' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_home_meta( array $probe ) {
		?>
		<div class="nimbo-meta">
			<?php
			echo '<span class="nimbo-meta-text">';
			$images   = (int) $probe['images'];
			$page     = max( (int) $probe['page'], (int) $probe['served'], $images );
			$unserved = (int) $probe['unserved'];
			$missing  = max( 0, (int) $probe['served'] - $images );
			if ( $images > 0 && $images === $page ) {
				printf(
					/* translators: %s: number of images measured */
					esc_html( _n( 'The %s image on this page', 'The %s images on this page', $images, 'nimbocdn' ) ),
					esc_html( number_format_i18n( $images ) )
				);
				echo ' <span class="nimbo-sep">&middot;</span> ';
			} elseif ( $images > 0 ) {
				printf(
					/* translators: 1: images measured, 2: images on the page */
					esc_html__( '%1$s of the %2$s images on this page', 'nimbocdn' ),
					esc_html( number_format_i18n( $images ) ),
					esc_html( number_format_i18n( $page ) )
				);
				echo ' ';
				$why = array();
				if ( $unserved > 0 ) {
					/* translators: %s: number of images */
					$why[] = sprintf( _n( '%s is delivered by WordPress: the month’s allowance of new images ran out before it.', '%s are delivered by WordPress: the month’s allowance of new images ran out before them.', $unserved, 'nimbocdn' ), number_format_i18n( $unserved ) );
				}
				if ( $missing > 0 ) {
					/* translators: %s: number of images */
					$why[] = sprintf( _n( '%s did not answer during the measurement and is not counted in the percentage.', '%s did not answer during the measurement and are not counted in the percentage.', $missing, 'nimbocdn' ), number_format_i18n( $missing ) );
				}
				self::help( implode( ' ', $why ) );
				echo ' <span class="nimbo-sep">&middot;</span> ';
			}
			if ( ! empty( $probe['stale'] ) && (int) $probe['error_at'] > 0 ) {
				printf(
					/* translators: 1: time since the successful measurement, 2: time since the failed attempt */
					esc_html__( 'measured %1$s ago — the attempt %2$s ago could not reach the site', 'nimbocdn' ),
					esc_html( human_time_diff( (int) $probe['at'] ) ),
					esc_html( human_time_diff( (int) $probe['error_at'] ) )
				);
			} elseif ( ! empty( $probe['provisional'] ) ) {
				echo '<span class="nimbo-spin" aria-hidden="true"></span> ';
				esc_html_e( 'first pass — still completing', 'nimbocdn' );
				if ( ! empty( $probe['stage'] ) ) {
					echo ' <span class="nimbo-sep">&middot;</span> ' . esc_html( self::progress_line( $probe ) );
				}
			} elseif ( (int) $probe['at'] > 0 ) {
				printf(
					/* translators: %s: human readable time difference, for example "20 minutes" */
					esc_html__( 'measured one by one %s ago', 'nimbocdn' ),
					esc_html( human_time_diff( (int) $probe['at'] ) )
				);
				echo ' ';
				self::help( __( 'Visitor data takes up to a minute to arrive.', 'nimbocdn' ) );
			} else {
				esc_html_e( 'never measured', 'nimbocdn' );
			}
			echo '</span>';
			$left = self::cooldown_left();
			if ( $left > 0 ) {
				printf(
					'<span class="nimbo-action"><button type="button" class="button nimbo-cooldown" disabled data-cooldown="%d">%s</button></span>',
					(int) $left,
					/* translators: %s: seconds */
					esc_html( sprintf( __( 'Wait %ss', 'nimbocdn' ), number_format_i18n( $left ) ) )
				);
			} else {
				self::action_button( 'nimbocdn_recheck', _x( 'Measure now', 'button: measure again now', 'nimbocdn' ) );
			}
			?>
		</div>
		<?php
	}

	private static function explain( array $probe ) {
		if ( 'paused' === $probe['state'] ) {
			return __( 'WordPress is delivering your images, exactly as before NimboCDN. Nothing broke and no file was modified.', 'nimbocdn' );
		}
		if ( 'no_images' === $probe['state'] ) {
			return __( 'Your home page has no images to measure yet.', 'nimbocdn' );
		}
		if ( 'fallback' === $probe['state'] ) {
			$reasons = array(
				'bad-signature'         => __( 'The site credentials no longer match. Press Measure now; if it persists, get in touch.', 'nimbocdn' ),
				'unknown-tenant'        => __( 'This site is not registered with the service. Press Measure now.', 'nimbocdn' ),
				'origin-not-registered' => __( 'Images are served from a different domain than the one registered.', 'nimbocdn' ),
				'not-in-free-scope'     => __( 'The service has not registered your home page images yet. Press Measure now.', 'nimbocdn' ),
			);
			return isset( $reasons[ $probe['detail'] ] )
				? $reasons[ $probe['detail'] ]
				: __( 'Your store keeps working and every image loads — WordPress is delivering the originals.', 'nimbocdn' );
		}
		if ( 'error' === $probe['state'] ) {
			if ( 'http_request_failed' === $probe['detail'] ) {
				return __( 'Your site could not reach itself to be measured, which happens on hosting with few PHP workers. Your images are not affected — only this measurement. Try again in a moment, or wait for the scheduled check.', 'nimbocdn' );
			}
			if ( 'not-configured' === $probe['detail'] ) {
				$why = (string) get_option( 'nimbocdn_activation_error', '' );
				if ( 'not-verified' === $why ) {
					return __( 'This domain is already registered with the service, and to hand its credentials back the service must confirm this site is yours, which it could not do yet. It retries by itself; press Measure now to try again. Sites behind a password or on localhost cannot be verified.', 'nimbocdn' );
				}
				if ( 'rate-limited' === $why ) {
					return __( 'Too many registration attempts for this domain. Wait an hour and press Measure now.', 'nimbocdn' );
				}
				return __( 'Not connected to the service yet. Press Measure now, or wait for the automatic check.', 'nimbocdn' );
			}
			if ( 'timeout' === $probe['detail'] ) {
				return __( 'The background measurement did not finish, which usually means scheduled tasks are disabled on this site. Press Measure now.', 'nimbocdn' );
			}
			return __( 'The measurement could not be completed. Your images are not affected.', 'nimbocdn' );
		}
		return __( 'Press Measure now to see what your visitors are actually receiving.', 'nimbocdn' );
	}

	private static function site_url() {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$lang = strtolower( substr( (string) $locale, 0, 2 ) );
		if ( 'es' === $lang ) {
			return 'https://nimbocdn.net/es/';
		}
		if ( 'pt' === $lang ) {
			return 'https://nimbocdn.net/pt/';
		}
		return 'https://nimbocdn.net/';
	}

	private static function plan_ends_at() {
		$a = Settings_Store::account();
		return isset( $a['cancelling'] ) && is_string( $a['cancelling'] ) ? $a['cancelling'] : '';
	}

	private static function render_plan( $is_pro, array $home, array $images, $library, array $grant = array(), array $probe = array() ) {
		if ( $is_pro ) {
			$granted = ! empty( $grant['active'] );
			$ends    = $granted ? '' : self::plan_ends_at();
			?>
			<?php
			if ( '' !== $ends ) :
				?>
				<div class="nimbo-plan-ending">
					<span class="nimbo-plan-ending-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
					</span>
					<span>
						<b><?php esc_html_e( 'Subscription cancelled.', 'nimbocdn' ); ?></b>
						<?php
						printf(
							/* translators: %s: date the paid period ends */
							esc_html__( 'Pro stays on until %s, and then this site returns to the free plan.', 'nimbocdn' ),
							'<b>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $ends ) ) ) . '</b>'
						);
						?>
					</span>
				</div>
			<?php endif; ?>
			<ul class="nimbo-benefits nimbo-benefits-row">
				<li><?php esc_html_e( 'Whole site optimized', 'nimbocdn' ); ?></li>
				<li>
										<?php esc_html_e( 'Format', 'nimbocdn' ); ?>
					<span class="nimbo-tag avif nimbo-tip" tabindex="0" data-tip="<?php esc_attr_e( 'The most modern and efficient format for images.', 'nimbocdn' ); ?>">AVIF</span>
				</li>
				<li><?php esc_html_e( 'No image limit', 'nimbocdn' ); ?></li>
			</ul>
			<p class="nimbo-hint">
				<?php
				printf(
					/* translators: %s: images optimized */
					esc_html( _n( '%s image optimized and served from our global network.', '%s images optimized and served from our global network.', (int) $images['count'], 'nimbocdn' ) ),
					esc_html( number_format_i18n( (int) $images['count'] ) )
				);
				?>
			</p>
			<?php
			if ( $granted ) :
				?>
				<p class="nimbo-granted">
					<?php self::handshake(); ?>
					<span>
					<?php
					if ( '' !== $grant['by'] ) {
						printf(
							/* translators: %s: the name of the agency or partner */
							esc_html__( 'Pro plan provided by %s.', 'nimbocdn' ),
							'<b>' . esc_html( $grant['by'] ) . '</b>'
						);
					} else {
						esc_html_e( 'Pro plan provided at no cost to you.', 'nimbocdn' );
					}
					if ( '' !== $grant['expires_at'] ) {
						echo ' ';
						printf(
							/* translators: %s: a date, for example "25 de novembro de 2026" */
							esc_html__( 'Included until %s.', 'nimbocdn' ),
							esc_html( date_i18n( self::date_format(), strtotime( $grant['expires_at'] . ' UTC' ) ) )
						);
					}
					?>
					</span>
				</p>
				<?php if ( '' !== $grant['expires_at'] && $grant['days_left'] <= 14 ) : ?>
					<p class="nimbo-granted-soon">
						<?php
						printf(
							/* translators: %s: number of days */
							esc_html( _n( 'It ends in %s day. After that this site returns to the free plan, and you can subscribe if you want to keep Pro.', 'It ends in %s days. After that this site returns to the free plan, and you can subscribe if you want to keep Pro.', (int) $grant['days_left'], 'nimbocdn' ) ),
							esc_html( number_format_i18n( (int) $grant['days_left'] ) )
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<?php
			$account  = Settings_Store::account();
			$interval = isset( $account['interval'] ) && 'year' === $account['interval'] ? 'year' : 'month';
			$renews   = isset( $account['renews_at'] ) && is_string( $account['renews_at'] ) ? $account['renews_at'] : '';
			$switch   = isset( $account['switching'] ) && is_array( $account['switching'] ) ? $account['switching'] : array();
			if ( ! $granted && '' === $ends ) :
				?>
				<p class="nimbo-plan-cycle">
					<?php
					if ( '' !== $renews ) {
						printf(
							'year' === $interval
								/* translators: %s: date of the next renewal */
								? esc_html__( 'Annual plan · renews on %s', 'nimbocdn' )
								/* translators: %s: date of the next renewal */
								: esc_html__( 'Monthly plan · renews on %s', 'nimbocdn' ),
							'<b>' . esc_html( date_i18n( self::date_format(), strtotime( $renews ) ) ) . '</b>'
						);
					} else {
						echo esc_html( 'year' === $interval ? __( 'Annual plan', 'nimbocdn' ) : __( 'Monthly plan', 'nimbocdn' ) );
					}
					?>
				</p>
				<?php
				if ( ! empty( $switch['to'] ) && ! empty( $switch['at'] ) ) :
					?>
					<p class="nimbo-plan-switching">
						<?php
						printf(
							/* translators: %s: date the current period ends */
							esc_html__( 'Switching to monthly on %s. Nothing is charged until then, and you keep everything you paid for.', 'nimbocdn' ),
							'<b>' . esc_html( date_i18n( self::date_format(), strtotime( (string) $switch['at'] ) ) ) . '</b>'
						);
						?>
					</p>
					<?php
				endif;
			endif;
			?>
			<?php if ( ! $granted ) : ?>
								<div class="nimbo-foot">
					<?php
					self::action_button(
						'nimbocdn_manage',
						'' !== $ends ? __( 'Reactivate subscription', 'nimbocdn' ) : __( 'Manage subscription', 'nimbocdn' )
					);

					if ( '' === $ends && empty( $switch['to'] ) ) {
						if ( 'month' === $interval ) {
							self::action_button(
								'nimbocdn_switch',
								__( 'Switch to annual', 'nimbocdn' ),
								'button-primary',
								'nimbocdn-switch',
								array( 'interval' => 'year' )
							);
						} else {
							self::action_button(
								'nimbocdn_switch',
								__( 'Switch to monthly at renewal', 'nimbocdn' ),
								'button-link',
								'nimbocdn-switch',
								array( 'interval' => 'month' )
							);
						}
					}
					?>
				</div>
			<?php endif; ?>
			<?php
			return;
		}

		$count = (int) $home['count'];
		$on_page  = ! empty( $probe['page'] ) ? (int) $probe['page'] : $count;
		$unserved = ! empty( $probe['unserved'] ) && 'serving' === $probe['state'] ? (int) $probe['unserved'] : 0;
		$outside  = max( 0, $library - max( $on_page, $count ) );
		$renews   = '' !== $home['renews_at'] ? strtotime( $home['renews_at'] ) : false;
		?>
		<div class="nimbo-free">
			<p class="nimbo-free-title">
				<?php if ( $unserved > 0 ) : ?>
					<?php esc_html_e( 'Your home page, optimized month by month', 'nimbocdn' ); ?>
				<?php else : ?>
					<span class="nimbo-check" aria-hidden="true">&#10003;</span>
					<?php esc_html_e( 'Your home page, optimized for good', 'nimbocdn' ); ?>
				<?php endif; ?>
			</p>
			<p class="nimbo-hint">
				<?php
				if ( $unserved > 0 ) {
					printf(
						/* translators: 1: images served, 2: images on the home page */
						esc_html__( '%1$s of the %2$s images of your home page, in WebP, from the global cache. The rest join as the allowance renews.', 'nimbocdn' ),
						esc_html( number_format_i18n( $count ) ),
						esc_html( number_format_i18n( $on_page ) )
					);
				} else {
					printf(
						/* translators: %s: number of images */
						esc_html( _n( '%s image of your home page, in WebP, from the global cache.', '%s images of your home page, in WebP, from the global cache.', $count, 'nimbocdn' ) ),
						esc_html( number_format_i18n( $count ) )
					);
				}
				?>
			</p>
			<p class="nimbo-cap">
				<?php
				if ( (int) $home['limit'] > 0 ) {
					printf(
						/* translators: 1: monthly allowance, 2: images admitted this month, 3: month name */
						esc_html__( 'up to %1$s new images per month on the home page · %2$s used in %3$s', 'nimbocdn' ),
						esc_html( number_format_i18n( (int) $home['limit'] ) ),
						esc_html( number_format_i18n( (int) $home['used'] ) ),
						esc_html( $renews ? date_i18n( 'F', $renews - DAY_IN_SECONDS ) : $home['month'] )
					);
					if ( $renews ) {
						echo ' ';
						/* translators: %s: date */
						self::help( sprintf( __( 'The monthly allowance renews on %s.', 'nimbocdn' ), date_i18n( self::date_format(), $renews ) ) );
					}
				}
				?>
			</p>
		</div>

		<div class="nimbo-pro">
			<p class="nimbo-pro-title"><span class="nimbo-pro-chip"><?php self::crown(); ?><b>Pro</b></span> <span><?php esc_html_e( 'your whole site optimized', 'nimbocdn' ); ?></span></p>
			<ul class="nimbo-benefits">
				<li><?php esc_html_e( 'Catalogue, products, blog and pages — not only the home page', 'nimbocdn' ); ?></li>
				<li>
					<?php esc_html_e( 'Format', 'nimbocdn' ); ?>
					<span class="nimbo-tag avif nimbo-tip" tabindex="0" data-tip="<?php esc_attr_e( 'The most modern and efficient format for images.', 'nimbocdn' ); ?>">AVIF</span>
					<?php
					if ( (int) $images['avif_pairs'] > 0 && (int) $images['webp_bytes'] > 0 && (float) $images['avif_bytes'] < (float) $images['webp_bytes'] ) {
						printf(
							/* translators: %s: percentage, for example "23%" */
							esc_html__( '%s smaller than WebP, measured on this site', 'nimbocdn' ),
							esc_html( number_format_i18n( round( ( 1 - (float) $images['avif_bytes'] / (float) $images['webp_bytes'] ) * 100 ) ) . '%' )
						);
					} else {
						esc_html_e( 'about 20% smaller than WebP', 'nimbocdn' );
					}
					?>
				</li>
				<li><?php esc_html_e( 'No image limit', 'nimbocdn' ); ?></li>
				<li><?php esc_html_e( 'Every image delivered by our global network', 'nimbocdn' ); ?></li>
			</ul>
			<?php if ( $outside > 0 && $library > 0 ) : ?>
				<div class="nimbo-pro-fact">
					<p class="nimbo-fact-text">
						<b>
						<?php
						printf(
							/* translators: %s: number of images */
							esc_html( _n( '%s image outside your home page still reaches your visitors heavy.', '%s images outside your home page still reach your visitors heavy.', $outside, 'nimbocdn' ) ),
							'<span class="nimbo-red">' . esc_html( number_format_i18n( $outside ) ) . '</span>'
						);
						?>
						</b>
						<span>
						<?php
						/* translators: %s: percentage of the media library */
						printf( esc_html__( 'That is %s of your site. With Pro, all of them would be optimized.', 'nimbocdn' ), esc_html( number_format_i18n( round( $outside / $library * 100 ) ) . '%' ) );
						?>
						</span>
					</p>
				</div>
			<?php endif; ?>
						<div class="nimbo-foot">
			<div class="nimbo-period-wrap">
								<p class="nimbo-period-title" id="nimbo-period-title"><?php esc_html_e( 'Choose how you pay', 'nimbocdn' ); ?></p>
				<div class="nimbo-period" role="radiogroup" aria-labelledby="nimbo-period-title">
										<input class="nimbo-period-in" type="radio" name="nimbocdn_period" id="nimbo-per-month" value="month" />
					<label class="nimbo-period-lb" for="nimbo-per-month"><?php esc_html_e( 'Monthly', 'nimbocdn' ); ?></label>
					<input class="nimbo-period-in" type="radio" name="nimbocdn_period" id="nimbo-per-year" value="year" checked />
					<label class="nimbo-period-lb" for="nimbo-per-year">
						<?php esc_html_e( 'Annual', 'nimbocdn' ); ?>
						<b class="nimbo-period-save" data-period-save><?php esc_html_e( '2 months free', 'nimbocdn' ); ?></b>
					</label>
				</div>
								<p class="nimbo-period-note" data-period-note aria-live="polite"></p>
			</div>
				<?php
				self::action_button(
					'nimbocdn_upgrade',
					__( 'Get Pro', 'nimbocdn' ),
					'button-primary',
					'nimbocdn-upgrade',
					array( 'interval' => 'year' )
				);
				?>
			</div>
		</div>
		<?php
	}

	private static function render_technical() {
		$creds = Settings_Store::credentials();
		$host  = Settings_Store::cdn_host();
		$email = get_option( 'nimbocdn_email', '' );
		if ( ! is_string( $email ) || '' === $email ) {
			$email = (string) get_option( 'admin_email', '' );
		}
		?>
		<details class="nimbo-card nimbo-details" data-key="account">
			<summary class="nimbo-head">
				<p class="nimbo-title"><?php esc_html_e( 'Your account', 'nimbocdn' ); ?></p>
			</summary>
			<dl class="nimbo-rows">
				<div class="nimbo-row">
					<dt><?php esc_html_e( 'Domain', 'nimbocdn' ); ?></dt>
					<dd><code><?php echo esc_html( '' !== $creds['domain'] ? $creds['domain'] : '—' ); ?></code></dd>
				</div>
				<div class="nimbo-row">
					<dt>
						<?php esc_html_e( 'Delivery', 'nimbocdn' ); ?>
						<?php
						self::help(
							sprintf(
								/* translators: 1: example hostname built from the site's domain, 2: email address */
								__( 'Would you like a custom delivery domain for your site, such as %1$s? Get in touch: %2$s', 'nimbocdn' ),
								'img.' . Signer::site_key( $creds['domain'] ),
								'hello@nimbocdn.net'
							)
						);
						?>
					</dt>
					<dd><code><?php echo esc_html( '' !== $host ? $host : '—' ); ?></code></dd>
				</div>
				<div class="nimbo-row">
					<dt>
						<?php esc_html_e( 'Billing email', 'nimbocdn' ); ?>
						<?php self::help( __( 'Where payment notices go: a declined card, a subscription about to lapse, receipts. You can use the same address on more than one site.', 'nimbocdn' ) ); ?>
					</dt>
					<dd>
						<input type="email" name="nimbocdn_email" class="regular-text" form="nimbo-account-form"
							value="<?php echo esc_attr( $email ); ?>"
							aria-label="<?php esc_attr_e( 'Billing email', 'nimbocdn' ); ?>" />
					</dd>
				</div>
				<?php self::render_marketing_row(); ?>
			</dl>
						<form method="post" id="nimbo-account-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nimbo-foot">
				<input type="hidden" name="action" value="nimbocdn_account" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Save', 'nimbocdn' ); ?></button>
			</form>
		</details>
		<?php
	}

	private static function render_marketing_row() {
		$account = Settings_Store::account();
		$local   = Settings_Store::marketing();
		$opt_out = isset( $account['marketing_opt_out'] ) ? (bool) $account['marketing_opt_out'] : $local['opt_out'];

		if ( ! $local['notice'] ) {
			Settings_Store::save_marketing( array( 'notice' => true ) );
		}
		?>
		<div class="nimbo-row">
			<dt>
				<?php esc_html_e( 'Product emails', 'nimbocdn' ); ?>
								<?php self::help( __( 'Occasional emails about NimboCDN: new features, and tips to get more out of it. Never more than a couple a month, and we never share your data with third parties. Payment notices are separate and always arrive — they are part of the service.', 'nimbocdn' ) ); ?>
			</dt>
			<dd>
								<label class="nimbo-optin">
										<input type="hidden" name="nimbocdn_marketing" value="0" form="nimbo-account-form" />
					<input type="checkbox" name="nimbocdn_marketing" value="1" form="nimbo-account-form" <?php checked( ! $opt_out ); ?> />
					<span><?php esc_html_e( 'Send me occasional emails about NimboCDN', 'nimbocdn' ); ?></span>
				</label>
			</dd>
		</div>
		<?php
	}

	private static function why_safe() {
		?>
		<details class="nimbo-why-safe">
			<summary><?php self::icon( 'shield-check' ); ?><span><?php esc_html_e( 'What if something goes wrong?', 'nimbocdn' ); ?></span></summary>
			<p><?php esc_html_e( 'Your original files are never altered. If an image cannot be optimized, the visitor gets the original: your site never breaks.', 'nimbocdn' ); ?></p>
		</details>
		<?php
	}

	private static function percent_lighter( $before, $after ) {
		$cut = $before > 0 ? 100 - (int) round( $after / $before * 100 ) : 0;
		/* translators: %s: percentage */
		return sprintf( __( '%s%% lighter', 'nimbocdn' ), number_format_i18n( max( 0, $cut ) ) );
	}

	private static function measured_tag() {
		printf( '<span class="nimbo-est">%s</span>', esc_html__( 'measured', 'nimbocdn' ) );
	}

	private static function estimated_tag() {
		printf( '<span class="nimbo-est">%s</span>', esc_html__( 'estimated', 'nimbocdn' ) );
	}

	private static function format_name( $mime ) {
		if ( false !== strpos( $mime, 'avif' ) ) {
			return 'AVIF';
		}
		if ( false !== strpos( $mime, 'webp' ) ) {
			return 'WebP';
		}
		if ( false !== strpos( $mime, 'png' ) ) {
			return 'PNG';
		}
		return 'JPEG';
	}

	const TAG_HTML = array(
		'span' => array(
			'class'    => array(),
			'tabindex' => array(),
			'data-tip' => array(),
		),
	);

	private static function avif_tag() {
		return sprintf(
			'<span class="nimbo-tag avif nimbo-tip" tabindex="0" data-tip="%s">AVIF</span>',
			esc_attr__( 'The most modern and efficient format for images.', 'nimbocdn' )
		);
	}

	private static function brand_mark() {
		echo '<svg class="nimbo-brand-mark" viewBox="0 0 24 24" width="34" height="34"'
			. ' aria-hidden="true" focusable="false">'
			. '<mask id="nimbocdn-mark"><rect width="24" height="24" fill="#fff"/>'
			. '<rect x="5" y="5.5" width="14" height="8.84" rx="1.6" fill="#000"/>'
			. '<circle cx="16.3" cy="8.5" r="1.2" fill="#fff"/>'
			. '<path d="M6.6 13.1L10.4 8.9L13.2 13.1Z" fill="#fff"/>'
			. '<path d="M11.4 13.1L14.4 10L17.4 13.1Z" fill="#fff"/>'
			. '<rect x="3.2" y="17" width="17.6" height="1.5" rx=".75" fill="#000"/></mask>'
			. '<rect width="24" height="24" fill="#fff" mask="url(#nimbocdn-mark)"/>'
			. '</svg>';
	}

	private static function crown() {
		// `crown` from Tabler Icons (MIT License, Copyright (c) 2020-2026 Paweł Kuna) — see `readme.txt`.
		echo '<svg class="nimbo-crown" viewBox="0 0 24 24" aria-hidden="true" focusable="false"'
			. ' fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">'
			. '<path d="M12 6l4 6l5 -4l-2 10h-14l-2 -10l5 4l4 -6"/>'
			. '</svg>';
	}

	private static function date_format() {
		return _x( 'F j, Y', 'date format shown in the panel', 'nimbocdn' );
	}

	/**
	 * Two hands shaking inside a heart: a plan granted by a partner.
	 *
	 * `heart-handshake` from Tabler Icons (MIT License, Copyright (c) 2020-2026 Paweł Kuna) — see `readme.txt`.
	 * The path is inlined rather than shipped as a file: there are two icons in the whole
	 * panel, and an `<img>` would cost a request per page load for 400 bytes of path.
	 *
	 * A handshake rather than another crown: the crown already says "Pro" in the badge
	 * beside it. What this line tells is not which plan the site has but where it came
	 * from — a relationship with someone, not a purchase.
	 *
	 * 28 px and not 20: the inner detail of this icon smears below ~26 px. Checked by
	 * rendering it.
	 */
	private static function handshake() {
		echo '<svg class="nimbo-handshake" viewBox="0 0 24 24" aria-hidden="true" focusable="false"'
			. ' fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
			. '<path d="M19.5 12.572l-7.5 7.428l-7.5 -7.428a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572"/>'
			. '<path d="M12 6l-3.293 3.293a1 1 0 0 0 0 1.414l.543 .543c.69 .69 1.81 .69 2.5 0l1 -1a3.182 3.182 0 0 1 4.5 0l2.25 2.25"/>'
			. '<path d="M12.5 15.5l2 2"/>'
			. '<path d="M15 13l2 2"/>'
			. '</svg>';
	}

	private static function help( $text, $css_class = '' ) {
		printf(
			'<span class="nimbo-help nimbo-tip %s" tabindex="0" role="img" aria-label="%s" data-tip="%s">i</span>',
			esc_attr( $css_class ),
			esc_attr( $text ),
			esc_attr( $text )
		);
	}

	private static function styles() {
		return <<<'CSS'
		.nimbocdn {
			--nb-line:#c3c4c7; --nb-rule:#e4e5e7; --nb-ink:#1d2327; --nb-muted:#646970;
			--nb-blue:var(--wp-admin-theme-color, #2271b1);
			--nb-green:#00a32a; --nb-green-ink:#007017; --nb-red:#d63638;
			--nb-brand:#7A0F5C; --nb-brand-ink:#63144A; --nb-brand-deep:#5C1246;
			--nb-brand-wash:#FBF0F7; --nb-brand-border:#E7C4DA; --nb-lime:#C8F53C;
			--nb-fmt-avif:#5C1246; --nb-fmt-webp:#8F2570;
			--nb-fmt-jpeg:#BF64A2; --nb-fmt-png:#D89BC2;
			--nb-w-main:52rem;
			--nb-w-side:21rem;
			--nb-gap:1.25rem;
			--nb-w-layout:var(--nb-w-main);
			container:nimbo-panel / inline-size;
		}
		.nimbo-brand { box-sizing:border-box;
			display:flex; align-items:center; gap:.7rem; max-width:var(--nb-w-layout);
			margin:.4rem 0 1.15rem; padding:.85rem 1.1rem; border-radius:4px;
			background-color:var(--nb-brand);
			background-image:url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='22' height='14'><rect x='0' y='3' width='16' height='2' rx='1' fill='%23C8F53C' opacity='.13'/><rect x='0' y='9' width='10.88' height='2' rx='1' fill='%23C8F53C' opacity='.08'/></svg>");
			background-size:44px 28px; background-repeat:repeat;
			border-bottom:3px solid var(--nb-lime); }
		.nimbo-brand-mark { display:block; flex:none; border-radius:3px; }
		.nimbo-brand-home { margin-left:auto; flex:none; display:inline-flex; align-items:center;
			justify-content:center; width:34px; height:34px; border-radius:4px;
			color:#fff; opacity:.75; text-decoration:none; transition:opacity .12s, background-color .12s; }
		.nimbo-brand-home:hover, .nimbo-brand-home:focus { color:#fff; opacity:1; background-color:rgba(255,255,255,.14); }
		.nimbo-brand-home:focus-visible { outline:2px solid var(--nb-lime); outline-offset:1px; }
		.nimbocdn :where(.notice, .updated, .error, .notice-alt) {
			box-sizing:border-box; max-width:var(--nb-w-layout); }
		.nimbo-brand h1 { margin:0; padding:0; font-size:19px; line-height:1.2; font-weight:600;
			letter-spacing:-.01em; color:#fff; }
		@media (max-width:782px) { .nimbo-brand { margin-top:0; } }
		.nimbo-card { container:nimbo-card / inline-size; }
		.nimbo-card { background:#fff; border:1px solid var(--nb-line); margin:0 0 1rem;
			padding:1.1rem 1.25rem; display:flex; flex-direction:column; gap:.75rem; max-width:52rem; }
		.nimbo-head { display:flex; align-items:center; justify-content:space-between;
			gap:1rem; flex-wrap:wrap; }
		.nimbo-title { margin:0; font-size:11px; font-weight:600; color:var(--nb-muted);
			text-transform:uppercase; letter-spacing:.06em; }
		.nimbo-label { margin:0; font-size:11px; color:var(--nb-muted); text-transform:uppercase;
			letter-spacing:.06em; }

		.nimbo-hero-card.nimbo-paused > :not(.nimbo-head) { filter:grayscale(1); opacity:.6; }
		.nimbo-paused-tag { font-size:12px; color:var(--nb-muted); }
		.nimbo-network.nimbo-paused .nimbo-map-wrap { filter:grayscale(1); opacity:.6; }
		.nimbo-head-right { display:flex; align-items:center; gap:.9rem; flex-wrap:wrap; justify-content:flex-end; }
		.nimbo-head-right .nimbo-action { margin:0; }
		.nimbo-pause.button-link, .nimbo-pause.button-link:hover, .nimbo-pause.button-link:focus, .nimbo-pause.button-link:active {
			border:0; background:transparent; box-shadow:none; outline:none; border-radius:0;
			font-size:12px; text-decoration:underline; text-decoration-color:var(--nb-line);
			padding:0; height:auto; line-height:1.4; }
		.nimbo-pause.button-link { color:var(--nb-muted); }
		.nimbo-pause.button-link:hover { color:var(--nb-ink); }
		.nimbo-pause.button-link:focus { color:var(--nb-ink); text-decoration-color:currentColor; text-decoration-thickness:2px; text-underline-offset:2px; }
		.nimbo-status { display:inline-flex; align-items:center; gap:.45rem; font-size:13px;
			color:var(--nb-muted); }
		.nimbo-status b { font-weight:600; color:var(--nb-ink); }
		.nimbo-pulse { position:relative; width:8px; height:8px; border-radius:50%; flex:none; }
		.nimbo-pulse.ok { background:var(--nb-green); }
		.nimbo-pulse.warn { background:var(--nb-red); }
		.nimbo-pulse.idle { background:var(--nb-muted); }
		.nimbo-pulse.busy { background:var(--nb-blue); }
		.nimbo-pulse.ok::after, .nimbo-pulse.busy::after { content:""; position:absolute; inset:-3px;
			border-radius:50%; border:1px solid currentColor; color:var(--nb-green);
			animation:nimbo-beat 2.4s ease-out infinite; }
		.nimbo-pulse.busy::after { color:var(--nb-blue); animation-duration:1.2s; }
		@keyframes nimbo-beat {
			0% { opacity:.7; transform:scale(.6); }
			70%,100% { opacity:0; transform:scale(1.5); }
		}
		.nimbo-spin { display:inline-block; width:14px; height:14px; margin:0 .5rem 0 0;
			border:2px solid var(--nb-line); border-top-color:var(--nb-blue); border-radius:50%;
			animation:nimbo-turn .9s linear infinite; vertical-align:-1px; }
		.nimbo-measuring { display:flex; align-items:center; gap:.5rem; }
		.nimbo-measuring .nimbo-spin { margin:0; flex:none; vertical-align:baseline; }
		@keyframes nimbo-turn { to { transform:rotate(360deg); } }
		@media (prefers-reduced-motion: reduce) {
			.nimbo-pulse::after { animation:none; opacity:.35; }
			.nimbo-spin { animation:none; }
		}

		.nimbo-hero { display:flex; align-items:stretch; gap:1rem 2rem; flex-wrap:wrap; }
		.nimbo-hero-text { flex:1 1 16rem; min-width:0; }
		.nimbo-kicker { margin:0; font-size:13px; color:var(--nb-muted); }
		.nimbo-big { margin:.1rem 0 .2rem; font-size:34px; line-height:1.15;
			font-variant-numeric:tabular-nums; display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; }
		.nimbo-big b { font-weight:600; color:var(--nb-ink); }
		.nimbo-big b.nimbo-gain { color:var(--nb-green-ink); }
		.nimbo-sub { margin:0 0 .35rem; font-size:15px; font-variant-numeric:tabular-nums; }
		.nimbo-sub .was { color:var(--nb-muted); text-decoration:line-through;
			text-decoration-thickness:1px; text-decoration-color:#b0b5ba; margin-right:.4rem; }
		.nimbo-sub .arw { color:var(--nb-line); margin-right:.4rem; }
		.nimbo-sub .now { font-weight:600; }
		.nimbo-sub .cut { color:var(--nb-green-ink); font-weight:600; margin-left:.3rem; }
		.nimbo-spark { width:100%; height:100%; min-height:36px; fill:var(--nb-green); opacity:.85; display:block; }
		.nimbo-spark g:hover rect:last-child { fill:var(--nb-green-ink); }
		.nimbo-spark-wrap { position:relative; flex:1 1 12rem; min-width:min(12rem, 100%); max-width:44rem; display:flex; align-items:stretch; min-height:36px; }
		.nimbo-spark-empty { position:absolute; inset:0 0 10px; display:flex; align-items:center; justify-content:center;
			font-size:12px; color:var(--nb-muted); text-align:center; pointer-events:none; padding:0 .5rem; }
		.nimbo-spark-tip { position:fixed; transform:translateX(-50%);
			padding:.35rem .55rem; background:#1d2327; color:#fff; font-size:12px; line-height:1.3;
			border-radius:4px; white-space:nowrap; pointer-events:none; box-shadow:0 4px 14px rgba(0,0,0,.22);
			opacity:0; visibility:hidden; transition:opacity .1s; z-index:100000; }
		.nimbo-spark-tip.on { opacity:1; visibility:visible; }
		.nimbo-explain { max-width:64ch; }

		.nimbo-tiles { display:flex; gap:.6rem;
			padding-top:.75rem; border-top:1px solid var(--nb-rule); }
		.nimbo-tile { display:flex; flex-direction:column; gap:.1rem; padding:.55rem .7rem;
			flex:1 1 0;
			border:1px solid var(--nb-rule); border-radius:4px; background:#f9f9fa; min-width:0; }
		.nimbo-tile-label { display:flex; align-items:center; justify-content:space-between; gap:.4rem; }
		.nimbo-tile-value { font-size:20px; font-weight:600; color:var(--nb-ink);
			font-variant-numeric:tabular-nums; line-height:1.2; }
		.nimbo-tile-label { font-size:11px; color:var(--nb-muted); }

		.nimbo-formats { display:flex; flex-direction:column; gap:.4rem; padding-top:.75rem;
			border-top:1px solid var(--nb-rule); }
		.nimbo-share { display:flex; height:22px; gap:2px; }
		.nimbo-share-seg { display:flex; align-items:center; justify-content:center; min-width:0;
			font-size:11px; font-weight:600; color:#fff; white-space:nowrap; border-radius:2px; }
		.nimbo-share-seg > span { overflow:hidden; text-overflow:clip; padding:0 .3rem; min-width:0; }
		.nimbo-share-seg.avif { background:var(--nb-fmt-avif); color:#fff; }
		.nimbo-share-seg.webp { background:var(--nb-fmt-webp); color:#fff; }
		.nimbo-share-seg.jpeg { background:var(--nb-fmt-jpeg); color:var(--nb-ink); }
		.nimbo-share-seg.png  { background:var(--nb-fmt-png);  color:var(--nb-ink); }
		.nimbo-share-seg:focus-visible { outline:2px solid var(--nb-blue); outline-offset:-2px; }
		.nimbo-legend { display:flex; flex-wrap:wrap; gap:.3rem 1.1rem; margin:0; padding:0;
			list-style:none; font-size:12px; color:var(--nb-muted); }
		.nimbo-legend li { display:inline-flex; align-items:center; gap:.35rem; }
		.nimbo-legend b { color:var(--nb-ink); font-weight:600; }
		.nimbo-legend i { width:9px; height:9px; border-radius:2px; display:inline-block; }
		.nimbo-legend i.avif { background:var(--nb-fmt-avif); }
		.nimbo-legend i.webp { background:var(--nb-fmt-webp); }
		.nimbo-legend i.jpeg { background:var(--nb-fmt-jpeg); }
		.nimbo-legend i.png  { background:var(--nb-fmt-png); }
		.nimbo-share-seg.nimbo-tip-end::after { left:auto; right:0; }
		.nimbo-js .nimbo-tip::after { display:none; }
		.nimbo-tooltip { position:fixed; z-index:100000; max-width:min(22rem, calc(100vw - 16px));
			padding:.5rem .65rem; background:#1d2327; color:#fff; font-size:12px; font-weight:400;
			line-height:1.45; border-radius:4px; box-shadow:0 4px 14px rgba(0,0,0,.22);
			pointer-events:none; opacity:0; visibility:hidden; transition:opacity .1s; }
		.nimbo-tooltip.on { opacity:1; visibility:visible; }
		.nimbo-pro-line { margin:.2rem 0 0; display:flex; align-items:flex-start; gap:.5rem; flex-wrap:nowrap;
			font-size:13px; font-weight:600; color:var(--nb-brand-ink); background:var(--nb-brand-wash); border:1px solid var(--nb-brand-border);
			border-radius:4px; padding:.5rem .7rem; }
		.nimbo-crown { width:17px; height:17px; display:block; flex:none; color:var(--nb-brand); }
		.nimbo-pro-line > span:not([class]) { min-width:0; }

		.nimbo-bars { display:grid; grid-template-columns:max-content minmax(6rem,1fr) max-content;
			align-items:center; gap:.35rem .7rem; }
		.nimbo-bar-row { display:contents; }
		.nimbo-bar-name { font-size:12px; font-weight:600; color:var(--nb-muted);
			display:flex; align-items:center; gap:.35rem; white-space:nowrap; }
		.nimbo-bar-row.here .nimbo-bar-name { color:var(--nb-green-ink); }
		.nimbo-track { height:14px; }
		.nimbo-track > i { display:block; height:100%; border-radius:2px; background:#c3c4c7; }
		.nimbo-bar-row.here .nimbo-track > i { background:var(--nb-green); }
		.nimbo-size { font-size:12px; font-variant-numeric:tabular-nums; text-align:right; white-space:nowrap; }
		.nimbo-size b.nimbo-cut { margin-left:.5rem; font-size:20px; font-weight:700;
			letter-spacing:-.01em; vertical-align:-1px; }
		.nimbo-size b { color:var(--nb-green-ink); margin-left:.3rem; }

		.nimbo-tag { display:inline-flex; align-items:center; gap:.25rem; height:17px;
			padding:0 .35rem; font-size:10px; font-weight:600; letter-spacing:.05em;
			text-transform:uppercase; border-radius:3px; border:1px solid #dcdcde;
			background:#fff; color:var(--nb-muted); }
		.nimbo-tag.mine { border-color:var(--nb-green); background:#edfaef; color:var(--nb-green-ink); }
		.nimbo-tag.avif { border-color:var(--nb-brand); background:#fff; color:var(--nb-brand-ink); cursor:help; }
		.nimbo-red { color:var(--nb-red); font-weight:700; font-variant-numeric:tabular-nums; }
		.nimbo-est { display:inline-block; font-size:10px; line-height:1.5; text-transform:uppercase;
			letter-spacing:.06em; color:var(--nb-muted); border:1px solid #dcdcde;
			border-radius:3px; padding:.05rem .35rem; font-weight:400; font-variant-numeric:normal; vertical-align:middle; }

		.nimbo-free { display:flex; flex-direction:column; gap:.25rem; }
		.nimbo-free-title { margin:0; font-size:15px; font-weight:600; color:var(--nb-ink);
			display:flex; align-items:center; gap:.5rem; }
		.nimbo-check { color:var(--nb-green-ink); font-weight:700; }
		.nimbo-cap { margin:0; font-size:11px; color:var(--nb-muted); }
		.nimbo-pro { border:1px solid var(--nb-brand-border); border-radius:4px; padding:.85rem 1rem;
			display:flex; flex-direction:column; gap:.5rem; }
		.nimbo-pro-title { margin:0; display:flex; align-items:center; gap:.5rem; font-size:15px;
			color:var(--nb-brand); flex-wrap:wrap; }
		.nimbo-pro-chip { display:inline-flex; align-items:center; gap:.4rem; flex:none;
			background:var(--nb-brand); border-radius:3px; padding:.22rem .5rem .22rem .42rem; }
		.nimbo-pro-chip b { font-size:14px; letter-spacing:.06em; font-weight:700; color:#fff; }
		.nimbo-pro-chip .nimbo-crown { width:15px; height:15px; color:var(--nb-lime); }
		.nimbo-pro-chip-solo { padding:.2rem .38rem; }
		.nimbo-pro-title > span:not(.nimbo-pro-chip) { color:var(--nb-ink); font-weight:700; }
		.nimbo-benefits { margin:0; padding:0; list-style:none; display:flex; flex-direction:column;
			gap:.25rem; font-size:13px; color:var(--nb-ink); }
		.nimbo-benefits li { padding-left:1.35em; text-indent:-1.35em; line-height:1.5; }
		.nimbo-benefits li::before { content:"\2713"; color:var(--nb-green-ink); font-weight:700; display:inline-block; width:1.35em; text-indent:0; }
		.nimbo-benefits li .nimbo-tag { text-indent:0; margin:0 .25em; }
		.nimbo-benefits-row { display:flex; flex-direction:row; gap:1.25rem; flex-wrap:wrap; font-weight:600; }
		.nimbo-benefits-row li { padding-left:0; text-indent:0; display:flex; align-items:center; gap:.5rem; }
		.nimbo-benefits-row li::before { width:auto; }
		.nimbo-card-pro, .nimbo-pro {
			background-color:var(--nb-brand-wash);
			background-image:url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='22' height='14'><rect x='0' y='3' width='16' height='2' rx='1' fill='%237A0F5C' opacity='.042'/><rect x='0' y='9' width='10.88' height='2' rx='1' fill='%237A0F5C' opacity='.026'/></svg>");
			background-size:44px 28px;
			background-repeat:repeat;
			border-color:var(--nb-brand-border); }
		.nimbo-card-pro .nimbo-title, .nimbo-pro .nimbo-pro-title { color:var(--nb-brand-ink); }
		.nimbo-card-pro .nimbo-hint, .nimbo-pro .nimbo-hint { color:#4A2E40; }
		.nimbo-card-pro .nimbo-benefits, .nimbo-pro .nimbo-benefits { color:var(--nb-ink); }
		.nimbo-card-pro .nimbo-foot, .nimbo-pro .nimbo-foot { border-top-color:rgba(120,90,0,.25); }
		.nimbo-card-pro .nimbo-tag.avif, .nimbo-pro .nimbo-tag.avif { background:#FEFAFC; }
		.nimbo-plan-badge { display:inline-flex; align-items:center; gap:.4rem; font-size:13px;
			font-weight:700; letter-spacing:.03em; color:var(--nb-brand-ink);
			background:var(--nb-brand-wash);
			border:1px solid var(--nb-brand); border-radius:3px; padding:.15rem .55rem; }
		.nimbo-plan-badge--free { background:#fff; color:var(--nb-brand); }
		.nimbo-granted { margin:0; font-size:13px; line-height:1.5; color:#4A2E40;
			display:flex; align-items:center; gap:.6rem;
			background:rgba(255,255,255,.62); border:1px solid var(--nb-brand-border); border-radius:4px;
			padding:.6rem .8rem; }
		.nimbo-granted b { font-weight:700; color:var(--nb-ink); }
		.nimbo-handshake { width:28px; height:28px; flex:none; color:var(--nb-brand-ink); }
		.nimbo-granted-soon { margin:0; font-size:13px; line-height:1.5; color:var(--nb-ink);
			background:rgba(255,255,255,.6); border-left:3px solid var(--nb-brand);
			padding:.45rem .7rem; border-radius:3px; }
		.nimbo-dunning { border:1px solid var(--nb-red); border-left-width:4px; border-radius:4px;
			background:#fff; padding:.7rem .9rem; display:flex; flex-direction:column;
			align-items:flex-start; gap:.4rem; }
		.nimbo-dunning-title { margin:0; font-weight:700; font-size:13px; color:var(--nb-red); }
		.nimbo-progress-line { margin:.2rem 0 .4rem; }
		.nimbo-progress-bar { display:block; width:min(100%, 28rem); margin:0 0 .5rem; }
		.nimbo-allowance { margin:.9rem 0 0; padding:.7rem .9rem; background:#fff; border:1px solid var(--nb-brand-border);
			border-left-width:4px; border-radius:4px; display:flex; flex-direction:column; gap:.35rem; align-items:flex-start; }
		.nimbo-allowance-title { margin:0; font-weight:700; font-size:13px; color:var(--nb-ink); }
		.nimbo-allowance-body { margin:0; font-size:13px; line-height:1.5; color:var(--nb-ink); max-width:64ch; }
		.nimbo-dunning-body { margin:0; font-size:13px; line-height:1.5; color:var(--nb-ink); }
		.nimbo-pro-fact { margin:0; padding:.7rem .9rem; background:#fff; border:1px solid var(--nb-brand-border);
			border-left:4px solid var(--nb-brand); border-radius:4px; box-shadow:0 1px 2px rgba(0,0,0,.05); }
		.nimbo-fact-text { margin:0; font-size:13px; line-height:1.5; color:var(--nb-ink);
			display:flex; flex-direction:column; gap:.15rem; }
		.nimbo-fact-text b { font-weight:600; }
		.nimbo-fact-text > span { color:var(--nb-muted); }
		.nimbo-fact-text .nimbo-red { font-size:15px; color:var(--nb-brand-ink); }
		.nimbo-pro .nimbo-foot { border-top-color:var(--nb-brand-border); }

		.nimbo-hint { margin:0; font-size:13px; color:var(--nb-muted); max-width:64ch; }
		.nimbo-meta { margin:0; font-size:12px; color:var(--nb-muted); display:flex;
			align-items:center; gap:.5rem; flex-wrap:wrap; padding-top:.55rem;
			border-top:1px solid var(--nb-rule); }
		.nimbo-sep { color:var(--nb-line); }
		.nimbo-meta-text { min-width:0; }
		.nimbo-action { margin:0; }
		.nimbo-cooldown { min-width:9.5rem; text-align:center; font-variant-numeric:tabular-nums; }
		.nimbo-meta .nimbo-action { margin:0 0 0 auto; }
		.nimbo-foot { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;
			justify-content:flex-end; padding-top:.55rem; border-top:1px solid var(--nb-rule); }
		.nimbo-lead { margin:0 auto 0 0; font-size:12px; color:var(--nb-muted); max-width:42ch; }

		.nimbo-details { display:block; }
		.nimbo-details > summary { cursor:pointer; list-style:none; }
		.nimbo-details[open] > summary { margin-bottom:.75rem; }
		.nimbo-details > .nimbo-rows { margin-bottom:.75rem; }
		.nimbo-details > summary::-webkit-details-marker { display:none; }
		.nimbo-details > summary .nimbo-title::before { content:"\25B8"; display:inline-block;
			width:1em; color:var(--nb-muted); }
		.nimbo-details[open] > summary .nimbo-title::before { content:"\25BE"; }
		.nimbo-rows { display:flex; flex-direction:column; gap:.55rem; margin:0; }
		.nimbo-row { display:grid; grid-template-columns:9.5rem minmax(0,1fr); gap:.75rem; align-items:center; }
		.nimbo-row > dt { color:var(--nb-muted); display:flex; align-items:center; gap:.35rem; min-width:0; }
		.nimbo-row > dd { margin:0; min-width:0; }
		.nimbo-field { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; min-width:0; }
		.nimbo-plan-ending { display:flex; gap:.6rem; align-items:flex-start; margin:0 0 1rem;
			padding:.8rem .9rem; border:1px solid #F0C36D; border-left-width:4px;
			background:#FFF8E5; color:#5B4300; font-size:13.5px; line-height:1.55; border-radius:6px; }
		.nimbo-plan-ending b { color:#4A3600; }
		.nimbo-plan-ending-icon { flex:none; color:#B07D00; margin-top:1px; }

		.nimbo-plan-cycle { margin:0 0 .6rem; font-size:13px; color:var(--nb-muted); }
		.nimbo-plan-switching { margin:0 0 1rem; padding:.7rem .85rem; border-radius:6px;
			border:1px solid var(--nb-rule); background:var(--nb-brand-wash);
			font-size:13px; line-height:1.5; color:var(--nb-ink); }

		.nimbo-period-wrap { align-self:center; margin:0 auto 0 0; }
		.nimbo-period-title { margin:0 0 .45rem; font-size:12px; font-weight:600;
			letter-spacing:.04em; text-transform:uppercase; color:var(--nb-muted); }
		.nimbo-period { display:inline-flex; align-self:flex-start; align-items:center; gap:2px;
			padding:3px; border:1px solid var(--nb-rule); border-radius:999px;
			background:#fff; box-shadow:inset 0 1px 2px rgba(0,0,0,.04); }
		.nimbo-period-in { position:absolute; width:1px; height:1px; margin:-1px; padding:0;
			overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0; }
		.nimbo-period-lb { display:inline-flex; align-items:center; gap:.5rem; height:32px;
			padding:0 .95rem; border-radius:999px; font-size:13px; font-weight:600;
			color:var(--nb-muted); cursor:pointer; white-space:nowrap;
			transition:background .15s ease, color .15s ease; }
		.nimbo-period-lb:hover { color:var(--nb-ink); }
		.nimbo-period-in:checked + .nimbo-period-lb { background:var(--nb-brand); color:#fff; }
		.nimbo-period-in:focus-visible + .nimbo-period-lb { outline:2px solid var(--nb-brand);
			outline-offset:2px; }
		.nimbo-period-save { padding:2px .5rem; border-radius:999px; background:#E6F4EA;
			color:var(--nb-green-ink); font-size:11px; font-weight:700; letter-spacing:.01em; }
		.nimbo-period-in:checked + .nimbo-period-lb .nimbo-period-save { background:#fff;
			color:var(--nb-brand); }
		.nimbo-period-note { margin:.45rem 0 0; min-height:1.15em; font-size:12.5px;
			color:var(--nb-muted); }
		.nimbo-period-note b { color:var(--nb-ink); font-weight:600; }

		.nimbo-optin { display:flex; gap:.4rem; align-items:flex-start; flex:1 1 14rem; min-width:0; cursor:pointer; line-height:1.4; }
		.nimbo-optin input { margin:.15rem 0 0; flex:none; }

		.nimbo-tip { position:relative; cursor:help; }
		.nimbo-tip::after { content:attr(data-tip); position:absolute; left:0; top:calc(100% + 7px);
			z-index:20; width:max-content; max-width:20rem; padding:.5rem .65rem; background:#1d2327;
			color:#fff; font-size:12px; font-weight:400; letter-spacing:0; line-height:1.45;
			text-transform:none; text-align:left; border-radius:4px;
			box-shadow:0 4px 14px rgba(0,0,0,.22);
			opacity:0; visibility:hidden; pointer-events:none; }
		.nimbo-tip:hover::after, .nimbo-tip:focus-visible::after { opacity:1; visibility:visible; }
		.nimbo-tip:focus-visible { outline:2px solid var(--nb-blue); outline-offset:1px; }
		.nimbo-help { display:inline-flex; align-items:center; justify-content:center;
			width:15px; height:15px; border-radius:50%;
			border:1px solid var(--nb-line); background:#fff; color:var(--nb-muted);
			font-size:10px; line-height:1; flex:none; }
		.nimbo-help:hover { border-color:var(--nb-blue); color:var(--nb-blue); }

		.nimbo-note { margin:.75rem 0 0 .1rem; font-size:12px; color:var(--nb-muted); max-width:52rem; }

		.nimbo-layout { display:flex; flex-direction:column; gap:0; max-width:var(--nb-w-layout); }
		@container nimbo-panel (max-width:775.98px) {
			.nimbo-main, .nimbo-side, #nimbocdn-panel { display:contents; }
			.nimbo-hero-card { order:1; }
			#nimbocdn-why { order:2; }
			.nimbo-home-card { order:3; }
			#nimbocdn-plan { order:4; }
			.nimbo-network { order:5; }
			.nimbo-details { order:6; }
			.nimbo-support { order:7; }
			.nimbo-note { order:8; }
		}
		.nimbo-main, .nimbo-side { min-width:0; }
		.nimbo-network { gap:.6rem; }
		.nimbo-support { gap:.55rem; }
		.nimbo-support-line { margin:0; font-size:12.5px; line-height:1.5; color:var(--nb-muted); }
		.nimbo-support-mail { display:flex; align-items:center; gap:.55rem; padding:.55rem .7rem;
			background:var(--nb-brand-wash); border:1px solid var(--nb-brand-border);
			border-left:3px solid var(--nb-brand); border-radius:4px; text-decoration:none; }
		.nimbo-support-mail b { color:var(--nb-brand-ink); font-weight:600; font-size:13px; }
		.nimbo-support-mail .nimbo-icon { color:var(--nb-brand-ink); margin-top:0; }
		.nimbo-support-mail:hover b, .nimbo-support-mail:focus b { text-decoration:underline; }
		.nimbo-support-path a { font-weight:600; text-decoration:none; white-space:nowrap; }
		.nimbo-why { gap:.7rem; }
		.nimbo-why-lead { margin:0; font-size:13px; line-height:1.5; color:var(--nb-ink); }
		.nimbo-why-list { margin:0; padding:0; list-style:none; display:flex; flex-direction:column; gap:.55rem; }
		.nimbo-why-list li { display:flex; align-items:flex-start; gap:.55rem; font-size:12.5px; line-height:1.4; color:var(--nb-muted); }
		.nimbo-why-list li b { font-size:13px; color:var(--nb-ink); font-weight:600; }
		.nimbo-icon { flex:none; color:var(--nb-green-ink); margin-top:.1rem; }
		.nimbo-why-safe { font-size:12.5px; color:var(--nb-muted); line-height:1.45; }
		.nimbo-why-safe summary { display:flex; align-items:center; gap:.45rem; cursor:pointer; list-style:none; color:var(--nb-ink); font-weight:600; font-size:13px; }
		.nimbo-why-safe summary::-webkit-details-marker { display:none; }
		.nimbo-why-price { display:block; margin-top:.15rem; font-weight:700;
			font-size:14px; color:var(--nb-ink); }
		.nimbo-why-price:empty { display:none; }
		.nimbo-why-safe summary span::before { content:"\25B8"; display:inline-block; width:.9em; color:var(--nb-muted); }
		.nimbo-why-safe[open] summary span::before { content:"\25BE"; }
		.nimbo-why-safe summary .nimbo-icon { color:var(--nb-muted); margin-top:0; }
		.nimbo-why-safe p { margin:.4rem 0 0 1.75rem; }
		.nimbo-why-plans { margin:0; padding-top:.7rem; border-top:1px solid var(--nb-rule); display:grid; grid-template-columns:max-content 1fr; column-gap:.55rem; row-gap:.35rem; align-items:baseline; font-size:12.5px; }
		.nimbo-why-plans div { display:contents; }
		.nimbo-why-plans dt { margin:0; font-weight:600; color:var(--nb-ink); }
		.nimbo-why-plans dd { margin:0; color:var(--nb-muted); }
		.nimbo-why-price { font-weight:600; color:var(--nb-ink); white-space:nowrap; }
		.nimbo-why-foot { margin:0; display:flex; flex-direction:column; align-items:flex-end; gap:.4rem; font-size:12.5px; color:var(--nb-muted); line-height:1.45; }
		.nimbo-why-foot > span { align-self:stretch; padding:.55rem .7rem; background:var(--nb-brand-wash); border:1px solid var(--nb-brand-border);
			border-left:3px solid var(--nb-brand); border-radius:4px; color:#4A2E40; }
		.nimbo-why-foot b { color:var(--nb-ink); }
		.nimbo-why-foot a { white-space:nowrap; font-weight:600; text-decoration:none; }
		.nimbo-why-row { align-self:stretch; display:flex; flex-wrap:wrap; align-items:start;
			gap:.4rem 1rem;
			padding-top:.7rem; border-top:1px solid var(--nb-rule); }
		.nimbo-why-row > .nimbo-why-safe { flex:1 1 auto; min-width:0; }
		.nimbo-why-row > a { margin-left:auto; padding-top:.1rem; }
		.nimbo-why-row > .nimbo-why-pro { flex:0 0 100%; }
		.nimbo-why-safe > summary { white-space:nowrap; }
		.nimbo-why-safe[open] > p { max-width:68ch; }
		.nimbo-why-pro { display:inline-flex; color:var(--nb-brand-ink); font-weight:600;
			align-items:center; gap:.4rem; }
		.nimbo-map-wrap { position:relative; width:100%; max-width:30rem; }
		.nimbo-map { width:100%; aspect-ratio:1200 / 580; height:auto; display:block; }
		.nimbo-land circle { fill:var(--nb-line); }
		.nimbo-pops circle { fill:var(--nb-brand); }
		.nimbo-pops circle.nimbo-me { display:none; }
		.nimbo-you { position:absolute; transform:translate(-50%, -50%); }
		.nimbo-you[hidden] { display:none; }
		@container nimbo-panel (min-width:776px) {
			.nimbo-brand, .nimbo-layout,
			.nimbocdn :where(.notice, .updated, .error, .notice-alt) {
				--nb-w-layout:calc(var(--nb-w-main) + var(--nb-w-side) + var(--nb-gap)); }
			.nimbo-layout { display:grid;
				grid-template-columns:minmax(0,1fr) var(--nb-w-side);
				gap:var(--nb-gap); align-items:start; }
			.nimbo-main { max-width:var(--nb-w-main); }
			.nimbo-side { position:sticky; top:2.5rem; }
			.nimbo-map-wrap { max-width:none; }
		}


		.nimbo-net-line { margin:0; font-size:14px; font-weight:600; color:var(--nb-ink); line-height:1.45; }
		#nimbocdn-panel .nimbo-card { transition:opacity .2s ease; }
		#nimbocdn-panel.nimbo-busy .nimbo-card { opacity:.55; }
		.nimbo-inline-notice { max-width:52rem; }

		@container nimbo-card (max-width:769px) {
			.nimbo-tiles { flex-wrap:wrap; }
			.nimbo-tile { flex-basis:calc(50% - .3rem); }
		}
		@container nimbo-card (max-width:500px) {
			.nimbo-big { font-size:26px; }
			.nimbo-row { grid-template-columns:minmax(0,1fr); gap:.2rem; }
			.nimbo-bars { grid-template-columns:max-content 1fr; }
			.nimbo-bar-name { white-space:normal; }
			.nimbo-size { grid-column:2; text-align:left; }
		}
		@container nimbo-card (max-width:420px) {
			.nimbo-spark-wrap { flex-basis:100%; max-width:none; }
			.nimbo-bars { grid-template-columns:minmax(0,1fr); gap:.15rem; }
			.nimbo-bar-row + .nimbo-bar-row { margin-top:.5rem; }
			.nimbo-bar-name, .nimbo-track, .nimbo-size { grid-column:1; }
			.nimbo-size { white-space:normal; }
			.nimbo-size b.nimbo-cut { margin-left:.35rem; }
		}
		.nimbocdn .regular-text { max-width:100%; }
CSS;
	}
}
