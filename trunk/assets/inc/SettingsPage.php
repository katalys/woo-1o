<?php
namespace KatalysMerchantPlugin;

/**
 * SETTINGS PAGE
 *
 * Registers setting page fields and outputs tabbed pages for settings.
 *
 */
class SettingsPage
{
  /**
   * Tell the plugin to creat a stand alone menu or an options submenu.
   *
   * @value 'stand-alone' - A separate settings menus in the Admin Menu.
   * @value 'options' - A submenu of "Settings" menu (default).
   */
  private $menu_type = 'stand-alone';

  public function __construct()
  {
    // Admin Scripts
    add_action('admin_enqueue_scripts', function () {
      // admin_css
      wp_enqueue_style(OOMP_TEXT_DOMAIN . '-admin-css', OOMP_LOC_URL . '/css/admin.css', null, time(), 'all');
      // admin_js
      //wp_enqueue_script(OOMP_TEXT_DOMAIN . '-admin-js', OOMP_LOC_URL . '/js/admin.js', ['jquery'], time(), true);
    });

    add_action('admin_menu', [$this, 'oneO_settings_add_plugin_page']);
    add_action('admin_init', [$this, 'oneO_settings_page_init']);
  }

  public function oneO_settings_add_plugin_page()
  {
    if ('stand-alone' === $this->menu_type) {
      add_menu_page(
          'Katalys Shops Settings',
          'Katalys Shops Settings',
          'manage_options',
          '1o-settings',
          [$this, 'oneO_settings_create_admin_page'],
          OOMP_LOC_URL . '/img/1o-docs-logo.svg',
          80 // position
      );
    } else {
      add_options_page(
          'Katalys Shops Settings',
          'Katalys Shops Settings',
          'manage_options',
          '1o-settings',
          [$this, 'oneO_settings_create_admin_page']
      );
    }
  }

