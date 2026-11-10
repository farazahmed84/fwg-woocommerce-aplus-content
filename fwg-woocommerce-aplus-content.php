<?php
/**
 * Plugin Name: A+ Content for WooCommerce
 * Plugin URI:  https://farazthewebguy.com/
 * Description: Assign an Elementor template to a WooCommerce product and display it below the product details.
 * Version:     1.0.0
 * Author:      Faraz The Web Guy
 * Author URI:  https://farazthewebguy.com/
 * Text Domain: fwg-woocommerce-aplus-content
 * Requires Plugins: woocommerce, elementor
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

final class FWG_WooCommerce_APlus_Content {
	private const META_KEY   = '_fwg_aplus_elementor_template_id';
	private const NONCE_NAME = '_fwg_aplus_nonce';
	private const NONCE_ACTION = 'fwg_save_aplus_template';

	public static function init(): void {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_product_meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_product_template' ) );
		add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'render_product_content' ), 14 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_plugin_action_link' ) );
	}

	public static function add_product_meta_box(): void {
		add_meta_box(
			'fwg-aplus-content',
			esc_html__( 'A+ Content', 'fwg-woocommerce-aplus-content' ),
			array( __CLASS__, 'render_product_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	public static function render_product_meta_box( WP_Post $post ): void {
		$current_id = absint( get_post_meta( $post->ID, self::META_KEY, true ) );
		$templates  = self::get_elementor_templates();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<p>
			<label for="fwg-aplus-template">
				<?php esc_html_e( 'Elementor template', 'fwg-woocommerce-aplus-content' ); ?>
			</label>
		</p>
		<select id="fwg-aplus-template" name="fwg_aplus_template_id" class="widefat">
			<option value=""><?php esc_html_e( 'No A+ content', 'fwg-woocommerce-aplus-content' ); ?></option>
			<?php foreach ( $templates as $template ) : ?>
				<option value="<?php echo esc_attr( $template->ID ); ?>" <?php selected( $current_id, $template->ID ); ?>>
					<?php echo esc_html( $template->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Displayed below the product tabs and before upsells or related products.', 'fwg-woocommerce-aplus-content' ); ?>
		</p>
		<?php if ( $current_id ) : ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $current_id . '&action=elementor' ) ); ?>">
					<?php esc_html_e( 'Edit selected template', 'fwg-woocommerce-aplus-content' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function save_product_template( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$template_id = isset( $_POST['fwg_aplus_template_id'] )
			? absint( wp_unslash( $_POST['fwg_aplus_template_id'] ) )
			: 0;

		if ( $template_id && self::is_valid_template( $template_id ) ) {
			update_post_meta( $post_id, self::META_KEY, $template_id );
			return;
		}

		delete_post_meta( $post_id, self::META_KEY );
	}

	public static function render_product_content(): void {
		if ( ! is_product() || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return;
		}

		$template_id = absint( get_post_meta( get_the_ID(), self::META_KEY, true ) );

		if ( ! self::is_valid_template( $template_id ) ) {
			return;
		}

		$content = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $template_id, true );

		if ( '' === trim( $content ) ) {
			return;
		}

		echo '<section class="fwg-product-aplus-content" aria-label="' . esc_attr__( 'Additional product information', 'fwg-woocommerce-aplus-content' ) . '">';
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor returns trusted rendered template markup.
		echo '</section>';
	}

	public static function enqueue_styles(): void {
		if ( ! is_product() ) {
			return;
		}

		$template_id = absint( get_post_meta( get_queried_object_id(), self::META_KEY, true ) );

		if ( ! self::is_valid_template( $template_id ) ) {
			return;
		}

		wp_enqueue_style(
			'fwg-woocommerce-aplus-content',
			plugins_url( 'assets/css/frontend.css', __FILE__ ),
			array(),
			'1.0.0'
		);
	}

	public static function add_plugin_action_link( array $links ): array {
		$templates_url = admin_url( 'edit.php?post_type=elementor_library&tabs_group=library' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $templates_url ) . '">' . esc_html__( 'A+ Templates', 'fwg-woocommerce-aplus-content' ) . '</a>'
		);

		return $links;
	}

	private static function get_elementor_templates(): array {
		return get_posts(
			array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_elementor_template_type',
						'value'   => array( 'section', 'container', 'page' ),
						'compare' => 'IN',
					),
				),
			)
		);
	}

	private static function is_valid_template( int $template_id ): bool {
		return $template_id > 0
			&& 'elementor_library' === get_post_type( $template_id )
			&& 'publish' === get_post_status( $template_id );
	}
}

FWG_WooCommerce_APlus_Content::init();

