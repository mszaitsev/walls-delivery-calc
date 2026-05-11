<?php
/**
 * Admin page.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Admin {
	private WDC_Logger $logger;

	public function __construct( WDC_Logger $logger ) {
		$this->logger = $logger;
	}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Walls Delivery Calc', 'walls-delivery-calc' ),
			esc_html__( 'Walls Delivery Calc', 'walls-delivery-calc' ),
			'manage_woocommerce',
			'wdc-delivery-calc',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'walls-delivery-calc' ) );
		}

		$tabs = array(
			'general'      => __( 'Общие настройки', 'walls-delivery-calc' ),
			'russian_post' => __( 'Почта России', 'walls-delivery-calc' ),
			'packaging'    => __( 'Упаковка', 'walls-delivery-calc' ),
			'countries'    => __( 'Страны', 'walls-delivery-calc' ),
			'logs'         => __( 'Логи', 'walls-delivery-calc' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Walls Delivery Calc', 'walls-delivery-calc' ); ?></h1>

			<p>
				<strong><?php echo esc_html__( 'WooCommerce:', 'walls-delivery-calc' ); ?></strong>
				<?php echo esc_html( class_exists( 'WooCommerce' ) ? __( 'active', 'walls-delivery-calc' ) : __( 'inactive', 'walls-delivery-calc' ) ); ?>
			</p>

			<?php wp_nonce_field( 'wdc_admin_page', 'wdc_admin_nonce' ); ?>

			<h2><?php echo esc_html__( 'Будущие вкладки', 'walls-delivery-calc' ); ?></h2>
			<ul>
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<li data-wdc-tab="<?php echo esc_attr( sanitize_key( $tab_key ) ); ?>">
						<?php echo esc_html( $tab_label ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