  public function oneO_settings_create_admin_page()
  {
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

          @media screen and (max-width: 600px) {
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

          @media screen and (max-width: 400px) {
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
          <h2>Katalys Shops Settings Page</h2>
        <?php settings_errors(); ?>
        <?php
        switch (isset($_GET['tab']) ? $_GET['tab'] : '') {
          case 'getting_started':
            $active_tab = 'getting_started';
            break;
          case 'network_settings':
            if (!checkPluginRevoffersExist()) {
              $active_tab = 'network_settings';
            } else {
              $active_tab = 'settings';
            }
            break;
          case 'settings':
          default:
            $active_tab = 'settings';
        }
        ?>
          <h2 class="nav-tab-wrapper">
              <a href="?page=1o-settings&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
              <?php if (!checkPluginRevoffersExist()):?>
                <a href="?page=1o-settings&tab=network_settings" class="nav-tab <?php echo $active_tab == 'network_settings' ? 'nav-tab-active' : ''; ?>">Network Settings</a>
              <?php endif;?>
              <a href="?page=1o-settings&tab=getting_started" class="nav-tab <?php echo $active_tab == 'getting_started' ? 'nav-tab-active' : ''; ?>">Getting Started</a>
          </h2>
        <?php
        if ($active_tab === 'getting_started') {
          ?>
            <h1>Getting Started</h1>
            <p>Now that you have installed and activated the 1o Merchant Plugin, you are ready to connect your
                WooCommerce Store and your 1o account. Follow the steps below to connect your WooCommerce Store.</p>
            <h3>Connecting your store to 1o:</h3>
            <p>Because you'll be copying and pasting values from your <strong>1o Admin Console</strong> to this plugin,
                you'll want to open the <strong>Setting</strong> tab above, and keep it open during the next few steps.
            </p>
            <ol>
                <li><strong>Login</strong> to your <strong>Katalys Shops Admin Console</strong>.</li>
                <li>Navigate to the <strong>General</strong> tab in the Katalys Shops Admin Console.</li>
                <li><strong>Copy</strong> the <strong>Integration ID</strong> code below and <strong>Paste</strong> it
                    into the <strong>Integration ID</strong> field of the 1o plugin Settings tab in this plugin.
                </li>
                <li><strong>Copy</strong> the <strong>API key</strong> code and <strong>Paste</strong> it into the
                    <strong>API Key</strong> field of the Katalys Shops plugin Settings tab in this plugin.
                </li>
                <li><strong>Copy</strong> the <strong>Shared Secret</strong> code and <strong>Paste</strong> it into the
                    <strong>Shared secret</strong> field of the Katalys Shops plugin Settings tab in this plugin.
                </li>
                <li>Click the <strong>Save Settings</strong> button in the Katalys Shops plugin Settings tab in this plugin to save
                    your settings.
                </li>
                <li>Return to the Katalys Shops Admin Console and click <strong>Save & Generate GraphQL ID</strong> button.</li>
                <li>Navigate to <strong>GraphQL</strong> tab and copy the url.</li>
                <li>Navigate back to your Katalys Shops Admin Console > Settings > Apps & integrations and select <strong>WooCommerce</strong>.
                </li>
                <li>Click the <strong>Settings</strong> tab and paste the url in the GraphQL field.</li>
                <li>Click <strong>Save</strong> and you are done!</li>
            </ol>
            <p>&nbsp;</p>
            <h3>Need help?</h3>
            <p>Get in touch with us and we'll help install it for you.</p>
            <p><a href="mailto:help@1o.io" class="button button-primary" target="_blank">Get in touch</a></p>
          <?php
        } else if ($active_tab == 'network_settings' and !checkPluginRevoffersExist()) {
          require_once __DIR__ . '/AdvertiserIntegration/admin_page.php';
        } else {
          $opt = oneO_options();
          $optIsValid = $opt->publicKey && $opt->secretKey && $opt->integrationId && $opt->graphqlEndpoint;
          ?>
            <p>Enter your <strong>Integration ID</strong>, <strong>API Key</strong> and <strong>Shared Secret</strong>
                in the fields below. Log in to your Katalys Shops Admin console > Settings > Apps & Integrations, select Platforms
                tab, click WooCommerce and follow the instructions.</p>
            <form method="post" action="options.php" class="settings-form-1o <?php echo $optIsValid ? 'settings_set' : 'settings_unset'; ?>">
              <?php settings_fields('katalys_shop_merchant_group'); ?>
              <?php do_settings_sections('oneO-settings-admin'); ?>
              <?php do_settings_sections('oneO-settings-admin-two'); ?>
              <?php do_settings_sections('oneO-settings-admin-three'); ?>
              <?php submit_button(__('Save 1o Settings')); ?>
            </form>
          <?php
        }
        ?>
          <nav>
              <ul class="settings-1o-nav">
                  <li><a href="https://1o.io/users/log-in" target="_blank">Merchant Login</a></li>
                  <li><a href="https://www.1o.io/" target="_blank">About Katalys Shops</a></li>
                  <li><a href="https://www.1o.io/help-intro.html" target="_blank">Help Center</a></li>
                  <li><a href="https://www.1o.io/tos.html" target="_blank">Terms</a></li>
                  <li><a href="https://www.1o.io/privacy.html" target="_blank">Privacy</a></li>
                  <li><a href="mailto:help@1o.io" target="_blank">Get In Touch</a></li>
                  <li class="nav-1o-vesion-num">Version <?php echo OOMP_VER_NUM; ?></li>
              </ul>
          </nav>
      </div>
    <?php
  }

  public function oneO_settings_page_init()
  {
    register_setting(
        'katalys_shop_merchant_group', // option_group
        'katalys_shop_merchant', // option_name
        [$this, 'oneO_settings_sanitize'] // sanitize_callback
    );

    add_settings_section(
        'oneO_settings_setting_section', // id
        'Settings', // title
        [$this, 'oneO_settings_section_info'], // callback
        'oneO-settings-admin' // page
    );
    add_settings_section(
        'oneO_settings_endpoint_section', // id
        'Endpoint', // title
        [$this, 'oneO_settings_section_endpoint'], // callback
        'oneO-settings-admin-two' // page
    );

    add_settings_field(
        'integration_id', // id
        'Integration ID', // title
        [$this, 'integration_id_callback'], // callback
        'oneO-settings-admin', // page
        'oneO_settings_setting_section' // section
    );

    add_settings_field(
        'graphql_endpoint', // id
        'GraphQL Endpoint', // title
        [$this, 'graphql_callback'], // callback
        'oneO-settings-admin', // page
        'oneO_settings_setting_section' // section
    );

    add_settings_field(
        'public_key', // id
        'API Key', // title
        [$this, 'public_key_callback'], // callback
        'oneO-settings-admin', // page
        'oneO_settings_setting_section' // section
    );

    add_settings_field(
        'secret_key', // id
        'Shared Secret', // title
        [$this, 'secret_key_callback'], // callback
        'oneO-settings-admin', // page
        'oneO_settings_setting_section' // section
    );

    add_settings_field(
        'api_endpoint', // id
        'Store API Endpoint', // title
        [$this, 'api_endpoint_callback'], // callback
        'oneO-settings-admin-two', // page
        'oneO_settings_endpoint_section', // section
        ['class' => 'api_endpoint'] // args
    );
  }

  public function oneO_settings_sanitize($input)
  {
    $sanitary_values = [];
    if (isset($input['integration_id'])) {
      $sanitary_values['integration_id'] = sanitize_text_field($input['integration_id']);
    }
    if (isset($input['public_key'])) {
      $sanitary_values['public_key'] = sanitize_text_field($input['public_key']);
    }
    if (isset($input['api_endpoint'])) {
      $sanitary_values['api_endpoint'] = sanitize_text_field($input['api_endpoint']);
    }
    if (isset($input['graphql_endpoint'])) {
      $sanitary_values['graphql_endpoint'] = sanitize_text_field($input['graphql_endpoint']);
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
    ?>
      <input class="regular-text medium-text-input" type="text" autocomplete="1o-public-key" name="katalys_shop_merchant[public_key]" id="public_key" value="<?php echo esc_attr(oneO_options()->publicKey) ?>"/>
    <?php
  }

  public function secret_key_callback()
  {
    ?>
      <input class="regular-text medium-text-input" type="password" autocomplete="1o-shared-secret" name="katalys_shop_merchant[secret_key]" id="secret_key" value="<?php echo esc_attr(oneO_options()->secretKey) ?>"/>
      <span id="secret_key-toggle" class="dashicons dashicons-visibility"></span>
      <script type="text/javascript">
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
    ?>
      <input class="regular-text medium-text-input" type="text" autocomplete="1o-integration-id" name="katalys_shop_merchant[integration_id]" id="integration_id" value="<?php echo esc_attr(oneO_options()->integrationId) ?>"/>
    <?php
  }

  public function graphql_callback()
  {
    ?>
      <input class="regular-text medium-text-input" type="text" autocomplete="1o-graphql-endpoint" name="katalys_shop_merchant[graphql_endpoint]" id="graphql_endpoint" value="<?php echo esc_attr(oneO_options()->graphqlEndpoint) ?>"/>
    <?php
  }

  public function api_endpoint_callback()
  {
    ?>
      <input class="regular-text medium-text-input" type="text" autocomplete="none"
             name="katalys_shop_merchant[api_endpoint]" id="api_endpoint" value="<?php echo esc_attr(oneO_options()->endpoint) ?>"
             disabled/>&nbsp;&nbsp;<a href="#" id="endpoint_copy">Copy</a>
      <p class="description" id="api_endpoint-description">Copy this URL to your account integration settings page in
          your Katalys Shops Admin Console.</p>
      <script type="text/javascript">
          document.querySelector('#endpoint_copy').addEventListener('click', function (e) {
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
          });
      </script>
    <?php
  }
}
