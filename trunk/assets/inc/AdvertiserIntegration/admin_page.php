<style type="text/css">
    .wrap .msg { background: #abffb8; padding:1em; }
    .wrap .err { background: #ffcc91; padding:1em; }
    .wrap > .img { float:right; max-width: 480px; width: 40%; height:auto; margin-left:2em; }
    .wrap > .img + h1 + div,
    .wrap > .img + h1 + div + div { overflow: hidden; }
    .wrap > .settings { clear:both; margin-top:1.6em; border:1px solid #fff; padding:1em 1em 0; }
    .settings label { margin:0; font-weight:bold; }
    .settings #revoffers_site_id { width:100%; max-width:16em; }
    .settings .desc { font-size:.9em; color:#e06868; margin:0; }
    .settings .pre { display:inline-block; min-width:6em; margin-right:.5em; }
    .settings .field { margin-bottom:2em; }
    .wrap > .debug { font-size:.9em; opacity:.8; margin:1em; float:right; }
    .optin { display: none; background: #f7f7f7; padding: 10px 14px; border-radius: 4px; }
    .optin p { font-size: .9em; }
</style>
<div class="wrap">
  <h1>Advertiser Integration</h1>
  <p>
    Thank you for joining our network of advertisers! This plugin
    ensures that you get the most value out of our conversion network.
  </p>
  <p>
    If you have any questions, please
    <a href="https://katalys.com/contact-us/?tab=2" target="_blank">contact us here</a>.
  </p>

  <form class="settings" method="post" action="options.php">
    <?php wp_nonce_field('update-options'); ?>
    <?php settings_fields('revoffers'); ?>
    <div class="field">
      <label for="revoffers_site_id">Katalys Site ID</label>
      <div style="margin-bottom:8px">This identifies your web property when your website is available from multiple domains.</div>
      <span class="pre">Default value:</span> <strong style="display:inline-block;padding:4px;"><?php echo esc_attr(\revoffers\getSiteId(false)); ?></strong><br/>
      <span class="pre">Your value:</span> <input type="text" id="revoffers_site_id" name="revoffers_site_id" value="<?php echo esc_attr(get_option('revoffers_site_id')); ?>" placeholder="<use default>"/>
      <?php if (\revoffers\hasCustomSiteId() && !get_option('revoffers_site_id')) { ?>
        <p class="desc">Your HTTP_HOST header does not match your WordPress <code>site_url</code>. Ask a Katalys representative if you need a value here!</p>
      <?php } else { ?>
        <p class="desc">DO NOT ENTER A VALUE HERE UNLESS DIRECTED BY A KATALYS REPRESENTATIVE!</p>
      <?php } ?>
    </div>
    <div class="field">
      <label for="revoffers_use_cron">Use Cron System</label>
      <div style="margin-bottom:8px">If activated, will use WordPress's wp-cron.php system to schedule reporting offline. Requires MySQL <code>ALTER</code> privilege to create the stateful database table.</div>
      <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) { ?>
        <div class="desc">You have disabled the wp-cron.php file. Ensure your crontab or system cron is configured correctly before activating this option.</div>
      <?php } ?>
      <span class="pre">Default value:</span> <strong style="display:inline-block;padding:4px;"><?=\revoffers\shouldUseCron(false) ? 'yes' : 'no'?></strong><br/>
      <span class="pre">Your value:</span>
      <?php $val = get_option('revoffers_use_cron'); ?>
      <select id="revoffers_use_cron" name="revoffers_use_cron">
        <option value="">auto, use cron when available</option>
        <option value="1" <?=$val ? 'selected': ''?>>yes, always</option>
        <option value="0" <?=strlen($val) && !$val ? 'selected' : ''?>>no, never</option>
      </select>
    </div>
    <?php submit_button(); ?>
  </form>

  <div class="debug">
    Debug: <a href="<?php echo admin_url('admin-ajax.php') ?>?action=revoffers_orders_debug&asText=1" download>Download order-debug file</a>
    | Alt: <a href="<?php echo admin_url('admin-ajax.php') ?>?action=revoffers_orders_debug&timeout=60" download>#2</a>
  </div>
</div>
