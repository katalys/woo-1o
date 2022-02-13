<?php

/** 
 * SETTINGS PAGE
 *
 * Registers setting page fields and outputs tabbed pages for settings.
 *
 */
class oneO_Settings
{

    private $oneO_settings_options;

    /**
     * Tell the plugin to creat a stand alone menu or an options submenu.
     *
     * @value 'stand-alone' - A separate settings menus in the Admin Menu.
     * @value 'options' - A submenu of "Settings" menu (default).
     */
    private $menu_type = 'options';

    public function __construct()
    {
        $this->menu_type = 'stand-alone';
        add_action('admin_menu', array($this, 'oneO_settings_add_plugin_page'));
        add_action('admin_init', array($this, 'oneO_settings_page_init'));
    }

    public function oneO_settings_add_plugin_page()
    {
        if ('stand-alone' === $this->menu_type) {
            add_menu_page(
                '1o Settings',
                '1o Settings',
                'manage_options',
                '1o-settings',
                array($this, 'oneO_settings_create_admin_page'),
                OOMP_LOC_CORE_IMG . '1o-docs-logo.svg',
                80 // position
            );
        } else {
            add_options_page(
                '1o Settings',
                '1o Settings',
                'manage_options',
                '1o-settings',
                array($this, 'oneO_settings_create_admin_page')
            );
        }
    }

    public function oneO_settings_create_admin_page()
    {
        $this->oneO_settings_options = get_option('oneO_settings_option_name', array());
?>
        <style>
            .settings_unset .api_endpoint {
                display: none;
            }

            .settings-1o-nav {
                border-width: 1px 0;
                border-style: solid;
                border-color: #c3c4c7;
            }

            .settings-1o-nav li {
                display: inline-block;
                padding: .5rem;
                margin: 0;
                color: #1d2327;
                text-align: center;
            }

            .settings-1o-nav li.nav-1o-vesion-num {
                float: right;
                text-align: right;
                color: #9aa9b2;
            }

            .settings-1o-nav li a {
                text-decoration: none;
                color: #1d2327;
            }

            .settings-1o-nav li a:hover {
                text-decoration: underline;
            }

            .settings-form-1o h2 {
                display: none;
            }

            #endpoint_copy {
                text-decoration: none;
            }

