<?php
class OmnivaLt_Core
{
  public static $main_file_path = WP_PLUGIN_DIR . '/' . OMNIVALT_BASENAME;
  public static $var_directories = array('logs', 'pdf', 'debug', 'locations');

  public static function init()
  {
    self::load_classes();
    self::load_pre_init_hooks();

    add_action('init', function() {
      if ( ! self::allow_activate_plugin() ) {
        OmnivaLt_Helper::show_notices();
        return;
      }
      OmnivaLt_Terminals::check_terminals_json_file();
      self::load_init_hooks();

      if ( ! function_exists('is_plugin_active') ) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
      }
      if ( is_plugin_active('woocommerce/woocommerce.php') ) {
        self::load_launch_hooks();
        self::load_conditional_hooks();
        OmnivaLt_Cronjob::init();
      }

      if ( OmnivaLt_Debug::is_development_mode_enabled() ) {
        OmnivaLt_Helper::add_msg(sprintf(__('Development mode is activated! When no longer needed, disable it in %s.', 'omnivalt'), '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=omnivalt' ) . '">' . __('Omniva settings page', 'omnivalt') . '</a>'), 'warning', 'Omniva');
      }
    });
  }

  public static function allow_activate_plugin()
  {
    $error_prefix = __('Omniva plugin not working', 'omnivalt');

    if ( version_compare(PHP_VERSION, '7.0.0', '<') ) {
      OmnivaLt_Helper::add_msg(__('The website is using too low a PHP version', 'omnivalt'), 'error', $error_prefix);
      return false;
    }

    if ( ! self::is_directory_writable(OMNIVALT_DIR) ) {
      OmnivaLt_Helper::add_msg(__('Cannot create files in plugin folder. Please check the plugin folder permissions.', 'omnivalt'), 'error', $error_prefix);
      return false;
    }

    return true;
  }

  public static function get_configs( $section_name = false )
  {
    $default_configs = self::default_configs();
    $configs = $default_configs;

    if ( function_exists('omnivalt_configs') ) {
      foreach ( omnivalt_configs() as $key => $values ) {
        if ( is_array($values) ) {
          if ( ! isset($configs[$key]) ) {
            $configs[$key] = array();
          }
          $configs[$key] = array_merge($configs[$key], $values);
        } else {
          $configs[$key] = $values;
        }
      }
    }

    $configs['method_params'] = self::load_methods();

    $api_type = isset($configs['api']['type']) ? $configs['api']['type'] : null;
    $api = OmnivaLt_Api::get_api_class($api_type, true);
    $configs['additional_services'] = (method_exists($api, 'get_additional_services')) ? $api::get_additional_services() : array();
  
    if ( $section_name && isset($configs[$section_name]) ) {
      return $configs[$section_name];
    }

    return $configs;
  }

  public static function get_settings()
  {
    return get_option('woocommerce_omnivalt_settings');
  }

  public static function load_methods()
  {
    $classes = array(
      new OmnivaLt_Method_Terminal(),
      new OmnivaLt_Method_Courier(),
      new OmnivaLt_Method_CourierPlus(),
      new OmnivaLt_Method_PrivateCustomer(),
      new OmnivaLt_Method_PostNear(),
      new OmnivaLt_Method_PostSpecific(),
      new OmnivaLt_Method_PostBox(),
      new OmnivaLt_Method_Logistic(),
      new OmnivaLt_Method_LetterCourier(),
      new OmnivaLt_Method_LetterPost()
    );

    $methods = array();
    foreach ( $classes as $class ) {
      $class_data = $class->getData();
      if ( ! isset($class_data['id']) ) {
        continue;
      }
      $method_id = $class_data['id'];
      $methods[$method_id] = $class_data;
    }

    return $methods;
  }

  public static function get_shipping_method_info( $args = '' ) //TODO: Not completed and still not using. Make a mapping (simple function to return required info)
  {
    $args['receiver_country'] = (isset($args['receiver_country'])) ? $args['receiver_country'] : '';
    $args['get_all'] = (isset($args['get_all'])) ? $args['get_all'] : false;

    $configs = self::get_configs();
    $settings = self::get_settings();
    if ( ! $settings ) {
      return false;
    }

    $api_country = $settings['api_country'];
    if ( ! isset($configs['shipping_params'][$api_country]) ) {
      return false;
    }

    $shipping_params = $configs['shipping_params'][$api_country];

    $output = array(
      'title' => $shipping_params['title'],
      'comment_lang' => $shipping_params['comment_lang'],
      'tracking_url' => $shipping_params['tracking_url'],
      'set_name' => false,
      'shipping_services' => array(),
    );

    if ( ! empty($args['receiver_country']) && isset($shipping_params['shipping_sets'][$args['receiver_country']]) ) {
      $output['set_name'] = $shipping_params['shipping_sets'][$args['receiver_country']];

      if ( isset($configs['shipping_sets'][$output['set_name']]) ) {
        $output['shipping_services'] = $configs['shipping_sets'][$output['set_name']];
      }

      /*$asociations = OmnivaLt_Helper::get_methods_asociations(); //TODO: Continue with this

      if ( ! $args['get_all'] ) {
        foreach ( $output['shipping_services'] as $serv_combination => $serv_code ) {
        }
        $allowed_methods = OmnivaLt_Helper::get_allowed_methods($output['set_name']);
      } */
    }
  }

  public static function textdomain() {
    load_plugin_textdomain('omnivalt', false, dirname(OMNIVALT_BASENAME) . '/languages' ); 
  }

  public static function add_required_directories()
  {
    if ( ! self::is_directory_writable(OMNIVALT_DIR) ) {
      throw new \Exception(__('Cannot create files in plugin folder', 'omnivalt'));
    }
    $var_dir = OMNIVALT_DIR . 'var/';
    foreach ( self::$var_directories as $dir ) {
      if ( ! file_exists($var_dir . $dir) ) {
        mkdir($var_dir . $dir, 0755, true);
      }
    }
  }

  public static function is_directory_writable( $directory )
  {
    return (is_writable($directory));
  }

  public static function get_overrides_dir( $get_url = false, $get_parent = false )
  {
    $directory = '/omniva/';
    $base = ($get_parent) ? get_template_directory() : get_stylesheet_directory();
    $base_url = ($get_parent) ? get_template_directory_uri() : get_stylesheet_directory_uri();

    return ($get_url) ? $base_url . $directory : $base . $directory;
  }

  public static function get_override_file_path( $override_file, $get_url = false, $check_parent = true )
  {
    $child_path = self::get_overrides_dir(false, false) . $override_file;
    $parent_path = self::get_overrides_dir(false, true) . $override_file;

    if (file_exists($child_path)) {
        return $get_url ? self::get_overrides_dir(true, false) . $override_file : $child_path;
    }
    if ($check_parent && file_exists($parent_path)) {
        return $get_url ? self::get_overrides_dir(true, true) . $override_file : $parent_path;
    }

    return false;
  }

  public static function check_update( $current_version = '' ) {
    $update_params = self::get_configs('update');
    
    if (empty($update_params['check_url'])) {
      return false;
    }

    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_URL, $update_params['check_url']);
    curl_setopt($ch, CURLOPT_USERAGENT,'Awesome-Octocat-App');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    $response_data = json_decode(curl_exec($ch)); 
    curl_close($ch);

    if (isset($response_data->tag_name)) {
      $update_info = array(
        'version' => str_replace('v', '', $response_data->tag_name),
        'url' => (isset($response_data->html_url)) ? $response_data->html_url : '#',
      );
      if (empty($current_version)) {
        $plugin_data = get_file_data(self::$main_file_path, array('Version' => 'Version'), false);
        $current_version = $plugin_data['Version'];
      }
      return (version_compare($current_version, $update_info['version'], '<')) ? $update_info : false;
    }
  
    return false;
  }

  public static function update_message( $file, $plugin ) {
    $check_update = self::check_update($plugin['Version']);
    $update_params = self::get_configs('update');

    if ( $check_update ) {
      echo '<tr class="plugin-update-tr installer-plugin-update-tr js-otgs-plugin-tr active">';
      echo '<td class="plugin-update" colspan="100%">';
      echo '<div class="update-message notice inline notice-warning notice-alt">';
      echo '<p>' . sprintf(__('A newer version of the plugin (%s) has been released.', 'omnivalt'), '<a href="' . $check_update['url'] . '" target="_blank">v' . $check_update['version'] . '</a>');
      if ( ! empty($update_params['download_url']) ) {
        echo ' ' . sprintf(__('You can download it by pressing %s.', 'omnivalt'), '<a href="' . $update_params['download_url'] . '">' . __('here', 'omnivalt') . '</a>');
      }
      if ( defined('OMNIVALT_CUSTOM_CHANGES') && ! empty(OMNIVALT_CUSTOM_CHANGES) ) {
        echo '<br/><strong style="color:red;">' . __('We do not recommend update the plugin, because your plugin have changes that is not included in the update', 'omnivalt') . ':</strong>';
        foreach ( OMNIVALT_CUSTOM_CHANGES as $change ) {
          echo '<br/>· ' . $change . '';
        }
      }
      echo '</p>';
      echo '</div>';
      echo '</td></tr>';
    }
  }

  public static function load_front_scripts()
  {
    $folder_css = '/assets/css/';
    $folder_js = '/assets/js/';

    if (is_cart() || is_checkout()) {
      wp_enqueue_script('omnivalt_mapping', plugins_url($folder_js . 'terminal-mapping.js', self::$main_file_path), array('jquery'), null, true);
      wp_enqueue_style('omnivalt_mapping', plugins_url($folder_css . 'terminal-mapping.css', self::$main_file_path));

      wp_enqueue_script('omnivalt-helper', plugins_url($folder_js . 'omniva_helper.js', self::$main_file_path), array('jquery'), OMNIVALT_VERSION);
      wp_enqueue_script('omnivalt', plugins_url($folder_js . 'omniva.js', self::$main_file_path), array('jquery'), OMNIVALT_VERSION);
      wp_enqueue_style('omnivalt', plugins_url($folder_css . 'omniva.css', self::$main_file_path), array(), OMNIVALT_VERSION);
      
      if ( file_exists(OMNIVALT_DIR . $folder_css . 'custom.css') ) { //Allow custom CSS file which not include in plugin by default
        wp_enqueue_style('omnivalt-custom', plugins_url($folder_css . 'custom.css', self::$main_file_path), array(), OMNIVALT_VERSION);
      }

      wp_enqueue_script('omnivalt_leaflet', plugins_url($folder_js . 'leaflet.js', self::$main_file_path), array('jquery'), null, true);
      wp_enqueue_style('omnivalt_leaflet', plugins_url($folder_css . 'leaflet.css', self::$main_file_path));    

      wp_localize_script('omnivalt', 'omnivalt_data', array( //New method (use terminal-mapping library)
        'ajax_url' => admin_url('admin-ajax.php'),
        'omniva_plugin_url' => OMNIVALT_URL,
        'text' => array(
          'providers' => array(
            'omniva' => 'Omniva',
            'matkahuolto' => 'Matkahuolto',
          ),
          'modal_title_terminal' => __('parcel terminals', 'omnivalt'),
          'modal_search_title_terminal' => __('Parcel terminals list', 'omnivalt'),
          'select_terminal' => __('Select terminal', 'omnivalt'),
          'modal_title_post' => __('post offices', 'omnivalt'),
          'modal_search_title_post' => __('Post offices list', 'omnivalt'),
          'select_post' => __('Select post office', 'omnivalt'),
          'modal_open_button' => __('Select in map', 'omnivalt'),
          'search_placeholder' => __('Enter postcode', 'omnivalt'),
          'search_button' => __('Search', 'omnivalt'),
          'select_button' => __('Select', 'omnivalt'),
          'not_found' => __('Place not found', 'omnivalt'),
          'no_cities_found' => __('There were no cities found for your search term', 'omnivalt'),
          'enter_address' => __('Enter postcode/address', 'omnivalt'),
          'show_more' => __('Show more', 'omnivalt'),
          'use_my_location' => __('Use my location', 'omnivalt'),
          'my_position' => __('Distance calculated from this point', 'omnivalt'),
          'geo_not_supported' => __('Geolocation is not supported', 'omnivalt'),
        )
      ));
      wp_localize_script('omnivalt', 'omnivadata', array( //Old method (for dropdown)
        'ajax_url' => admin_url('admin-ajax.php'),
        'omniva_plugin_url' => OMNIVALT_URL,
        'text_select_terminal' => __('Select terminal', 'omnivalt'),
        'text_select_post' => __('Select post office', 'omnivalt'),
        'text_search_placeholder' => __('Enter postcode', 'omnivalt'),
        'not_found' => __('Place not found', 'omnivalt'),
        'text_enter_address' => __('Enter postcode/address', 'omnivalt'),
        'text_show_in_map' => __('Show in map', 'omnivalt'),
        'text_show_more' => __('Show more', 'omnivalt'),
        'text_modal_title_terminal' => __('Omniva parcel terminals', 'omnivalt'),
        'text_modal_search_title_terminal' => __('Parcel terminals addresses', 'omnivalt'),
        'text_modal_title_post' => __('Omniva post offices', 'omnivalt'),
        'text_modal_search_title_post' => __('Post offices addresses', 'omnivalt'),
      ));
    }

    /* Overrides from theme */
    if ( is_cart() ) {
      if ( self::get_override_file_path('css/cart.css') ) {
        wp_enqueue_style('omnivalt-theme-cart', self::get_override_file_path('css/cart.css', true), array(), OMNIVALT_VERSION);
      }
    }

    if ( is_checkout() ) {
      if ( self::get_override_file_path('css/checkout.css') ) {
        wp_enqueue_style('omnivalt-theme-checkout', self::get_override_file_path('css/checkout.css', true), array(), OMNIVALT_VERSION);
      }
    }
  }

  public static function load_admin_global_scripts( $hook )
  {
    $folder_css = '/assets/css/';
    $folder_js = '/assets/js/';
    
    wp_enqueue_style('omnivalt_admin_global', plugins_url($folder_css . 'omniva_admin_global.css', self::$main_file_path), array(), OMNIVALT_VERSION);
  }

  public static function load_admin_settings_scripts( $hook )
  {
    $folder_css = '/assets/css/';
    $folder_js = '/assets/js/';

    if ($hook == 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] == 'omnivalt') {
      wp_enqueue_style('omnivalt_admin_settings', plugins_url($folder_css . 'omniva_admin_settings.css', self::$main_file_path), array(), OMNIVALT_VERSION);
      wp_enqueue_script('omnivalt_admin_settings', plugins_url($folder_js . 'omniva_admin_settings.js', self::$main_file_path), array('jquery'), OMNIVALT_VERSION);

      wp_localize_script('omnivalt_admin_settings', 'omnivalt_params', array(
        'available_methods' => self::get_configs('available_methods')
      ));
    }
  }

  public static function add_asyncdefer_by_handle( $tag, $handle )
  {
    if (strpos($handle, 'async') !== false) {
      $tag = str_replace('<script ', '<script async ', $tag);
    }
    if (strpos($handle, 'defer') !== false) {
      $tag =  str_replace('<script ', '<script defer ', $tag);
    }

    return $tag;
  }

  public static function init_shipping_method()
  {
    include OMNIVALT_DIR . 'core/class-shipping-method.php';
  }

  public static function add_shipping_method( $methods )
  {
    $methods['omnivalt'] = 'Omnivalt_Shipping_Method';
    return $methods;
  }

  public static function settings_link( $links ) {
    array_unshift($links, '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=omnivalt' ) . '">' . __('Settings', 'omnivalt') . '</a>');
    return $links;
  }

  public static function admin_notices() {
    OmnivaLt_Helper::show_notices();
  }

  public static function add_to_footer()
  {
    if (is_cart() || is_checkout()) {
      echo OmnivaLt_Terminals::terminals_modal();
    }
  }

  public static function get_error_text( $error_code )
  {
    $errors = array(
      '001' => _x('The service is not available to the sender country', 'error', 'omnivalt'),
      '002' => _x('The service is not available to the receiver country', 'error', 'omnivalt'),
      '003' => _x('This shipping set does not exist', 'error', 'omnivalt'),
      '004' => _x('The service does not exist for the specified method', 'error', 'omnivalt'),
    );

    if ( ! isset($errors[$error_code]) ) {
      return _x('Unknown error', 'error', 'omnivalt');
    }

    return $errors[$error_code];
  }

  public static function get_core_dir()
  {
    return OMNIVALT_DIR . 'core/';
  }

  private static function load_classes()
  {
    include OMNIVALT_DIR . 'vendor/autoload.php';
    
    $core_dir = self::get_core_dir();
    require_once $core_dir . 'class-debug.php';
    require_once $core_dir . 'class-filters.php';
    require_once $core_dir . 'class-helper.php';
    require_once $core_dir . 'wc/' . 'class-wc.php';
    require_once $core_dir . 'wc/' . 'class-wc-order.php';
    require_once $core_dir . 'wc/' . 'class-wc-product.php';
    require_once $core_dir . 'wc/' . 'class-wc-blocks.php';
    require_once $core_dir . 'class-method.php';
    require_once $core_dir . 'methods/' . 'class-method-core.php';
    require_once $core_dir . 'methods/' . 'class-method-terminal.php';
    require_once $core_dir . 'methods/' . 'class-method-courier.php';
    require_once $core_dir . 'methods/' . 'class-method-courierplus.php';
    require_once $core_dir . 'methods/' . 'class-method-privatecustomer.php';
    require_once $core_dir . 'methods/' . 'class-method-postnear.php';
    require_once $core_dir . 'methods/' . 'class-method-postspecific.php';
    require_once $core_dir . 'methods/' . 'class-method-postbox.php';
    require_once $core_dir . 'methods/' . 'class-method-logistic.php';
    require_once $core_dir . 'methods/' . 'class-method-lettercourier.php';
    require_once $core_dir . 'methods/' . 'class-method-letterpost.php';
    require_once $core_dir . 'class-compatibility.php';
    require_once $core_dir . 'class-emails.php';
    require_once $core_dir . 'class-labels.php';
    require_once $core_dir . 'class-api.php';
    require_once $core_dir . 'api/' . 'class-api-core.php';
    require_once $core_dir . 'api/' . 'class-api-xml.php';
    require_once $core_dir . 'api/' . 'class-api-omx.php';
    require_once $core_dir . 'api/' . 'class-api-international.php';
    require_once $core_dir . 'api/' . 'class-api-omx-international.php';
    require_once $core_dir . 'class-product.php';
    require_once $core_dir . 'class-cronjob.php';
    require_once $core_dir . 'class-terminals.php';
    require_once $core_dir . 'class-manifest.php';
    require_once $core_dir . 'class-order.php';
    require_once $core_dir . 'class-omniva-order.php';
    require_once $core_dir . 'class-frontend.php';
    require_once $core_dir . 'class-statistics.php';
    require_once $core_dir . 'shipping-method/' . 'class-shipping-method-helper.php';
    require_once $core_dir . 'shipping-method/' . 'class-shipping-method-core.php';
    require_once $core_dir . 'shipping-method/' . 'class-shipping-method-country.php';
    require_once $core_dir . 'shipping-method/' . 'class-shipping-method-international.php';
    require_once $core_dir . 'shipping-method/' . 'class-shipping-method-field.php';
    require_once $core_dir . 'shipping-method/' . 'class-shipping-method-html.php';
  }

  private static function default_configs()
  {
    return array(
      'plugin' => array(
        'id' => 'omnivalt',
        'title' => 'Omniva',
        'settings_key' => 'woocommerce_omnivalt_settings',
      ),
      'available_methods' => array(),
      'shipping_params' => array(),
      'method_params' => array(),
      'additional_services' => array(),
      'locations' => array(),
      'update' => array(),
      'text_variables' => array(),
      'api' => array(
        'type' => 'xml',
      ),
      'debug' => array(
        'delete_after' => 30, //Days
      ),
      'meta_keys' => array(
        'method' => '_omnivalt_method',
        'barcodes' => '_omnivalt_barcode',
        'error' => '_omnivalt_error',
        'manifest_date' => '_omnivalt_manifest_date',
        'terminal_id' => '_omnivalt_terminal_id',
        'dimmensions' => '_omnivalt_dimmensions',
        'total_shipments' => '_omnivalt_total_shipments',
        'courier_calls' => '_omnivalt_courier_calls',
        'order_tracked' => '_omnivalt_order_tracked',
        'last_track_date' => '_omnivalt_last_track_date',
      ),
    );
  }

  private static function load_pre_init_hooks()
  {
    add_action('before_woocommerce_init', array('OmnivaLt_Compatibility', 'declare_wc_hpos_compatibility'));
    add_action('before_woocommerce_init', array('OmnivaLt_Compatibility', 'declare_wc_blocks_compatibility'));
    add_action('init', array('OmnivaLt_Core', 'textdomain'));
    add_action('woocommerce_blocks_loaded', array('OmnivaLt_Wc_Blocks', 'init'));
    add_action('woocommerce_shipping_init', array('OmnivaLt_Core', 'init_shipping_method'));
  }

  private static function load_init_hooks()
  {
    add_action('admin_notices', array('OmnivaLt_Core', 'admin_notices'));
    add_action('after_plugin_row_' . OMNIVALT_BASENAME, array('OmnivaLt_Core', 'update_message'), 10, 3);

    add_filter('plugin_action_links_' . OMNIVALT_BASENAME, array('OmnivaLt_Core', 'settings_link'));
  }

  private static function load_launch_hooks()
  {
    add_action('wp_enqueue_scripts', array('OmnivaLt_Core', 'load_front_scripts'), 99);
    add_action('omniva_admin_manifest_head', array('OmnivaLt_Manifest', 'load_admin_scripts'));
    add_action('admin_enqueue_scripts', array('OmnivaLt_Core', 'load_admin_global_scripts'));
    add_action('admin_enqueue_scripts', array('OmnivaLt_Core', 'load_admin_settings_scripts'));
    add_action('admin_enqueue_scripts', array('OmnivaLt_Order', 'load_admin_scripts'));
    add_action('wp_footer', array('OmnivaLt_Core', 'add_to_footer'));
    add_action('wp_ajax_nopriv_add_terminal_to_session', array('OmnivaLt_Terminals', 'add_terminal_to_session'));
    add_action('wp_ajax_add_terminal_to_session', array('OmnivaLt_Terminals', 'add_terminal_to_session'));
    add_action('wp_ajax_omniva_terminals_json', array('OmnivaLt_Terminals', 'get_terminals_json'));
    add_action('wp_ajax_nopriv_omniva_terminals_json', array('OmnivaLt_Terminals', 'get_terminals_json'));
    add_action('admin_menu', array('OmnivaLt_Manifest', 'register_menu_pages'));
    add_action('woocommerce_after_shipping_rate', array('OmnivaLt_Order', 'after_rate_description'), 20, 2);
    add_action('woocommerce_after_shipping_rate', array('OmnivaLt_Order', 'after_rate_terminals'));
    add_action('woocommerce_checkout_update_order_meta', array('OmnivaLt_Order', 'add_terminal_id_to_order'));
    add_action('woocommerce_review_order_before_cart_contents', array('OmnivaLt_Order', 'validate_order'), 10);
    add_action('woocommerce_after_checkout_validation', array('OmnivaLt_Order', 'validate_order'), 10);
    add_action('woocommerce_checkout_order_created', array('OmnivaLt_Order', 'check_terminal_id_in_order'));
    add_action('woocommerce_order_details_after_order_table', array('OmnivaLt_Order', 'show_selected_terminal'), 10, 1);
    add_action('woocommerce_order_details_after_order_table', array('OmnivaLt_Order', 'show_tracking_link'), 10, 1);
    add_action('woocommerce_email_after_order_table', array('OmnivaLt_Order', 'show_selected_terminal'), 10, 1);
    add_action('woocommerce_admin_order_preview_end', array('OmnivaLt_Order', 'display_order_data_in_admin'));
    add_action('wp_ajax_generate_omnivalt_label', array('OmnivaLt_Order', 'generate_label'));
    add_action('woocommerce_checkout_update_order_meta', array('OmnivaLt_Order', 'add_cart_weight'));
    add_action('print_omniva_tracking_url', array('OmnivaLt_Order', 'print_tracking_url_action'), 10, 2);
    add_action('woocommerce_admin_order_data_after_shipping_address', array('OmnivaLt_Order', 'admin_order_display'), 10, 2);
    add_action('save_post', array('OmnivaLt_Order', 'admin_order_save'));
    add_action('woocommerce_update_order', array('OmnivaLt_Order', 'admin_order_save_hpos'));
    add_action('woocommerce_checkout_process', array('OmnivaLt_Order', 'checkout_validate_terminal'));
    add_action('wp_ajax_nopriv_remove_courier_call', array('OmnivaLt_Labels', 'ajax_remove_courier_call'));
    add_action('wp_ajax_remove_courier_call', array('OmnivaLt_Labels', 'ajax_remove_courier_call'));
    add_action('woocommerce_after_save_address_validation', array('OmnivaLt_Frontend', 'validate_phone_number'), 1, 2);
    add_action('woocommerce_checkout_process', array('OmnivaLt_Frontend', 'validate_phone_number'));
    add_action('block_categories_all', array('OmnivaLt_Wc_Blocks', 'register_block_categories'), 10, 2 );
    add_action('admin_head', array('OmnivaLt_Product', 'tabs_styles'));
    add_action('woocommerce_process_product_meta_simple', array('OmnivaLt_Product', 'save_options_fields'));
    add_action('woocommerce_process_product_meta_variable', array('OmnivaLt_Product', 'save_options_fields'));

    add_filter('script_loader_tag', array('OmnivaLt_Core', 'add_asyncdefer_by_handle'), 10, 2);
    add_filter('woocommerce_shipping_methods', array('OmnivaLt_Core', 'add_shipping_method'));
    add_filter('admin_post_omnivalt_call_courier', array('OmnivaLt_Labels', 'post_call_courier_actions'));
    add_filter('admin_post_omnivalt_cancel_courier', array('OmnivaLt_Labels', 'post_cancel_courier_actions'));
    add_filter('woocommerce_order_data_store_cpt_get_orders_query', array('OmnivaLt_Manifest', 'handle_custom_query_var'), 10, 2);
    add_filter('woocommerce_package_rates', array('OmnivaLt_Order', 'restrict_shipping_methods_by_cats'), 10, 1);
    add_filter('woocommerce_package_rates', array('OmnivaLt_Order', 'restrict_shipping_methods_by_shipclass'), 10, 1);
    add_filter('woocommerce_admin_order_preview_get_order_details', array('OmnivaLt_Order', 'admin_order_add_custom_meta_data'), 10, 2);
    add_filter('bulk_actions-edit-shop_order', array('OmnivaLt_Order', 'bulk_actions'), 20);
    add_filter('handle_bulk_actions-edit-shop_order', array('OmnivaLt_Order', 'handle_bulk_actions'), 20, 3);
    add_filter('bulk_actions-woocommerce_page_wc-orders', array('OmnivaLt_Order', 'bulk_actions'), 20); //HPOS
    add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array('OmnivaLt_Order', 'handle_bulk_actions'), 20, 3); //HPOS
    add_filter('admin_post_omnivalt_labels', array('OmnivaLt_Order', 'post_label_actions'), 20, 3);
    add_filter('admin_post_omnivalt_manifest', array('OmnivaLt_Order', 'post_manifest_actions'), 20, 3);
    //add_filter('woocommerce_admin_order_actions_end', array('OmnivaLt_Order', 'order_actions'), 10, 1);
    add_filter('woocommerce_cart_shipping_method_full_label', array('OmnivaLt_Frontend', 'add_logo_to_method'), 10, 2);
    add_filter('woocommerce_package_rates' , array('OmnivaLt_Frontend', 'change_methods_position'), 99, 2);
    add_filter('woocommerce_available_payment_gateways', array('OmnivaLt_Frontend', 'change_payment_list_by_shipping_method'));
    add_filter('woocommerce_process_registration_errors', array('OmnivaLt_Frontend', 'validate_phone_number'), 10, 4);
    add_filter('woocommerce_product_data_tabs', array('OmnivaLt_Product', 'add_product_tabs'));
    add_filter('woocommerce_product_data_panels', array('OmnivaLt_Product', 'options_content'));
  }

  private static function load_conditional_hooks()
  {
    $settings = self::get_settings();

    $track_info_in_emails = (isset($settings['track_info_in_email'])) ? $settings['track_info_in_email'] : 'yes';
    
    if ( $track_info_in_emails === 'yes' ) {
      add_action('woocommerce_email_after_order_table', array('OmnivaLt_Order', 'show_tracking_link'), 10, 1);
    }
  }

  public static function load_vendors( $vendors = array() )
  {
    trigger_error('Method ' . __METHOD__ . ' is deprecated. All vendors are now loaded via composer.', E_USER_DEPRECATED);
  }
}
