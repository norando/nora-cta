<?php
/**
 * Plugin Name: nora-cta
 * Plugin URI:  https://norando.net/nora-cta
 * Description: Nora-CTA plugin create and manage simple call-to-action.
 * Version:     1.0.0
 * Author:      norando
 * Author URI:  https://norando.net
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nora-cta
 * Domain Path: /languages
 *
 * @package nora-cta
 * @author norando
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Nora_CTA' ) ) {
	class Nora_CTA {
		/**
		 * Init
		 */
		public function init() {
			// 表示
			add_action( 'init', array( __CLASS__, 'display_nora_cta' ) );

			// style
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'cta_style' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_style' ) );

			// 国際化対応
			add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

			// CTA作成
			add_action( 'init', array( __CLASS__, 'register_cta_post_type' ) );
			add_action( 'save_post_nora_cta', array( __CLASS__, 'save_meta_box' ) );

			// カテゴリー設定
			add_action( 'category_edit_form_fields', array( __CLASS__, 'edit_category_cta' ) );
			add_action( 'category_add_form_fields', array( __CLASS__, 'add_category_cta' ) );
			add_action( 'edit_terms', array( __CLASS__, 'save_category_cta' ) );
			add_action( 'create_term', array( __CLASS__, 'save_category_cta' ) );

			// 個別記事設定
			add_action( 'add_meta_boxes_post', array( __CLASS__, 'post_cta' ) );
			add_action( 'save_post_post', array( __CLASS__, 'save_post_cta' ) );
		}

		/**
		 * Set wp nonce.
		 */
		public function set_nonce() {
			wp_nonce_field( 'nora_cta_nonce_key', 'nora_cta_nonce' );
		}

		/**
		 * Check wp nonce.
		 *
		 * @param int $id post_id.
		 * @return bool
		 */
		public function check_nonce( $id ) {
			$nonce_name = 'nora_cta_nonce';
			$nonce_key  = 'nora_cta_nonce_key';
			if ( empty( $_POST[ $nonce_name ] ) ) {
				return false;
			}
			if ( ! check_admin_referer( $nonce_key, $nonce_name ) ) {
				return false;
			}
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return false;
			}
			if ( ! current_user_can( 'edit_post', $id ) && ! current_user_can( 'manage_categories', $id ) ) {
				return false;
			}
			return true;
		}

		// ------------------------------------------------------------
		// 表示
		// ------------------------------------------------------------

		/**
		 * 記事が表示するCTAのIDを取得
		 */
		public function get_nora_cta_id() {
			global $post;

			$cta_id = false;

			// デフォルト
			$default = get_option( 'nora_cta_default', true );
			if ( ! empty( $default ) ) {
				$cta_id = $default;
			}

			// カテゴリーCTA設定
			$terms = get_the_category( $post->ID );

			foreach ( $terms as $term ) {
				$term_cta   = get_term_meta( $term->term_id, 'nora_cta', true );
				$has_cta    = ! empty( $term_cta );
				$term_depth = count( get_ancestors( $term->term_id, 'category' ) );
				$cta_depth  = $term_depth;

				// 何も設定されていない場合、先祖カテゴリーの設定を使用する
				if ( empty( $term_cta ) ) {
					$ancestors = get_ancestors( $term->term_id, 'category' );
					foreach ( $ancestors as $ancestor_id ) {
						$term_cta = get_term_meta( $ancestor_id, 'nora_cta', true );
						$cta_depth--;
						if ( ! empty( $term_cta ) ) {
							break;
						}
					}
				}

				// CTAが設定されている場合
				if ( ! empty( $term_cta ) ) {
					if ( ! empty( $term_cta_id ) ) {
						// 記事にCTAが登録されたカテゴリーが複数設定されている場合
						if ( 'hide' == $term_cta ) {
							// 「表示しない」の場合は他に設定されたCTAが優先
							continue;
						}

						if ( $has_cta && $temp_term['has_cta'] ) {
							// 暫定CTAと現在のCTAの両方が先祖カテゴリーの設定を使用していない場合
							// カテゴリーの階層が深い方が優先（同じ場合は名前順）
							if ( $term_depth <= $temp_term['term_depth'] ) {
								continue;
							}
						} elseif ( ! $has_cta && $temp_term['has_cta'] ) {
							// 現在のCTAが先祖カテゴリーの設定を使用しており、暫定CTAは違う場合
							// 暫定CTAが優先
							continue;
						} elseif ( ! $has_cta && ! $temp_term['has_cta'] ) {
							// 暫定CTAと現在のCTAの両方が先祖カテゴリーの設定を使用している場合
							// CTAの設定されたカテゴリーの階層が深い方が優先（同じ場合は名前順）
							if ( $cta_depth <= $temp_term['cta_depth'] ) {
								continue;
							}
						}
					}

					$term_cta_id = $term_cta;
					$temp_term   = array(
						'has_cta'    => $has_cta,
						'term_depth' => $term_depth,
						'cta_depth'  => $cta_depth,
					);
				}
			}

			if ( ! empty( $term_cta_id ) ) {
				$cta_id = $term_cta_id;
			}

			// 個別記事CTA設定
			$post_cta_id = get_post_meta( $post->ID, 'nora_cta', true );
			if ( ! empty( $post_cta_id ) ) {
				$cta_id = $post_cta_id;
			}

			// 表示しない設定
			if ( 'hide' == $cta_id ) {
				return false;
			}

			$cta_id = apply_filters( 'nora_cta_id', $cta_id );

			return $cta_id;

		}

		/**
		 * CTAを作成
		 */
		public function render_nora_cta() {
			$display = apply_filters( 'nora_cta_display', is_single() );
			if ( ! $display ) {
				return;
			}

			$cta_id = self::get_nora_cta_id();

			if ( empty( $cta_id ) ) {
				return;
			}

			// エディタに内容がある場合は優先
			$cta_post = get_post( $cta_id );
			if ( ! empty( $cta_post->post_content ) ) {
				return wp_kses_post( $cta_post->post_content );
			}

			$cta = get_post_meta( $cta_id, 'nora_cta', true );
			if ( empty( $cta ) ) {
				return $content; }

			$cta_content = '<div class="nora-cta">';
			if ( ! empty( $cta['title'] ) ) {
				$cta_content .= '<p class="nora-cta_title">' . wp_kses_post( $cta['title'] ) . '</p>';
			}

			if ( ! empty( $cta['disc'] ) ) {
				$cta_content .= '<p class="nora-cta_disc">' . wp_kses_post( $cta['disc'] ) . '</p>';
			}

			if ( ! empty( $cta['btn-text'] ) && ! empty( $cta['url'] ) ) {
				$cta_content .= '<a href="' . esc_url( $cta['url'] ) . '" class="nora-cta_btn">' . wp_kses_post( $cta['btn-text'] ) . '</a>';
			}

			if ( ! empty( $cta['footer'] ) ) {
				$cta_content .= '<div class="nora-cta_footer">';
				$cta_content .= $cta['footer'];
				$cta_content .= '</div>';
			}

			$cta_content .= '</div>';

			apply_filters( 'nora_cta_content', $cta_content, $cta );

			return $cta_content;
		}

		/**
		 * CTAを表示
		 */
		public function display_nora_cta() {
			$hook     = apply_filters( 'nora_cta_hook', 'the_content' );
			$priority = apply_filters( 'nora_cta_hook_priority', 10 );
			if ( empty( $hook ) ) {
				return;
			}

			if ( 'the_content' == $hook ) {
				add_filter(
					$hook,
					function( $content ) {
						echo wp_kses_post( $content . self::render_nora_cta() );
					}
				);
			} else {
				add_action(
					$hook,
					function() {
						echo wp_kses_post( self::render_nora_cta() );
					},
					$priority
				);
			}
		}

		// ------------------------------------------------------------
		// style
		// ------------------------------------------------------------

		/**
		 * フロントのスタイル読み込み
		 */
		public function cta_style() {
			$css = 'css/nora-cta.css';
			wp_enqueue_style( 'nora-cta', plugins_url( $css, __FILE__ ), array(), filemtime( plugin_dir_path( __FILE__ ) . $css ) );
		}

		/**
		 * 管理画面のスタイル読み込み
		 */
		public function admin_style() {
			$css = 'css/admin.css';
			wp_enqueue_style( 'nora-cta-admin', plugins_url( $css, __FILE__ ), array(), filemtime( plugin_dir_path( __FILE__ ) . $css ) );
		}

		// ------------------------------------------------------------
		// 国際化対応
		// ------------------------------------------------------------

		/**
		 * Load languages
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'nora-cta', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}

		// ------------------------------------------------------------
		// CTA作成
		// ------------------------------------------------------------

		/**
		 * Register post type
		 */
		public function register_cta_post_type() {
			register_post_type(
				'nora_cta',
				array(
					'label'                => 'CTA',
					'public'               => false,
					'show_ui'              => true,
					'supports'             => array( 'title', 'editor' ),
					'register_meta_box_cb' => array( __CLASS__, 'add_cta_meta_box' ),
				)
			);
		}

		/**
		 * Add meta box
		 */
		public function add_cta_meta_box() {
			add_meta_box(
				'nroa_cta',
				__( 'CTA Setting', 'nora-cta' ),
				array( __CLASS__, 'meta_box_ui' ),
				'nora_cta',
				'advanced',
				'core'
			);
		}

		/**
		 * UI
		 */
		public function meta_box_ui() {
			global $post;
			self::set_nonce();
			$cta = get_post_meta( $post->ID, 'nora_cta', true );
			if ( ! empty( $cta ) ) {
				$title    = ( ! empty( $cta ) && $cta['title'] ) ? $cta['title'] : '';
				$disc     = ( ! empty( $cta ) && $cta['disc'] ) ? $cta['disc'] : '';
				$btn_text = ( ! empty( $cta ) && $cta['btn-text'] ) ? $cta['btn-text'] : '';
				$url      = ( ! empty( $cta ) && $cta['url'] ) ? $cta['url'] : '';
				$footer   = ( ! empty( $cta ) && $cta['footer'] ) ? $cta['footer'] : '';
			}

			$default = get_option( 'nora_cta_default', true );
			if ( empty( $default ) ) {
				_e( '* No default CTA', 'nora-cta' );
			}
			?>
			<ul class="nora-cta-meta">
				<li class="nora-cta-meta_item"><label class="nora-cta-meta_label" for="cta_title"><?php _e( 'main copy', 'nora-cta' ); ?></label><input type="text" name="nora_cta[title]" id="cta_title" value="<?php echo wp_kses_post( $title ); ?>"></li>
				<li class="nora-cta-meta_item"><label class="nora-cta-meta_label" for="cta_disc"><?php _e( 'description', 'nora-cta' ); ?></label><input type="text" name="nora_cta[disc]" id="cta_disc" value="<?php echo wp_kses_post( $disc ); ?>"></li>
				<li class="nora-cta-meta_item"><label class="nora-cta-meta_label" for="cta_btn-text"><?php _e( 'link text', 'nora-cta' ); ?></label><input type="text" name="nora_cta[btn-text]" id="cta_btn-text" value="<?php echo wp_kses_post( $btn_text ); ?>"></li>
				<li class="nora-cta-meta_item"><label class="nora-cta-meta_label" for="cta_url">URL</label><input type="url" name="nora_cta[url]" id="cta_url" value="<?php echo esc_url( $url ); ?>"></li>
				<li class="nora-cta-meta_item">
					<label class="nora-cta-meta_label" for="cta_footer"><?php _e( 'footer', 'nora-cta' ); ?></label>
					<textarea  name="nora_cta[footer]" id="cta_footer" rows="10"><?php echo esc_textarea( $footer ); ?></textarea>
				</li>
			</ul>
			<label for="nora_cta_default"><?php _e( 'Set as default CTA', 'nora-cta' ); ?><input type="checkbox" name="nora_cta_default" id="nora_cta_default" value="<?php echo $post->ID; ?>"<?php echo $default == $post->ID ? ' checked' : ''; ?>></label>
			<?php
		}

		/**
		 * Save
		 *
		 * @param int $post_id post_id.
		 */
		public function save_meta_box( $post_id ) {

			if ( ! self::check_nonce( $post_id ) ) {
				return;
			}

			$cta = ( isset( $_POST['nora_cta'] ) && $_POST['nora_cta'] ) ? $_POST['nora_cta'] : '';
			if ( ! empty( $cta['footer'] ) ) {
				$cta['footer'] = stripslashes( $cta['footer'] );
			}
			update_post_meta( $post_id, 'nora_cta', $cta );

			$default = ( isset( $_POST['nora_cta_default'] ) && $_POST['nora_cta_default'] ) ? $_POST['nora_cta_default'] : '';
			update_option( 'nora_cta_default', $default );
		}


		// ------------------------------------------------------------
		// カテゴリー設定
		// ------------------------------------------------------------

		/**
		 * カテゴリー編集画面にCTA設定を追加
		 *
		 * @param object $term : term object
		 */
		public function edit_category_cta( $term ) {
			self::set_nonce();
			$nora_cta      = get_term_meta( $term->term_id, 'nora_cta', true );
			$selected_hide = $nora_cta == 'hide' ? ' selected' : '';
			?>
			<tr class="form-field">
				<th scope="row"><label for="description"><?php _e( 'Category CTA', 'nora-cta' ); ?></label></th>
				<td>
					<select name="nora_category_cta" id="nora_category_cta">
						<option value=""><?php _e( 'not set', 'nora-cta' ); ?></option>
						<option value="hide"<?php echo $selected_hide; ?>><?php _e( 'hide', 'nora-cta' ); ?></option>
						<?php
						$args = array(
							'posts_per_page' => -1,
							'post_type'      => 'nora_cta',
						);
						$ctas = get_posts( $args );
						foreach ( $ctas as $cta ) {
							$selected = $nora_cta == $cta->ID ? ' selected' : '';
							echo '<option value="' . $cta->ID . '"' . $selected . '>' . esc_html( $cta->post_title ) . '</option>';
						}
						?>
					</select>
					<p class="description"><?php _e( 'Default CTA for category', 'nora-cta' ); ?></p>
				</td>
			</tr>
			<?php
		}

		/**
		 * カテゴリー編集画面にCTA設定を追加
		 */
		public function add_category_cta() {
			self::set_nonce();
			?>
			<div class="form-field term-description-wrap">
				<label for="description"><?php _e( 'Category CTA', 'nora-cta' ); ?></label>
				<select name="nora_category_cta" id="nora_category_cta">
					<option value=""><?php _e( 'not set', 'nora-cta' ); ?></option>
					<option value="hide"><?php _e( 'hide', 'nora-cta' ); ?></option>
					<?php
					$args = array(
						'posts_per_page' => -1,
						'post_type'      => 'nora_cta',
					);
					$ctas = get_posts( $args );
					foreach ( $ctas as $cta ) {
						echo '<option value="' . $cta->ID . '">' . esc_html( $cta->post_title ) . '</option>';
					}
					?>
				</select>
				<p class="description"><?php _e( 'Default CTA for category', 'nora-cta' ); ?></p>
			</div>
			<?php
		}

		/**
		 * Save
		 *
		 * @param int $term_id
		 */
		public function save_category_cta( $term_id ) {
			if ( ! self::check_nonce( $term_id ) ) {
				return; }

			if ( isset( $_POST['nora_category_cta'] ) ) {
				update_term_meta( $term_id, 'nora_cta', $_POST['nora_category_cta'] );
			}
		}

		// ------------------------------------------------------------
		// 個別記事設定
		// ------------------------------------------------------------

		/**
		 * 投稿ごとのCTA設定
		 */
		public function post_cta() {
			add_meta_box(
				'nora_cta',
				__( 'CTA Setting', 'nora-cta' ),
				array( __CLASS__, 'post_cta_setting' ),
				'post',
				'side',
				'default'
			);
		}

		/**
		 * 設定 UI
		 *
		 * @param object $post
		 */
		public function post_cta_setting( $post ) {
			self::set_nonce();
			$nora_cta      = get_post_meta( $post->ID, 'nora_cta', true );
			$selected_hide = $nora_cta == 'hide' ? ' selected' : '';
			?>
		<select name="nora_post_cta" id="nora_post_cta">
			<option value=""><?php _e( 'not set', 'nora-cta' ); ?></option>
			<option value="hide"<?php echo $selected_hide; ?>><?php _e( 'hide', 'nora-cta' ); ?></option>
			<?php
			$args = array(
				'posts_per_page' => -1,
				'post_type'      => 'nora_cta',
			);
			$ctas = get_posts( $args );
			foreach ( $ctas as $cta ) {
				$selected = $nora_cta == $cta->ID ? ' selected' : '';
				echo '<option value="' . $cta->ID . '"' . $selected . '>' . esc_html($cta->post_title) . '</option>';
			}
			?>
		</select>
			<?php
		}

		/**
		 * Save
		 *
		 * @param int $post_id
		 */
		public function save_post_cta( $post_id ) {
			if ( ! self::check_nonce( $post_id ) ) {
				return;
			}
			if ( isset( $_POST['nora_post_cta'] ) ) {
				update_post_meta( $post_id, 'nora_cta', $_POST['nora_post_cta'] );
			}
		}
	}
}

Nora_CTA::init();