            #endpoint_copy:hover {
                text-decoration: underline;
            }

            #secret_key-toggle {
                margin-left: -27px;
                color: #2271b1;
                cursor: pointer;
                margin-top: 4px;
            }

            .medium-text-input {
                width: 30em;
            }

            @media screen and (max-width:600px) {
                .settings-1o-nav li {
                    width: 49%;
                    box-sizing: border-box;
                }

                .settings-1o-nav li.nav-1o-vesion-num {
                    float: none !important;
                    width: 100% !important;
                    box-sizing: border-box;
                    text-align: right;
                }
            }

            @media screen and (max-width:400px) {
                .settings-1o-nav li {
                    width: 100%;
                }
            }

            @media screen and (max-width: 782px) {
                .form-table input.regular-text.medium-text-input {
                    width: 86%;
                    display: inline-block;
                }

                .settings-1o-nav li {
                    text-align: left;
                }
            }
        </style>

        <div class="wrap">
            <h2>1o Settings Page</h2>
            <?php settings_errors(); ?>
            <?php
            if (isset($_GET['tab'])) {
                $active_tab = $_GET['tab'];
            }
            ?>
            <h2 class="nav-tab-wrapper"> <a href="?page=1o-settings&tab=setting" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a> <a href="?page=1o-settings&tab=getting_started" class="nav-tab <?php echo $active_tab == 'getting_started' ? 'nav-tab-active' : ''; ?>">Getting Started</a> </h2>
            <?php
            if ($active_tab === 'getting_started') {
                /*
                $products = array(
                    array(
                        "id" => '14',
                        "qty" => 1
                    )
                );
                // TEST
                $email = 'fischer.creative.media@gmail.com';
                $orderid = '12546498478946556fdfdf';
                oneO_addWooOrder($products, $email, $orderid);
                */
            ?>
                <p>Getting Started</p>
                <p>&nbsp;</p>
                <p>&nbsp;</p>
                <p>&nbsp;</p>
                <p>&nbsp;</p>
                <p>&nbsp;</p>
                <p>&nbsp;</p>
                <p>&nbsp;</p>
                <p>&nbsp;</p>
                <p>&nbsp;</p>
            <?php
            } else {
                $pKey = isset($this->oneO_settings_options['public_key']) && $this->oneO_settings_options['public_key'] != '' ? true : false;
                $ssKey = isset($this->oneO_settings_options['secret_key']) && $this->oneO_settings_options['secret_key'] != '' ? true : false;
                $intId = isset($this->oneO_settings_options['integration_id']) && $this->oneO_settings_options['integration_id'] != '' ? true : false;
                $setting_class = $pKey && $ssKey && $intId ? ' settings_set' : ' settings_unset';
            ?>
                <p>Enter your <strong>Integration ID</strong>, <strong>API Key</strong> and <strong>Shared Secret</strong> in the fields below. Log in to your 1o Admin console > Settings > Apps & Integrations, select Platforms tab, click WooCommerce and follow the instructions.</p>
                <form method="post" action="options.php" class="settings-form-1o<?php echo $setting_class; ?>">
                    <?php settings_fields('oneO_settings_option_group'); ?>
                    <?php do_settings_sections('oneO-settings-admin'); ?>
                    <?php do_settings_sections('oneO-settings-admin-two'); ?>
                    <?php submit_button(__('Save 1o Settings')); ?>
                </form>
            <?php
            }
            ?>
            <nav>
                <ul class="settings-1o-nav">
                    <li><a href="#">Merchant Login</a></li>
                    <li><a href="#">About 1o</a></li>
                    <li><a href="#">Help Center</a></li>
                    <li><a href="#">Terms</a></li>
                    <li><a href="#">Privacy</a></li>
                    <li><a href="#">Get In Touch</a></li>
                    <li class="nav-1o-vesion-num">Version <?php echo OOMP_VER_NUM; ?></li>
                </ul>
            </nav>
        </div>
    <?php
    }

    public function oneO_settings_page_init()
    {
        register_setting(
            'oneO_settings_option_group', // option_group
            'oneO_settings_option_name', // option_name
            array($this, 'oneO_settings_sanitize') // sanitize_callback
        );

        add_settings_section(
            'oneO_settings_setting_section', // id
            'Settings', // title
            array($this, 'oneO_settings_section_info'), // callback
            'oneO-settings-admin' // page
        );
        add_settings_section(
            'oneO_settings_endpoint_section', // id
            'Endpoint', // title
            array($this, 'oneO_settings_section_endpoint'), // callback
            'oneO-settings-admin-two' // page
        );

        add_settings_field(
            'integration_id', // id
            'Integration ID', // title
            array($this, 'integration_id_callback'), // callback
            'oneO-settings-admin', // page
            'oneO_settings_setting_section' // section
        );

        add_settings_field(
            'public_key', // id
            'API Key', // title
            array($this, 'public_key_callback'), // callback
            'oneO-settings-admin', // page
            'oneO_settings_setting_section' // section
        );

        add_settings_field(
            'secret_key', // id
            'Shared Secret', // title
            array($this, 'secret_key_callback'), // callback
            'oneO-settings-admin', // page
            'oneO_settings_setting_section' // section
        );

        add_settings_field(
            'api_endpoint', // id
            'Store API Endpoint', // title
            array($this, 'api_endpoint_callback'), // callback
            'oneO-settings-admin-two', // page
            'oneO_settings_endpoint_section', // section
            array('class' => 'api_endpoint') // args
        );
    }

    public function oneO_settings_sanitize($input)
    {
        $sanitary_values = array();
        if (isset($input['integration_id'])) {
            $sanitary_values['integration_id'] = sanitize_text_field($input['integration_id']);
        }
        if (isset($input['public_key'])) {
            $sanitary_values['public_key'] = sanitize_text_field($input['public_key']);
        }
        if (isset($input['api_endpoint'])) {
            $sanitary_values['api_endpoint'] = sanitize_text_field($input['api_endpoint']);
        }
        if (isset($input['secret_key'])) {
            $sanitary_values['secret_key'] = sanitize_text_field($input['secret_key']);
        }

        return $sanitary_values;
    }

    public function oneO_settings_section_info()
    {
    }

    public function oneO_settings_section_endpoint()
    {
    }

    public function public_key_callback()
    {
        printf(
            '<input class="regular-text medium-text-input" type="text" autocomplete="1o-public-key" name="oneO_settings_option_name[public_key]" id="public_key" value="%s">',
            isset($this->oneO_settings_options['public_key']) ? esc_attr($this->oneO_settings_options['public_key']) : ''
        );
    }

    public function secret_key_callback()
    {
        printf(
            '<input class="regular-text medium-text-input" type="password" autocomplete="1o-shared-secret" name="oneO_settings_option_name[secret_key]" id="secret_key" value="%s"><span id="secret_key-toggle" class="dashicons dashicons-visibility"></span>',
            isset($this->oneO_settings_options['secret_key']) ? esc_attr($this->oneO_settings_options['secret_key']) : ''
        );
    ?>
        <script>
            const oneO_el = document.querySelector('#secret_key-toggle');
            const oneO_field = document.querySelector('#secret_key');
            const handleToggle = (event) => {
                const type = oneO_field.getAttribute("type") === "password" ? "text" : "password";
                const eye = oneO_el.getAttribute("class") == 'dashicons dashicons-visibility' ? 'dashicons dashicons-hidden' : 'dashicons dashicons-visibility';
                oneO_field.setAttribute("type", type);
                oneO_el.setAttribute("class", eye);
                event.preventDefault();
            }
            oneO_el.onclick = (event) => handleToggle(event);
            oneO_el.addEventListener('keyup', (event) => {
                if (event.keyCode === 13 || event.keyCode === 32) {
                    handleToggle(event);
                }
            });
        </script>
<?php
    }

    public function integration_id_callback()
    {
        printf(
            '<input class="regular-text medium-text-input" type="text" autocomplete="1o-integration-id" name="oneO_settings_option_name[integration_id]" id="integration_id" value="%s">',
            isset($this->oneO_settings_options['integration_id']) ? esc_attr($this->oneO_settings_options['integration_id']) : ''
        );
    }

    public function api_endpoint_callback()
    {
        $endpoint = isset($this->oneO_settings_options['api_endpoint']) ? esc_attr($this->oneO_settings_options['api_endpoint']) : '';
        $endpoint = $endpoint != '' ? $endpoint : get_rest_url(null, OOMP_NAMESPACE);
        echo '<input class="regular-text medium-text-input" type="text" autocomplete="none" name="oneO_settings_option_name[api_endpoint]" id="api_endpoint" value="' . $endpoint . '" disabled>&nbsp;&nbsp;<a href="#" id="endpoint_copy">Copy</a>';
        echo '<p class="description" id="api_endpoint-description">Copy this URL to your account integration settings on 1o.</p>';
        echo '<script>';
        echo "document.querySelector('#endpoint_copy').addEventListener('click', function(e){ 
      var copyText = document.querySelector('#api_endpoint');
      var copyLink = document.querySelector('#endpoint_copy');
      copyText.disabled = false;
      copyText.focus();
      copyText.select();
      document.execCommand('copy');
      copyText.blur();
      copyText.disabled = true;
      copyLink.innerHTML = 'Copied!';
      console.log(copyText.textContent);
      e.preventDefault();
   });";
        echo '</script>';
    }
}
