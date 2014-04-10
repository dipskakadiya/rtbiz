<?php
/**
 * Don't load this file directly!
 */
if ( ! defined( 'ABSPATH' ) )
	exit;

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


/**
 * Description of class-rt-access-control
 *
 * @author udit
 */
if( ! class_exists('Rt_Access_Control') ) {

	/**
	 * Class Rt_Access_Control
	 *
	 * This class works as the base for all the permissions, user access for rtBiz family plugins.
	 * All the plugins including rtBiz will register with this class for the Access Control that it requires
	 * & from there Rt_Access_Controll will take it over.
	 *
	 * It will map a matrix for permissions for Plugin Modules => User Groups (Departments) and from there user access
	 * can be set as per requirement.
	 */
	class Rt_Access_Control {

		/**
		 * @var - These are more of the permission roles for the access control.
		 */
		public static $permissions;

		/**
		 * @var - Registered Plugin Modules for Rt_Access_Control
		 */
		public static $modules;

		/**
		 *
		 */
		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'init_acl' ), 15 );
			add_filter( 'user_has_cap', array( $this, 'filter_caps' ), 999, 4 );

			add_action( 'edit_user_profile', array( $this, 'profile_level_permission' ), 1 );
			add_action( 'show_user_profile', array( $this, 'profile_level_permission' ), 1 );
			add_action( 'profile_update', array( $this, 'save_profile_level_permission' ), 10, 2 );
		}

		function filter_caps( $all_caps, $required_caps, $args, $user ) {

			$rt_biz_caps = array();
			// $m - module
			// $mkey - module_key
			foreach ( self::$modules as $mkey => $m ) {
				// $p - permission
				// $pkey - permission_key
				foreach ( self::$permissions as $pkey => $p ) {
					$rt_biz_caps[] = $mkey . '_' . $pkey;
				}
				$post_types = ( isset( $m['post_types'] ) && is_array( $m['post_types'] ) ) ? $m['post_types'] : array();
				// $pt - post_type
				foreach ( $post_types as $pt ) {
					$post_caps = self::get_admin_post_caps( $pt );
					$rt_biz_caps = array_merge( $rt_biz_caps, array_keys( $post_caps ) );
				}
			}

			if ( isset( $all_caps['administrator'] ) && $all_caps['administrator'] ) {
				foreach ( $required_caps as $cap ) {
					if ( ! in_array( $cap, $rt_biz_caps ) ) {
						continue;
					}
					$all_caps[$cap] = true;
				}
				return $all_caps;
			}

			foreach ( $required_caps as $cap ) {
				if ( ! in_array( $cap, $rt_biz_caps ) ) {
					continue;
				}

				$profile_permissions = get_user_meta($user->ID, 'rt_biz_profile_permissions', true );
				if ( ! empty( $profile_permissions ) && is_array( $profile_permissions ) ) {
					$valid_caps = array();
					// $mkey - module_key
					// $pp - profile_permission
					foreach ( $profile_permissions as $mkey => $pp ) {
						$valid_role_value = -1;
						// $ap - available_permission
						foreach ( self::$permissions as $ap ) {
							if ( intval( $pp ) > $valid_role_value && intval($pp) >= $ap['value'] ) {
								$valid_role_value = $ap['value'];
							}
						}
						$valid_role_key = self::get_role_key( $valid_role_value );
						// $ap - available_permission
						foreach ( self::$permissions as $ap ) {
							if ( $ap['value'] > $valid_role_value ) {
								continue;
							}
							$role_cap = self::get_capability_from_access_role( $mkey, self::get_role_key( $ap['value'] ) );
							if ( empty( $role_cap ) ) {
								continue;
							}
							$valid_caps[ $role_cap ] = true;
						}
						$post_types = ( isset( self::$modules[$mkey]['post_types'] ) && is_array( self::$modules[$mkey]['post_types'] ) ) ? self::$modules[$mkey]['post_types'] : array();
						// $pt - post_type
						foreach ( $post_types as $pt ) {
							$post_caps = call_user_func( array( 'Rt_Access_Control', 'get_'.$valid_role_key.'_post_caps' ), $pt );
							if ( ! empty( $post_caps ) && is_array( $post_caps ) ) {
								$valid_caps = array_merge( $valid_caps, $post_caps );
							}
						}
						$all_caps = array_merge( $all_caps, $valid_caps );
					}
					continue;
				}

				global $KWS_User_Groups;
				$module_permissions = get_site_option( 'rt_biz_module_permissions' );
				$ug_terms = $KWS_User_Groups->get_user_user_groups( $user );
				$user_groups = array();
				if ( ! $ug_terms instanceof WP_Error ) {
					// $ug - user_group
					foreach ( $ug_terms as $ug ) {
						$user_groups[] = $ug->term_id;
					}
				}
				if ( ! empty( $module_permissions ) && is_array( $module_permissions ) ) {
					// $mkey - module_key
					// $m - module
					foreach ( $module_permissions as $mkey => $m ) {
						// $gp - group_permission
						$gp = -1;
						$valid_caps = array();
						// $ugkey - user_group_key
						// $p - permission
						foreach ( $m as $ugkey => $p ) {
							if ( ! in_array( $ugkey, $user_groups ) ) {
								continue;
							}

							if ( $p > $gp ) {
								$gp = $p;
							}
						}
						$valid_role_value = -1;
						// $ap - available_permission
						foreach ( self::$permissions as $ap ) {
							if ( intval( $gp ) > $valid_role_value && intval($gp) >= $ap['value'] ) {
								$valid_role_value = $ap['value'];
							}
						}
						$valid_role_key = self::get_role_key( $valid_role_value );
						// $ap - available_permission
						foreach ( self::$permissions as $ap ) {
							if ( $ap['value'] > $valid_role_value ) {
								continue;
							}
							$role_cap = self::get_capability_from_access_role( $mkey, self::get_role_key( $ap['value'] ) );
							if ( empty( $role_cap ) ) {
								continue;
							}
							$valid_caps[ $role_cap ] = true;
						}
						$post_types = ( isset( self::$modules[$mkey]['post_types'] ) && is_array( self::$modules[$mkey]['post_types'] ) ) ? self::$modules[$mkey]['post_types'] : array();
						foreach ( $post_types as $pt ) {
							$post_caps = call_user_func( array( 'Rt_Access_Control', 'get_'.$valid_role_key.'_post_caps' ), $pt );
							if ( ! empty( $post_caps ) && is_array( $post_caps ) ) {
								$valid_caps = array_merge( $valid_caps, $post_caps );
							}
						}
						$all_caps = array_merge( $all_caps, $valid_caps );
					}
				}
			}
			return $all_caps;
		}

		/**
		 *  Initilize ACL on plugins_loaded with 15 priority. So that before this gets executed;
		 *  other addon plugins get chance to hook into it and register themselved
		 */
		function init_acl() {
			/**
			 *
			 *  Filter for other addons to register.
			 *  Array Structure is array( 'module_slug' => 'label' )
			 *
			 *  $biz_module = array(
			 *		RT_BIZ_TEXT_DOMAIN => array(
			 *			'label' => __( 'rtBiz' ),
			 *			'post_types' => array( 'post', 'page', 'rt_contact', 'rt_lead', ),
			 *		),
			 *  );
			 */
			self::$modules = apply_filters( 'rt_biz_modules', array() );

			self::$permissions = array(
				'no_access' => array(
					'value' => 0,
					'name' => __( 'No Role' ),
					'tooltip' => __( 'No Access' ),
				),
				'author' => array(
					'value' => 10,
					'name' => __( 'Author' ),
					'tooltip' => __( 'Read/Write (Self)' ),
				),
				'editor' => array(
					'value' => 20,
					'name' => __( 'Editor' ),
					'tooltip' => __( 'Read/Write (Everything)' ),
				),
				'admin' => array(
					'value' => 30,
					'name' => __( 'Admin' ),
					'tooltip' => __( 'Read/Write (Everything) + Settings' ),
				),
			);
		}

		/**
		 *
		 * This method return the capability name text if you pass the module_slug & role
		 * It will return proper cap if passed module_key is registered with Rt_Access_Control
		 * & passed role is valid.
		 *
		 * Other wise it will return empty string.
		 *
		 * Example : $module_key = 'rt_biz', $role = 'author'
		 *          Return String - rt_biz_author
		 *
		 * @param $module_key
		 * @param string $role
		 * @return string
		 */
		static function get_capability_from_access_role( $module_key, $role = 'no_access' ) {

			if ( isset( self::$modules[ $module_key ] ) && isset( self::$permissions[ $role ] ) ) {
				$module_key = rt_biz_sanitize_module_key( $module_key );
				return $module_key . '_' . $role;
			}
			return '';
		}

		static function get_role_key( $role_value ) {
			foreach ( self::$permissions as $pkey => $p ) {
				if ( $p['value'] == $role_value ) {
					return $pkey;
				}
			}
			return '';
		}

		static function get_no_access_post_caps( $post_type ) {
			return array(
				"edit_{$post_type}" => false,
				"read_{$post_type}" => false,
				"delete_{$post_type}" => false,
				"edit_{$post_type}s" => false,
				"edit_others_{$post_type}s" => false,
				"publish_{$post_type}s" => false,
				"read_private_{$post_type}s" => false,
				"delete_{$post_type}s" => false,
				"delete_private_{$post_type}s" => false,
				"delete_published_{$post_type}s" => false,
				"delete_others_{$post_type}s" => false,
				"edit_private_{$post_type}s" => false,
				"edit_published_{$post_type}s" => false,
			);
		}

		static function get_author_post_caps( $post_type ) {
			return array(
				"edit_{$post_type}" => true,
				"read_{$post_type}" => true,
				"delete_{$post_type}" => true,
				"edit_{$post_type}s" => true,
				"edit_others_{$post_type}s" => false,
				"publish_{$post_type}s" => true,
				"read_private_{$post_type}s" => false,
				"delete_{$post_type}s" => true,
				"delete_private_{$post_type}s" => false,
				"delete_published_{$post_type}s" => true,
				"delete_others_{$post_type}s" => false,
				"edit_private_{$post_type}s" => false,
				"edit_published_{$post_type}s" => true,
			);
		}

		static function get_editor_post_caps( $post_type ) {
			return array(
				"edit_{$post_type}" => true,
				"read_{$post_type}" => true,
				"delete_{$post_type}" => true,
				"edit_{$post_type}s" => true,
				"edit_others_{$post_type}s" => true,
				"publish_{$post_type}s" => true,
				"read_private_{$post_type}s" => true,
				"delete_{$post_type}s" => true,
				"delete_private_{$post_type}s" => true,
				"delete_published_{$post_type}s" => true,
				"delete_others_{$post_type}s" => true,
				"edit_private_{$post_type}s" => true,
				"edit_published_{$post_type}s" => true,
			);
		}

		static function get_admin_post_caps( $post_type ) {
			return array(
				"edit_{$post_type}" => true,
				"read_{$post_type}" => true,
				"delete_{$post_type}" => true,
				"edit_{$post_type}s" => true,
				"edit_others_{$post_type}s" => true,
				"publish_{$post_type}s" => true,
				"read_private_{$post_type}s" => true,
				"delete_{$post_type}s" => true,
				"delete_private_{$post_type}s" => true,
				"delete_published_{$post_type}s" => true,
				"delete_others_{$post_type}s" => true,
				"edit_private_{$post_type}s" => true,
				"edit_published_{$post_type}s" => true,
			);
		}

		/**
		 *  Saves the ACL Permission Matrix to the Database
		 */
		function save_acl_settings() {
			if ( ! isset( $_POST['rt_biz_acl_permissions'] ) ) {
				return;
			}
			if ( ! isset( $_POST['rt_biz_module_permissions'] ) || ! is_array( $_POST['rt_biz_module_permissions'] ) ) {
				return;
			}
			$module_permissions = $_POST['rt_biz_module_permissions'];
			update_site_option( 'rt_biz_module_permissions', $module_permissions );
		}

		/**
		 *  Take Action according to permission saved from the form & then display the ACL Settings UI
		 */
		function acl_settings_ui() {

			$this->save_acl_settings();

			rt_biz_get_template( 'acl-settings.php' );
		}

		function profile_level_permission( $user ) {
			$current_user = new WP_User( get_current_user_id() );
			if ( $current_user->has_cap( 'create_users' ) ) {
				$modules     = rt_biz_get_modules();
				$permissions = rt_biz_get_acl_permissions();
				$user_permissions = get_user_meta( $user->ID, 'rt_biz_profile_permissions', true );
				?>
				<h3><?php _e( 'rtBiz Profile Access' ); ?></h3>
				<table class="form-table">
					<tbody>
						<?php foreach ( $modules as $mkey => $m ) { ?>
						<tr>
							<th><?php echo $m['label']; ?></th>
							<td>
								<select name="rt_biz_profile_permissions[<?php echo $mkey ?>]">
									<option title="<?php _e( 'No Profile Access Override' ); ?>" value=""><?php _e( 'Use Group Access' ); ?></option>
									<?php foreach ( $permissions as $pkey => $p ) { ?>
									<option title="<?php echo $p['tooltip']; ?>" value="<?php echo $p['value']; ?>" <?php echo ( isset( $user_permissions[$mkey] ) && $user_permissions[$mkey] == $p['value'] ) ? 'selected="selected"' : ''; ?>><?php echo $p['name']; ?></option>
									<?php } ?>
								</select>
							</td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
				<?php
			}
		}

		function save_profile_level_permission( $user_id, $old_data ) {
			if ( current_user_can( 'create_users' ) ) {
				if ( isset( $_REQUEST['rt_biz_profile_permissions'] ) && is_array( $_REQUEST['rt_biz_profile_permissions'] ) ) {
					foreach ( $_REQUEST['rt_biz_profile_permissions'] as $mkey => $p ) {
						if ( strlen( $p ) == 0 ) {
							unset( $_REQUEST['rt_biz_profile_permissions'][$mkey] );
						}
					}
					update_user_meta( $user_id, 'rt_biz_profile_permissions', $_REQUEST['rt_biz_profile_permissions'] );
				}
			}
		}
	}
}