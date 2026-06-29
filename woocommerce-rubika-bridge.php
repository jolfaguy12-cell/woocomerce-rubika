<?php
/**
 * Plugin Name: WooCommerce Social Bridge
 * Description: Lightweight WooCommerce social publisher for Rubika and Telegram relay with queue, scheduling, and per-product controls.
 * Version: 1.5.0
 * Author: Codex
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCRB_Plugin')) {
    class WCRB_Plugin {
        const VERSION = '1.5.0';
        const VERSION_OPTION = 'wcrb_plugin_version';
        const OPTION_KEY = 'wcrb_settings';
        const LAST_SENT_OPTION = 'wcrb_last_sent_at';
        const LAST_RUNNER_PING_OPTION = 'wcrb_last_runner_ping';
        const LOG_OPTION = 'wcrb_logs';
        const CRON_HOOK = 'wcrb_process_queue_event';
        const TABLE_SUFFIX = 'wcrb_queue';

        public function __construct() {
            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));

            add_action('admin_menu', array($this, 'register_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('add_meta_boxes', array($this, 'register_product_social_meta_box'));
            add_action('save_post_product', array($this, 'save_product_social_meta'), 10, 2);
            add_action('init', array($this, 'bootstrap_queue_runner'));

            add_action('admin_post_wcrb_enqueue_all', array($this, 'handle_enqueue_all'));
            add_action('admin_post_wcrb_enqueue_single', array($this, 'handle_enqueue_single'));
            add_action('admin_post_wcrb_clear_queue', array($this, 'handle_clear_queue'));
            add_action('admin_post_wcrb_clear_logs', array($this, 'handle_clear_logs'));
            add_action('admin_post_wcrb_run_queue', array($this, 'handle_run_queue_now'));
            add_action('admin_post_wcrb_clear_database', array($this, 'handle_clear_database'));
            add_action('admin_post_wcrb_send_test_message', array($this, 'handle_send_test_message'));
            add_action('admin_post_wcrb_reset_sync_records', array($this, 'handle_reset_sync_records'));
            add_action('admin_post_wcrb_send_now_single', array($this, 'handle_send_now_single'));
            add_action('admin_post_wcrb_test_telegram_relay', array($this, 'handle_test_telegram_relay'));
            add_action('admin_post_wcrb_process_network_queue', array($this, 'handle_process_network_queue'));
            add_action('admin_post_wcrb_clear_network_failed', array($this, 'handle_clear_network_failed'));
            add_action('admin_post_wcrb_clear_network_queue', array($this, 'handle_clear_network_queue'));
            add_action('admin_post_wcrb_requeue_network_failed', array($this, 'handle_requeue_network_failed'));
            add_action('admin_post_wcrb_enqueue_unsynced_network', array($this, 'handle_enqueue_unsynced_network'));
            add_action('admin_post_wcrb_toggle_queue_pause', array($this, 'handle_toggle_queue_pause'));
            add_action('admin_post_wcrb_send_manual_message', array($this, 'handle_send_manual_message'));
            add_action('transition_post_status', array($this, 'enqueue_newly_published_product'), 10, 3);

            add_action('admin_bar_menu', array($this, 'admin_bar_publish_button'), 100);
            add_action('admin_notices', array($this, 'admin_notice'));

            add_action(self::CRON_HOOK, array($this, 'process_queue'));
            add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        }

        public function activate() {
            $this->maybe_create_table();
            $this->maybe_run_migrations();

            $defaults = $this->default_settings();
            $current = get_option(self::OPTION_KEY, array());
            update_option(self::OPTION_KEY, wp_parse_args($current, $defaults));

            $this->ensure_cron_event_scheduled();

            update_option(self::VERSION_OPTION, self::VERSION, false);

            $this->add_log('info', 'Plugin activated.', array('version' => self::VERSION));
        }

        public function deactivate() {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $this->add_log('info', 'Plugin deactivated.');
        }

        public function register_cron_schedules($schedules) {
            if (!isset($schedules['wcrb_every_minute'])) {
                $schedules['wcrb_every_minute'] = array(
                    'interval' => 60,
                    'display'  => __('Every Minute (WCRB)', 'wcrb'),
                );
            }
            return $schedules;
        }

        public function bootstrap_queue_runner() {
            $this->maybe_run_migrations();
            $this->ensure_cron_event_scheduled();
            $this->recover_stale_processing_items();
            $this->maybe_process_queue_on_request();
        }

        private function ensure_cron_event_scheduled() {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, 'wcrb_every_minute', self::CRON_HOOK);
            }
        }

        private function recover_stale_processing_items() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;

            $wpdb->query(
                "UPDATE {$table}
                SET status = 'pending', scheduled_at = UTC_TIMESTAMP(), error_message = 'Recovered from stale processing state'
                WHERE status = 'processing' AND created_at < (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)"
            );
        }

        private function maybe_process_queue_on_request() {
            if (wp_doing_cron()) {
                return;
            }

            $last_ping = (int) get_option(self::LAST_RUNNER_PING_OPTION, 0);
            if ($last_ping > 0 && (time() - $last_ping) < 45) {
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $has_pending = (int) $wpdb->get_var("SELECT COUNT(1) FROM {$table} WHERE status = 'pending' AND scheduled_at <= UTC_TIMESTAMP()");
            if ($has_pending < 1) {
                return;
            }

            update_option(self::LAST_RUNNER_PING_OPTION, time(), false);
            $this->process_queue(false);
        }

        private function default_settings() {
            return array(
                'bot_token' => 'JAIHJ0LIWGEOQKKWPBQFQKBEFSUAFZQIDYBFOTKDPUEQNSYTCAWPXPJEISIACNAP',
                'channel' => '@behdashtik_site',
                'website_url' => home_url('/'),
                'template' => "🛍️ {title}\n\n{short_description}\n\n💰 {price}\n🔗 {url}",
                'image_count' => 1,
                'excluded_images' => '',
                'telegram_excluded_images' => '',
                'interval_minutes' => 15,
                'scheduled_sending_enabled' => 1,
                'send_window_start' => '00:00',
                'send_window_end' => '23:59',
                'disable_notification' => 0,
                'enable_logs' => 1,
                'enable_plugin' => 1,
                'auto_publish_enabled' => 1,
                'block_out_of_stock' => 1,
                'queued_out_of_stock_behavior' => 'skipped',
                'max_retry_attempts' => 5,
                'retry_delay_minutes' => 10,
                'prevent_duplicates' => 1,
                'allow_manual_force_resend' => 1,
                'log_retention_limit' => 300,
                'rubika_enabled' => 1,
                'telegram_enabled' => 0,
                'telegram_relay_url' => '',
                'telegram_relay_api_key' => '',
                'telegram_hmac_secret' => '',
                'telegram_image_count' => 2,
                'telegram_template' => "🛍️ {title}

{social_text}

💰 {price}
🔗 {url}",
                'telegram_parse_mode' => 'HTML',
                'telegram_send_as_album' => 1,
                'queue_paused_rubika' => 0,
                'queue_paused_telegram' => 0,
            );
        }

        private function get_settings() {
            return wp_parse_args(get_option(self::OPTION_KEY, array()), $this->default_settings());
        }

        private function maybe_run_migrations() {
            $installed_version = get_option(self::VERSION_OPTION, '0.0.0');
            if (version_compare($installed_version, self::VERSION, '>=')) {
                return;
            }

            $this->maybe_create_table();
            $this->migrate_queue_network_columns();
            update_option(self::VERSION_OPTION, self::VERSION, false);
        }

        private function migrate_queue_network_columns() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $wpdb->query("UPDATE {$table} SET network = 'rubika' WHERE network IS NULL OR network = ''");
        }

        private function maybe_create_table() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $charset_collate = $wpdb->get_charset_collate();

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $sql = "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT UNSIGNED NOT NULL,
                network VARCHAR(20) NOT NULL DEFAULT 'rubika',
                payload_hash VARCHAR(64) NULL,
                request_id VARCHAR(80) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                last_response TEXT NULL,
                scheduled_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY status_scheduled (status, scheduled_at),
                KEY network_status (network, status),
                KEY payload_network (payload_hash, network),
                KEY product_id (product_id)
            ) {$charset_collate};";

            dbDelta($sql);
        }

        public function register_admin_menu() {
            add_submenu_page(
                'woocommerce',
                __('Social Bridge', 'wcrb'),
                __('Social Bridge', 'wcrb'),
                'manage_woocommerce',
                'wcrb-settings',
                array($this, 'render_settings_page')
            );
        }

        public function register_settings() {
            register_setting('wcrb_settings_group', self::OPTION_KEY, array($this, 'sanitize_settings'));
        }

        public function enqueue_admin_assets($hook) {
            if ($hook !== 'woocommerce_page_wcrb-settings') {
                return;
            }

            wp_enqueue_media();
            wp_register_style('wcrb-admin', false, array(), self::VERSION);
            wp_enqueue_style('wcrb-admin');
            wp_add_inline_style(
                'wcrb-admin',
                '.wcrb-wrap .nav-tab-wrapper{margin-top:16px}.wcrb-status-grid{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0}.wcrb-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:14px}.wcrb-status-grid .wcrb-card{min-width:220px}.wcrb-panel{background:#fff;border:1px solid #dcdcde;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:16px;max-width:1100px;margin-top:16px}.wcrb-network-card{margin:12px 0;max-width:980px}.wcrb-status-badge{display:inline-block;border-radius:999px;padding:2px 9px;font-weight:600;line-height:1.7}.wcrb-status-badge.is-enabled{background:#edfaef;color:#008a20}.wcrb-status-badge.is-disabled{background:#fcf0f1;color:#b32d2e}.wcrb-actions form,.wcrb-inline-action{display:inline-block;margin:0 8px 8px 0}.wcrb-secret-status{margin-inline-start:8px}.wcrb-help{max-width:720px}.wcrb-wrap textarea.code{direction:ltr}.wcrb-wrap .notice.inline{margin:10px 0 0}'
            );
            wp_register_script('wcrb-admin', '', array('jquery'), self::VERSION, true);
            wp_enqueue_script('wcrb-admin');
            wp_add_inline_script(
                'wcrb-admin',
                "jQuery(function($){
                    function parseIds(field){
                        return field.val() ? field.val().split(',').map(function(v){ return parseInt(v,10); }).filter(Boolean) : [];
                    }
                    function renderPreviews(field){
                        var target = field.data('preview');
                        var wrap = target ? $(target) : $();
                        var ids = parseIds(field);
                        wrap.empty();
                        if(!ids.length){return;}
                        ids.forEach(function(id){
                            wp.media.attachment(id).fetch().then(function(){
                                var att = wp.media.attachment(id).toJSON();
                                var src = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : (att.icon || '');
                                if(src){wrap.append('<span style=\"display:inline-block;margin:0 8px 8px 0;text-align:center\"><img src=\"'+src+'\" style=\"width:60px;height:60px;object-fit:cover;display:block;border:1px solid #ddd\"><small>#'+id+'</small></span>');}
                            });
                        });
                    }
                    $('.wcrb-media-field').each(function(){ renderPreviews($(this)); });
                    $(document).on('click', '.wcrb-pick-images', function(e){
                        e.preventDefault();
                        var button = $(this);
                        var field = $(button.data('target'));
                        var selectedIds = parseIds(field);
                        var frame = wp.media({
                            title: button.data('title') || 'انتخاب تصاویر',
                            button: { text: button.data('button') || 'انتخاب تصاویر' },
                            library: { type: 'image' },
                            multiple: true
                        });
                        frame.on('open', function(){
                            var selection = frame.state().get('selection');
                            selectedIds.forEach(function(id){
                                var attachment = wp.media.attachment(id);
                                attachment.fetch();
                                selection.add(attachment ? [attachment] : []);
                            });
                        });
                        frame.on('select', function(){
                            var ids = frame.state().get('selection').map(function(att){ return att.id; });
                            field.val(ids.join(','));
                            renderPreviews(field);
                        });
                        frame.open();
                    });
                    $(document).on('click', '.wcrb-clear-images', function(e){
                        e.preventDefault();
                        var field = $($(this).data('target'));
                        field.val('');
                        renderPreviews(field);
                    });
                });"
            );
        }

        public function sanitize_settings($input) {
            $input = is_array($input) ? $input : array();
            $current = $this->get_settings();
            $sanitized = $current;
            $active_tab = sanitize_key($input['_active_tab'] ?? 'all');

            if (array_key_exists('bot_token', $input)) {
                $bot_token_input = sanitize_text_field($input['bot_token']);
                if ($bot_token_input !== '') {
                    $sanitized['bot_token'] = $bot_token_input;
                }
            }
            if (array_key_exists('channel', $input)) {
                $sanitized['channel'] = sanitize_text_field($input['channel']);
            }

            if (array_key_exists('website_url', $input)) {
                $sanitized['website_url'] = esc_url_raw($input['website_url']);
            }
            if (array_key_exists('template', $input)) {
                $sanitized['template'] = wp_kses_post($input['template']);
            }
            if (array_key_exists('image_count', $input)) {
                $sanitized['image_count'] = max(0, absint($input['image_count']));
            }
            if (array_key_exists('excluded_images', $input)) {
                $sanitized['excluded_images'] = implode(',', array_filter(array_map('absint', explode(',', (string) $input['excluded_images']))));
            }
            if (array_key_exists('telegram_excluded_images', $input)) {
                $sanitized['telegram_excluded_images'] = implode(',', array_filter(array_map('absint', explode(',', (string) $input['telegram_excluded_images']))));
            }
            if (array_key_exists('interval_minutes', $input)) {
                $sanitized['interval_minutes'] = max(1, absint($input['interval_minutes']));
            }
            if (array_key_exists('max_retry_attempts', $input)) {
                $sanitized['max_retry_attempts'] = max(1, min(20, absint($input['max_retry_attempts'])));
            }
            if (array_key_exists('retry_delay_minutes', $input)) {
                $sanitized['retry_delay_minutes'] = max(1, min(1440, absint($input['retry_delay_minutes'])));
            }
            if (array_key_exists('log_retention_limit', $input)) {
                $sanitized['log_retention_limit'] = max(50, min(5000, absint($input['log_retention_limit'])));
            }
            if (array_key_exists('send_window_start', $input)) {
                $sanitized['send_window_start'] = preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $input['send_window_start']) ? $input['send_window_start'] : '00:00';
            }
            if (array_key_exists('send_window_end', $input)) {
                $sanitized['send_window_end'] = preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $input['send_window_end']) ? $input['send_window_end'] : '23:59';
            }
            if (array_key_exists('queued_out_of_stock_behavior', $input)) {
                $behavior = sanitize_key($input['queued_out_of_stock_behavior']);
                $sanitized['queued_out_of_stock_behavior'] = in_array($behavior, array('skipped', 'failed'), true) ? $behavior : 'skipped';
            }

            $checkbox_tabs = array(
                'enable_plugin' => 'general',
                'auto_publish_enabled' => 'general',
                'block_out_of_stock' => 'general',
                'prevent_duplicates' => 'general',
                'allow_manual_force_resend' => 'general',
                'enable_logs' => 'general',
                'scheduled_sending_enabled' => 'general',
                'rubika_enabled' => 'rubika',
                'disable_notification' => 'rubika',
                'telegram_enabled' => 'telegram',
                'telegram_send_as_album' => 'telegram',
            );
            foreach ($checkbox_tabs as $field => $tab) {
                if ($active_tab === 'all' || $active_tab === $tab || array_key_exists($field, $input)) {
                    $sanitized[$field] = !empty($input[$field]) ? 1 : 0;
                }
            }

            if (array_key_exists('telegram_relay_url', $input)) {
                $sanitized['telegram_relay_url'] = esc_url_raw($input['telegram_relay_url']);
            }
            if (array_key_exists('telegram_relay_api_key', $input)) {
                $api_key_input = sanitize_text_field($input['telegram_relay_api_key']);
                if ($api_key_input !== '') {
                    $sanitized['telegram_relay_api_key'] = $api_key_input;
                }
            }
            if (array_key_exists('telegram_hmac_secret', $input)) {
                $hmac_input = sanitize_text_field($input['telegram_hmac_secret']);
                if ($hmac_input !== '') {
                    $sanitized['telegram_hmac_secret'] = $hmac_input;
                }
            }
            if (array_key_exists('telegram_image_count', $input)) {
                $sanitized['telegram_image_count'] = max(0, min(10, absint($input['telegram_image_count'])));
            }
            if (array_key_exists('telegram_template', $input)) {
                $sanitized['telegram_template'] = wp_kses_post($input['telegram_template']);
            }
            if (array_key_exists('telegram_parse_mode', $input)) {
                $parse_mode = strtoupper(sanitize_text_field($input['telegram_parse_mode']));
                $sanitized['telegram_parse_mode'] = in_array($parse_mode, array('HTML', 'MARKDOWN', 'NONE'), true) ? $parse_mode : 'HTML';
            }

            unset($sanitized['_active_tab']);
            return wp_parse_args($sanitized, $this->default_settings());
        }

        public function render_settings_page() {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            $settings = $this->get_settings();
            $tabs = array(
                'general' => __('General', 'wcrb'),
                'rubika' => __('Rubika', 'wcrb'),
                'telegram' => __('Telegram', 'wcrb'),
                'manual' => __('Manual Message', 'wcrb'),
                'logs' => __('Logs / Diagnostics', 'wcrb'),
            );
            $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
            if (!isset($tabs[$active_tab])) {
                $active_tab = 'general';
            }
            $network_filter = isset($_GET['network_filter']) ? $this->normalize_network(sanitize_key(wp_unslash($_GET['network_filter']))) : '';
            $settings_page = admin_url('admin.php?page=wcrb-settings');
            list($synced, $unsynced) = $this->product_sync_counts();
            $queue_stats = $this->queue_stats();
            $network_queue_stats = $this->queue_network_stats();
            $logs = $this->get_logs($network_filter);
            $rubika_ready = $this->network_has_required_settings('rubika');
            $telegram_ready = $this->network_has_required_settings('telegram');
            ?>
            <div class="wrap wcrb-wrap">
                <h1><?php esc_html_e('WooCommerce Social Bridge', 'wcrb'); ?></h1>
                <p class="description"><?php esc_html_e('Lightweight WooCommerce publisher for Rubika, Telegram relay, and future social networks.', 'wcrb'); ?></p>
                <nav class="nav-tab-wrapper" style="margin-top:16px;">
                    <?php foreach ($tabs as $tab_key => $tab_label) : ?>
                        <a class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('tab' => $tab_key), $settings_page)); ?>"><?php echo esc_html($tab_label); ?></a>
                    <?php endforeach; ?>
                </nav>

                <div class="wcrb-status-grid">
                    <div class="wcrb-card">
                        <strong><?php esc_html_e('Plugin status', 'wcrb'); ?></strong><br>
                        <?php echo $this->status_badge(!empty($settings['enable_plugin'])); ?>
                    </div>
                    <div class="wcrb-card">
                        <strong><?php esc_html_e('Rubika', 'wcrb'); ?></strong><br>
                        <?php echo $this->status_badge(!empty($settings['rubika_enabled']) && $rubika_ready, $rubika_ready ? '' : __('Missing settings', 'wcrb')); ?>
                    </div>
                    <div class="wcrb-card">
                        <strong><?php esc_html_e('Telegram', 'wcrb'); ?></strong><br>
                        <?php echo $this->status_badge(!empty($settings['telegram_enabled']) && $telegram_ready, $telegram_ready ? '' : __('Missing relay settings', 'wcrb')); ?>
                    </div>
                    <div class="wcrb-card">
                        <strong><?php esc_html_e('Published products', 'wcrb'); ?></strong><br>
                        <?php echo esc_html(sprintf(__('Synced: %1$d | Unsynced: %2$d', 'wcrb'), $synced, $unsynced)); ?>
                    </div>
                </div>

                <?php if ($active_tab === 'general') : ?>
                    <form method="post" action="options.php" class="wcrb-panel">
                        <?php settings_fields('wcrb_settings_group'); ?>
                        <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[_active_tab]" value="general">
                        <h2><?php esc_html_e('General publishing controls', 'wcrb'); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><?php esc_html_e('Enable plugin', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_plugin]" value="1" <?php checked((int) $settings['enable_plugin'], 1); ?>> <?php esc_html_e('Allow queue and send actions for all networks', 'wcrb'); ?></label></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Automatic publishing for new products', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_publish_enabled]" value="1" <?php checked((int) $settings['auto_publish_enabled'], 1); ?>> <?php esc_html_e('Automatically queue newly published products for enabled networks', 'wcrb'); ?></label></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Do not publish out-of-stock products', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[block_out_of_stock]" value="1" <?php checked((int) $settings['block_out_of_stock'], 1); ?>> <?php esc_html_e('Block automatic, queued, and manual sends when the product is out of stock', 'wcrb'); ?></label></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Queued item becomes out of stock', 'wcrb'); ?></th><td><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[queued_out_of_stock_behavior]"><option value="skipped" <?php selected($settings['queued_out_of_stock_behavior'], 'skipped'); ?>><?php esc_html_e('Mark as skipped', 'wcrb'); ?></option><option value="failed" <?php selected($settings['queued_out_of_stock_behavior'], 'failed'); ?>><?php esc_html_e('Mark as failed', 'wcrb'); ?></option></select><p class="description"><?php esc_html_e('The item is not published and the reason is logged.', 'wcrb'); ?></p></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Publish interval', 'wcrb'); ?></th><td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[interval_minutes]" value="<?php echo esc_attr($settings['interval_minutes']); ?>"> <?php esc_html_e('minutes between queue sends', 'wcrb'); ?></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Retry policy', 'wcrb'); ?></th><td><input type="number" min="1" max="20" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_retry_attempts]" value="<?php echo esc_attr($settings['max_retry_attempts']); ?>"> <?php esc_html_e('max attempts', 'wcrb'); ?> &nbsp; <input type="number" min="1" max="1440" name="<?php echo esc_attr(self::OPTION_KEY); ?>[retry_delay_minutes]" value="<?php echo esc_attr($settings['retry_delay_minutes']); ?>"> <?php esc_html_e('retry delay minutes', 'wcrb'); ?></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Scheduled sending', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[scheduled_sending_enabled]" value="1" <?php checked((int) $settings['scheduled_sending_enabled'], 1); ?>> <?php esc_html_e('Only process automatic queues during the allowed sending window for all messengers', 'wcrb'); ?></label><p class="description"><?php echo esc_html(sprintf(__('Current WordPress time: %s. Manual direct product sends bypass the queue schedule; automatic queue processing respects it.', 'wcrb'), current_time('Y-m-d H:i'))); ?></p></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Allowed sending window', 'wcrb'); ?></th><td><input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[send_window_start]" value="<?php echo esc_attr($settings['send_window_start']); ?>"> - <input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[send_window_end]" value="<?php echo esc_attr($settings['send_window_end']); ?>"><p class="description"><?php esc_html_e('Applies to Rubika, Telegram, and future network queues when scheduled sending is enabled.', 'wcrb'); ?></p></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Duplicate-send prevention', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[prevent_duplicates]" value="1" <?php checked((int) $settings['prevent_duplicates'], 1); ?>> <?php esc_html_e('Prevent automatic resend when the payload hash was already sent', 'wcrb'); ?></label><br><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_manual_force_resend]" value="1" <?php checked((int) $settings['allow_manual_force_resend'], 1); ?>> <?php esc_html_e('Allow manual actions to force resend changed or duplicate payloads', 'wcrb'); ?></label></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Logging', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_logs]" value="1" <?php checked((int) $settings['enable_logs'], 1); ?>> <?php esc_html_e('Enable safe diagnostics logs', 'wcrb'); ?></label><br><input type="number" min="50" max="5000" name="<?php echo esc_attr(self::OPTION_KEY); ?>[log_retention_limit]" value="<?php echo esc_attr($settings['log_retention_limit']); ?>"> <?php esc_html_e('log lines to retain', 'wcrb'); ?></td></tr>
                        </table>
                        <?php submit_button(); ?>
                    </form>
                <?php elseif ($active_tab === 'rubika') : ?>
                    <form method="post" action="options.php" class="wcrb-panel">
                        <?php settings_fields('wcrb_settings_group'); ?>
                        <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[_active_tab]" value="rubika">
                        <h2><?php esc_html_e('Rubika settings', 'wcrb'); ?> <?php echo $this->status_badge(!empty($settings['rubika_enabled']) && $rubika_ready, $rubika_ready ? '' : __('Missing bot token or channel', 'wcrb')); ?></h2>
                        <?php if (!$rubika_ready) : ?><div class="notice notice-warning inline"><p><?php esc_html_e('Rubika bot token and channel are required before sending.', 'wcrb'); ?></p></div><?php endif; ?>
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><?php esc_html_e('Enable Rubika', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[rubika_enabled]" value="1" <?php checked((int) $settings['rubika_enabled'], 1); ?>> <?php esc_html_e('Publish to Rubika', 'wcrb'); ?></label></td></tr>
                            <tr><th scope="row"><label for="wcrb_bot_token"><?php esc_html_e('Bot Token', 'wcrb'); ?></label></th><td><input type="password" id="wcrb_bot_token" name="<?php echo esc_attr(self::OPTION_KEY); ?>[bot_token]" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($settings['bot_token'] ? __('Saved - leave blank to keep', 'wcrb') : __('Missing', 'wcrb')); ?>"><p class="description"><?php esc_html_e('Stored for Rubika only. It is not printed in the page after saving or in logs.', 'wcrb'); ?></p></td></tr>
                            <tr><th scope="row"><label for="wcrb_channel"><?php esc_html_e('Rubika Channel', 'wcrb'); ?></label></th><td><input type="text" id="wcrb_channel" name="<?php echo esc_attr(self::OPTION_KEY); ?>[channel]" class="regular-text" value="<?php echo esc_attr($settings['channel']); ?>"></td></tr>
                            <tr><th scope="row"><label for="wcrb_website_url"><?php esc_html_e('Website URL', 'wcrb'); ?></label></th><td><input type="url" id="wcrb_website_url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[website_url]" class="regular-text" value="<?php echo esc_attr($settings['website_url']); ?>"></td></tr>
                            <tr><th scope="row"><label for="wcrb_template"><?php esc_html_e('Rubika message template', 'wcrb'); ?></label></th><td><textarea id="wcrb_template" name="<?php echo esc_attr(self::OPTION_KEY); ?>[template]" rows="8" class="large-text code"><?php echo esc_textarea($settings['template']); ?></textarea><p class="description"><?php esc_html_e('Supports {title}, {short_description}, {social_text}, {price}, and {url}.', 'wcrb'); ?></p></td></tr>
                            <tr><th scope="row"><label for="wcrb_image_count"><?php esc_html_e('Rubika image count', 'wcrb'); ?></label></th><td><input type="number" min="0" id="wcrb_image_count" name="<?php echo esc_attr(self::OPTION_KEY); ?>[image_count]" value="<?php echo esc_attr($settings['image_count']); ?>"></td></tr>
                            <tr><th scope="row"><label for="wcrb_excluded_images"><?php esc_html_e('Excluded images', 'wcrb'); ?></label></th><td><input type="hidden" id="wcrb_excluded_images" class="wcrb-media-field" data-preview="#wcrb-excluded-preview" name="<?php echo esc_attr(self::OPTION_KEY); ?>[excluded_images]" value="<?php echo esc_attr($settings['excluded_images']); ?>"><p><button type="button" class="button wcrb-pick-images" data-target="#wcrb_excluded_images"><?php esc_html_e('Select from Media Library', 'wcrb'); ?></button> <button type="button" class="button-link-delete wcrb-clear-images" data-target="#wcrb_excluded_images"><?php esc_html_e('Clear selection', 'wcrb'); ?></button></p><div id="wcrb-excluded-preview"></div></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Rubika send behavior', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[disable_notification]" value="1" <?php checked((int) $settings['disable_notification'], 1); ?>> <?php esc_html_e('Disable Rubika notification when supported', 'wcrb'); ?></label></td></tr>
                        </table>
                        <?php submit_button(); ?>
                    </form>
                    <p><?php $this->render_action_button('wcrb_send_test_message', 'wcrb_send_test_message', __('Send Rubika Hello test', 'wcrb'), array(), 'secondary'); ?></p>
                    <?php $this->render_network_queue_card('rubika', $network_queue_stats['rubika']); ?>
                <?php elseif ($active_tab === 'telegram') : ?>
                    <form method="post" action="options.php" class="wcrb-panel">
                        <?php settings_fields('wcrb_settings_group'); ?>
                        <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[_active_tab]" value="telegram">
                        <h2><?php esc_html_e('Telegram relay settings', 'wcrb'); ?> <?php echo $this->status_badge(!empty($settings['telegram_enabled']) && $telegram_ready, $telegram_ready ? '' : __('Missing relay URL or API key', 'wcrb')); ?></h2>
                        <?php if (!$telegram_ready) : ?><div class="notice notice-warning inline"><p><?php esc_html_e('Telegram relay URL and API key are required. Telegram bot tokens stay on the relay server, not WordPress.', 'wcrb'); ?></p></div><?php endif; ?>
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><?php esc_html_e('Enable Telegram', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_enabled]" value="1" <?php checked((int) $settings['telegram_enabled'], 1); ?>> <?php esc_html_e('Publish to Telegram through the secure relay', 'wcrb'); ?></label></td></tr>
                            <tr><th scope="row"><label for="wcrb_telegram_relay_url"><?php esc_html_e('Relay server URL', 'wcrb'); ?></label></th><td><input type="url" id="wcrb_telegram_relay_url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_relay_url]" class="regular-text" value="<?php echo esc_attr($settings['telegram_relay_url']); ?>"><p class="description"><?php esc_html_e('Example: https://relay.example.com/send/telegram', 'wcrb'); ?></p></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Relay API key', 'wcrb'); ?></th><td><input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_relay_api_key]" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($settings['telegram_relay_api_key'] ? __('Saved - leave blank to keep', 'wcrb') : __('Missing', 'wcrb')); ?>"> <?php echo $this->status_badge(!empty($settings['telegram_relay_api_key']), !empty($settings['telegram_relay_api_key']) ? __('Saved', 'wcrb') : __('Missing', 'wcrb')); ?></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Optional HMAC secret', 'wcrb'); ?></th><td><input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_hmac_secret]" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($settings['telegram_hmac_secret'] ? __('Saved - leave blank to keep', 'wcrb') : __('Optional', 'wcrb')); ?>"> <?php echo $this->status_badge(!empty($settings['telegram_hmac_secret']), !empty($settings['telegram_hmac_secret']) ? __('Saved', 'wcrb') : __('Not set', 'wcrb')); ?></td></tr>
                            <tr><th scope="row"><label for="wcrb_telegram_image_count"><?php esc_html_e('Telegram image count', 'wcrb'); ?></label></th><td><input type="number" min="0" max="10" id="wcrb_telegram_image_count" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_image_count]" value="<?php echo esc_attr($settings['telegram_image_count']); ?>"></td></tr>
                            <tr><th scope="row"><label for="wcrb_telegram_excluded_images"><?php esc_html_e('Telegram excluded images', 'wcrb'); ?></label></th><td><input type="hidden" id="wcrb_telegram_excluded_images" class="wcrb-media-field" data-preview="#wcrb-telegram-excluded-preview" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_excluded_images]" value="<?php echo esc_attr($settings['telegram_excluded_images']); ?>"><p><button type="button" class="button wcrb-pick-images" data-target="#wcrb_telegram_excluded_images"><?php esc_html_e('Select from Media Library', 'wcrb'); ?></button> <button type="button" class="button-link-delete wcrb-clear-images" data-target="#wcrb_telegram_excluded_images"><?php esc_html_e('Clear selection', 'wcrb'); ?></button></p><div id="wcrb-telegram-excluded-preview"></div><p class="description"><?php esc_html_e('Only affects Telegram product sends and payload hashes; Rubika exclusions stay independent.', 'wcrb'); ?></p></td></tr>
                            <tr><th scope="row"><label for="wcrb_telegram_template"><?php esc_html_e('Telegram message template', 'wcrb'); ?></label></th><td><textarea id="wcrb_telegram_template" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_template]" rows="8" class="large-text code"><?php echo esc_textarea($settings['telegram_template']); ?></textarea><p class="description"><?php esc_html_e('Falls back to product social text, short description, title, price, and URL.', 'wcrb'); ?></p></td></tr>
                            <tr><th scope="row"><label for="wcrb_telegram_parse_mode"><?php esc_html_e('Telegram parse mode', 'wcrb'); ?></label></th><td><select id="wcrb_telegram_parse_mode" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_parse_mode]"><option value="HTML" <?php selected($settings['telegram_parse_mode'], 'HTML'); ?>>HTML</option><option value="MARKDOWN" <?php selected($settings['telegram_parse_mode'], 'MARKDOWN'); ?>>Markdown</option><option value="NONE" <?php selected($settings['telegram_parse_mode'], 'NONE'); ?>>None</option></select></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Album behavior', 'wcrb'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[telegram_send_as_album]" value="1" <?php checked((int) $settings['telegram_send_as_album'], 1); ?>> <?php esc_html_e('Ask relay to send multiple images as an album', 'wcrb'); ?></label></td></tr>
                        </table>
                        <?php submit_button(); ?>
                    </form>
                    <p><?php $this->render_action_button('wcrb_test_telegram_relay', 'wcrb_test_telegram_relay', __('Test Telegram relay', 'wcrb'), array(), 'secondary'); ?></p>
                    <?php $this->render_network_queue_card('telegram', $network_queue_stats['telegram']); ?>

                <?php elseif ($active_tab === 'manual') : ?>
                    <div class="wcrb-panel">
                        <h2><?php esc_html_e('Manual Social Message', 'wcrb'); ?></h2>
                        <p class="description"><?php esc_html_e('Send a custom message and selected media to one or more enabled networks immediately. Each network result is handled independently.', 'wcrb'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('wcrb_send_manual_message'); ?>
                            <input type="hidden" name="action" value="wcrb_send_manual_message">
                            <table class="form-table" role="presentation">
                                <tr><th scope="row"><label for="wcrb_manual_text"><?php esc_html_e('Custom message text', 'wcrb'); ?></label></th><td><textarea id="wcrb_manual_text" name="manual_text" rows="8" class="large-text code"></textarea><p class="description"><?php esc_html_e('Line breaks and Persian text are preserved. Safe limited HTML is accepted where supported.', 'wcrb'); ?></p></td></tr>
                                <tr><th scope="row"><?php esc_html_e('Custom images', 'wcrb'); ?></th><td><input type="hidden" id="wcrb_manual_images" class="wcrb-media-field" data-preview="#wcrb-manual-preview" name="manual_image_ids" value=""><p><button type="button" class="button wcrb-pick-images" data-target="#wcrb_manual_images"><?php esc_html_e('Select from Media Library', 'wcrb'); ?></button> <button type="button" class="button-link-delete wcrb-clear-images" data-target="#wcrb_manual_images"><?php esc_html_e('Clear selection', 'wcrb'); ?></button></p><div id="wcrb-manual-preview"></div></td></tr>
                                <tr><th scope="row"><?php esc_html_e('Target networks', 'wcrb'); ?></th><td>
                                    <label><input type="checkbox" name="networks[]" value="rubika" <?php disabled(!$this->is_network_enabled('rubika')); ?>> Rubika <?php echo $this->status_badge($this->is_network_enabled('rubika'), $this->is_network_enabled('rubika') ? __('Enabled', 'wcrb') : __('Disabled', 'wcrb')); ?></label><br>
                                    <label><input type="checkbox" name="networks[]" value="telegram" <?php disabled(!$this->is_network_enabled('telegram')); ?>> Telegram <?php echo $this->status_badge($this->is_network_enabled('telegram'), $this->is_network_enabled('telegram') ? __('Enabled', 'wcrb') : __('Disabled', 'wcrb')); ?></label>
                                </td></tr>
                            </table>
                            <?php submit_button(__('Send manual message now', 'wcrb'), 'primary'); ?>
                        </form>
                    </div>
                <?php else : ?>
                    <div class="wcrb-panel">
                        <h2><?php esc_html_e('Logs / Diagnostics', 'wcrb'); ?></h2>
                        <p><?php esc_html_e('Logs include safe network context such as product ID, queue ID, request ID, status, and sanitized response summaries. Secrets are never intentionally logged.', 'wcrb'); ?></p>
                        <p><strong><?php esc_html_e('Environment', 'wcrb'); ?></strong>: <?php echo esc_html(sprintf(__('Version %1$s | WP-Cron: %2$s | Next queue run: %3$s', 'wcrb'), self::VERSION, defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? __('disabled', 'wcrb') : __('enabled', 'wcrb'), wp_next_scheduled(self::CRON_HOOK) ? get_date_from_gmt(gmdate('Y-m-d H:i:s', wp_next_scheduled(self::CRON_HOOK))) : __('not scheduled', 'wcrb'))); ?></p>
                        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:8px;">
                            <input type="hidden" name="page" value="wcrb-settings"><input type="hidden" name="tab" value="logs">
                            <label><?php esc_html_e('Network filter', 'wcrb'); ?> <select name="network_filter"><option value=""><?php esc_html_e('All networks', 'wcrb'); ?></option><option value="rubika" <?php selected($network_filter, 'rubika'); ?>>Rubika</option><option value="telegram" <?php selected($network_filter, 'telegram'); ?>>Telegram</option></select></label>
                            <?php submit_button(__('Filter', 'wcrb'), 'secondary', 'submit', false); ?>
                        </form>
                        <p><?php $this->render_action_button('wcrb_clear_logs', 'wcrb_clear_logs', __('Clear logs', 'wcrb'), array(), 'secondary'); ?> <?php $this->render_action_button('wcrb_clear_database', 'wcrb_clear_database', __('Clear plugin database', 'wcrb'), array(), 'delete', __('This clears queue table, plugin logs/options, and sync markers. Continue?', 'wcrb')); ?></p>
                        <textarea readonly rows="18" class="large-text code"><?php echo esc_textarea(implode("\n", $logs)); ?></textarea>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }

        private function status_badge($enabled, $label = '') {
            $label = $label !== '' ? $label : ($enabled ? __('Enabled', 'wcrb') : __('Disabled', 'wcrb'));
            return sprintf(
                '<span class="wcrb-status-badge %1$s">%2$s</span>',
                $enabled ? 'is-enabled' : 'is-disabled',
                esc_html($label)
            );
        }

        private function render_action_button($action, $nonce_action, $label, $hidden = array(), $button_type = 'secondary', $confirm = '') {
            ?>
            <form class="wcrb-inline-action" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" <?php echo $confirm ? 'onsubmit="return confirm(\'' . esc_js($confirm) . '\');"' : ''; ?>>
                <?php wp_nonce_field($nonce_action); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
                <?php foreach ($hidden as $key => $value) : ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                <?php endforeach; ?>
                <?php submit_button($label, $button_type, 'submit', false); ?>
            </form>
            <?php
        }


        private function render_network_queue_card($network, $stats) {
            $network = $this->normalize_network($network);
            $label = $network === 'telegram' ? __('Telegram queue', 'wcrb') : __('Rubika queue', 'wcrb');
            $unsynced_label = $network === 'telegram' ? __('Add unsynced products to Telegram pending queue', 'wcrb') : __('Add unsynced products to Rubika pending queue', 'wcrb');
            ?>
            <?php list($network_synced, $network_unsynced) = $this->network_product_sync_counts($network); ?>
            <?php $paused = $this->is_queue_paused($network); ?>
            <div class="wcrb-card wcrb-network-card">
                <h3><?php echo esc_html($label); ?> <?php echo $this->status_badge($this->is_network_enabled($network), $this->is_network_enabled($network) ? '' : __('Disabled', 'wcrb')); ?></h3>
                <p><strong><?php esc_html_e('Published products', 'wcrb'); ?></strong>: <?php echo esc_html(sprintf(__('Synced: %1$d | Unsynced: %2$d', 'wcrb'), $network_synced, $network_unsynced)); ?></p>
                <p><strong><?php esc_html_e('Queue status', 'wcrb'); ?></strong>: <?php echo $this->status_badge(!$paused, $paused ? __('Paused', 'wcrb') : __('Running', 'wcrb')); ?></p>
                <p><?php echo esc_html(sprintf(__('Pending: %1$d | Processing: %2$d | Sent: %3$d | Failed: %4$d | Skipped: %5$d', 'wcrb'), $stats['pending'], $stats['processing'], $stats['sent'], $stats['failed'], $stats['skipped'])); ?></p>
                <p class="description"><?php esc_html_e('These actions affect only this messenger queue. Manual direct product sends bypass pause/schedule; automatic queue processing respects both.', 'wcrb'); ?></p>
                <?php $this->render_action_button('wcrb_enqueue_unsynced_network', 'wcrb_enqueue_unsynced_network_' . $network, $unsynced_label, array('network' => $network), 'primary'); ?>
                <?php $this->render_action_button('wcrb_process_network_queue', 'wcrb_process_network_queue_' . $network, __('Process this queue now', 'wcrb'), array('network' => $network), 'secondary'); ?>
                <?php if ($paused) : ?>
                    <?php $this->render_action_button('wcrb_toggle_queue_pause', 'wcrb_toggle_queue_pause_' . $network, __('Resume queue', 'wcrb'), array('network' => $network, 'paused' => 0), 'secondary'); ?>
                <?php else : ?>
                    <?php $this->render_action_button('wcrb_toggle_queue_pause', 'wcrb_toggle_queue_pause_' . $network, __('Pause queue', 'wcrb'), array('network' => $network, 'paused' => 1), 'secondary'); ?>
                <?php endif; ?>
                <?php $this->render_action_button('wcrb_clear_network_failed', 'wcrb_clear_network_failed_' . $network, __('Clear failed/skipped', 'wcrb'), array('network' => $network), 'secondary', __('Clear failed and skipped items for this network?', 'wcrb')); ?>
                <?php $this->render_action_button('wcrb_requeue_network_failed', 'wcrb_requeue_network_failed_' . $network, __('Requeue failed', 'wcrb'), array('network' => $network), 'secondary'); ?>
                <?php $this->render_action_button('wcrb_clear_network_queue', 'wcrb_clear_network_queue_' . $network, __('Clear this network queue', 'wcrb'), array('network' => $network), 'delete', __('Clear all queue items for this network?', 'wcrb')); ?>
            </div>
            <?php
        }

        public function register_product_social_meta_box() {
            add_meta_box(
                'wcrb_product_social_texts',
                __('Social publishing text', 'wcrb'),
                array($this, 'render_product_social_meta_box'),
                'product',
                'normal',
                'default'
            );
        }

        public function render_product_social_meta_box($post) {
            wp_nonce_field('wcrb_save_product_social_meta', 'wcrb_product_social_nonce');
            $general = get_post_meta($post->ID, '_wcrb_social_text', true);
            $rubika = get_post_meta($post->ID, '_wcrb_rubika_text', true);
            $telegram = get_post_meta($post->ID, '_wcrb_telegram_text', true);
            ?>
            <p><label for="wcrb_social_text"><strong><?php esc_html_e('General social media custom text', 'wcrb'); ?></strong></label></p>
            <textarea id="wcrb_social_text" name="wcrb_social_text" rows="4" class="widefat"><?php echo esc_textarea($general); ?></textarea>
            <p><label for="wcrb_rubika_text"><strong><?php esc_html_e('Rubika custom text', 'wcrb'); ?></strong></label></p>
            <textarea id="wcrb_rubika_text" name="wcrb_rubika_text" rows="4" class="widefat"><?php echo esc_textarea($rubika); ?></textarea>
            <p><label for="wcrb_telegram_text"><strong><?php esc_html_e('Telegram custom text', 'wcrb'); ?></strong></label></p>
            <textarea id="wcrb_telegram_text" name="wcrb_telegram_text" rows="4" class="widefat"><?php echo esc_textarea($telegram); ?></textarea>
            <?php
        }

        public function save_product_social_meta($post_id, $post) {
            if (!isset($_POST['wcrb_product_social_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wcrb_product_social_nonce'])), 'wcrb_save_product_social_meta')) {
                return;
            }
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            $fields = array(
                'wcrb_social_text' => '_wcrb_social_text',
                'wcrb_rubika_text' => '_wcrb_rubika_text',
                'wcrb_telegram_text' => '_wcrb_telegram_text',
            );
            foreach ($fields as $field => $meta_key) {
                $value = isset($_POST[$field]) ? sanitize_textarea_field(wp_unslash($_POST[$field])) : '';
                if ($value === '') {
                    delete_post_meta($post_id, $meta_key);
                } else {
                    update_post_meta($post_id, $meta_key, $value);
                }
            }
        }

        private function queue_stats() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $rows = $wpdb->get_results("SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status", ARRAY_A);
            $stats = array('pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0);
            foreach ($rows as $row) {
                $status = $row['status'];
                if (isset($stats[$status])) {
                    $stats[$status] = (int) $row['cnt'];
                }
            }
            return $stats;
        }

        private function queue_network_stats() {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $rows = $wpdb->get_results("SELECT network, status, COUNT(*) AS cnt FROM {$table} GROUP BY network, status", ARRAY_A);
            $stats = array(
                'rubika' => array('pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0),
                'telegram' => array('pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0),
            );
            foreach ($rows as $row) {
                $network = $this->normalize_network($row['network'] ?? 'rubika');
                $status = $row['status'];
                if (isset($stats[$network][$status])) {
                    $stats[$network][$status] = (int) $row['cnt'];
                }
            }
            return $stats;
        }


        private function network_product_sync_counts($network) {
            $network = $this->normalize_network($network);
            $products = wc_get_products(array(
                'status' => 'publish',
                'limit' => -1,
                'return' => 'objects',
            ));

            $synced = 0;
            $total = 0;
            foreach ($products as $product) {
                if (!$product || !is_callable(array($product, 'get_id'))) {
                    continue;
                }
                $total++;
                $payload_hash = $this->build_payload_hash($product, $network);
                if ($this->was_payload_sent((int) $product->get_id(), $network, $payload_hash)) {
                    $synced++;
                }
            }

            return array($synced, max(0, $total - $synced));
        }

        private function is_queue_paused($network) {
            $settings = $this->get_settings();
            return !empty($settings['queue_paused_' . $this->normalize_network($network)]);
        }

        private function paused_networks() {
            $paused = array();
            foreach (array('rubika', 'telegram') as $network) {
                if ($this->is_queue_paused($network)) {
                    $paused[] = $network;
                }
            }
            return $paused;
        }

        private function product_sync_counts() {
            $query = new WP_Query(array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'fields' => 'ids',
                'nopaging' => true,
            ));

            $synced = 0;
            $total = count($query->posts);
            foreach ($query->posts as $product_id) {
                if (get_post_meta($product_id, '_wcrb_last_sent_at', true) || get_post_meta($product_id, '_wcrb_rubika_last_sent_at', true)) {
                    $synced++;
                }
            }

            return array($synced, max(0, $total - $synced));
        }

        public function handle_enqueue_all() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_enqueue_all')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $products = wc_get_products(array(
                'status' => 'publish',
                'limit' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'return' => 'objects',
            ));

            usort($products, function($a, $b) {
                $cats_a = wp_get_post_terms($a->get_id(), 'product_cat', array('fields' => 'names'));
                $cats_b = wp_get_post_terms($b->get_id(), 'product_cat', array('fields' => 'names'));
                $cat_a = !empty($cats_a) ? implode(',', $cats_a) : 'zzzz';
                $cat_b = !empty($cats_b) ? implode(',', $cats_b) : 'zzzz';
                return strcmp($cat_a . $a->get_name(), $cat_b . $b->get_name());
            });

            $count = 0;
            foreach ($products as $product) {
                foreach ($this->get_enabled_networks() as $network) {
                    if ($this->enqueue_product($product->get_id(), $network)) {
                        $count++;
                    }
                }
            }

            $this->add_log('info', 'Bulk enqueue completed.', array('queued' => $count));
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'bulk', 'queued' => $count), admin_url('admin.php')));
            exit;
        }

        public function handle_enqueue_single() {
            if (!current_user_can('edit_products') || !check_admin_referer('wcrb_enqueue_single')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            if (!$this->is_plugin_enabled()) {
                wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'plugin_disabled'), wp_get_referer() ?: admin_url()));
                exit;
            }

            if ($product_id) {
                foreach ($this->get_enabled_networks() as $network) {
                    $this->enqueue_product($product_id, $network);
                }
                $this->add_log('info', 'Single product queued.', array('product_id' => $product_id, 'network' => 'all_enabled'));
                $this->process_queue(true);
            }

            $redirect_to = wp_get_referer() ? wp_get_referer() : admin_url('post.php?post=' . $product_id . '&action=edit');
            wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'single'), $redirect_to));
            exit;
        }

        public function handle_reset_sync_records() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_reset_sync_records')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wcrb_last_sent_at','_wcrb_rubika_last_sent_at','_wcrb_rubika_last_payload_hash','_wcrb_telegram_last_sent_at','_wcrb_telegram_last_payload_hash')");
            $this->add_log('warning', 'Synced/unsynced records reset by admin.');

            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'reset_sync'), admin_url('admin.php')));
            exit;
        }

        public function handle_clear_queue() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_clear_queue')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $wpdb->query("TRUNCATE TABLE {$table}");
            $this->add_log('warning', 'Queue cleared by admin.');

            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'clear_queue'), admin_url('admin.php')));
            exit;
        }

        public function handle_clear_logs() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_clear_logs')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            update_option(self::LOG_OPTION, array(), false);
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'clear_logs'), admin_url('admin.php')));
            exit;
        }

        public function handle_run_queue_now() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_run_queue')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $this->process_queue(true);
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'run_queue'), admin_url('admin.php')));
            exit;
        }


        public function handle_process_network_queue() {
            $network = isset($_POST['network']) ? $this->normalize_network(sanitize_key(wp_unslash($_POST['network']))) : 'rubika';
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_process_network_queue_' . $network)) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $this->process_queue(true, $network);
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'tab' => $network, 'wcrb_notice' => 'run_queue', 'network' => $network), admin_url('admin.php')));
            exit;
        }

        public function handle_clear_network_failed() {
            $network = isset($_POST['network']) ? $this->normalize_network(sanitize_key(wp_unslash($_POST['network']))) : 'rubika';
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_clear_network_failed_' . $network)) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE network = %s AND status IN ('failed','skipped')", $network));
            $this->add_log('warning', 'Failed/skipped queue items cleared by admin.', array('network' => $network, 'deleted' => (int) $deleted, 'action' => 'clear_failed_queue'));
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'tab' => $network, 'wcrb_notice' => 'clear_failed', 'network' => $network), admin_url('admin.php')));
            exit;
        }

        public function handle_clear_network_queue() {
            $network = isset($_POST['network']) ? $this->normalize_network(sanitize_key(wp_unslash($_POST['network']))) : 'rubika';
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_clear_network_queue_' . $network)) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE network = %s", $network));
            $this->add_log('warning', 'Network queue cleared by admin.', array('network' => $network, 'deleted' => (int) $deleted, 'action' => 'clear_network_queue'));
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'tab' => $network, 'wcrb_notice' => 'clear_queue', 'network' => $network), admin_url('admin.php')));
            exit;
        }

        public function handle_requeue_network_failed() {
            $network = isset($_POST['network']) ? $this->normalize_network(sanitize_key(wp_unslash($_POST['network']))) : 'rubika';
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_requeue_network_failed_' . $network)) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $updated = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'pending', attempts = 0, error_message = NULL, scheduled_at = UTC_TIMESTAMP() WHERE network = %s AND status = 'failed'", $network));
            $this->add_log('info', 'Failed queue items requeued by admin.', array('network' => $network, 'updated' => (int) $updated, 'action' => 'requeue_failed'));
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'tab' => $network, 'wcrb_notice' => 'requeue_failed', 'network' => $network), admin_url('admin.php')));
            exit;
        }




        public function handle_send_manual_message() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_send_manual_message')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $raw_text = isset($_POST['manual_text']) ? wp_unslash($_POST['manual_text']) : '';
            $text = wp_kses_post($raw_text);
            $image_ids = isset($_POST['manual_image_ids']) ? array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash($_POST['manual_image_ids']))))) : array();
            $selected = isset($_POST['networks']) && is_array($_POST['networks']) ? array_map('sanitize_key', wp_unslash($_POST['networks'])) : array();
            $networks = array_values(array_intersect(array('rubika', 'telegram'), $selected));

            if (empty($networks)) {
                wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'tab' => 'manual', 'wcrb_notice' => 'manual_no_network'), admin_url('admin.php')));
                exit;
            }
            if (trim(wp_strip_all_tags($text)) === '' && empty($image_ids)) {
                wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'tab' => 'manual', 'wcrb_notice' => 'manual_empty'), admin_url('admin.php')));
                exit;
            }

            $summary = array();
            $this->add_log('info', 'Manual message send started.', array('network' => implode(',', $networks), 'action' => 'manual_send', 'image_count' => count($image_ids), 'text_length' => strlen(wp_strip_all_tags($text))));
            foreach ($networks as $network) {
                if (!$this->is_network_enabled($network) || !$this->network_has_required_settings($network)) {
                    $summary[$network] = 'missing_settings';
                    $this->add_log('error', 'Manual message network skipped.', array('network' => $network, 'action' => 'manual_send', 'status' => 'missing_settings'));
                    continue;
                }
                $result = $network === 'telegram' ? $this->send_manual_to_telegram($text, $image_ids) : $this->send_manual_to_rubika($text, $image_ids);
                $summary[$network] = $result['success'] ? 'ok' : 'fail';
                $this->add_log($result['success'] ? 'info' : 'error', 'Manual message network result.', array('network' => $network, 'action' => 'manual_send', 'status' => $summary[$network], 'message' => $result['message']));
            }

            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'tab' => 'manual', 'wcrb_notice' => 'manual_sent', 'manual_result' => rawurlencode(wp_json_encode($summary))), admin_url('admin.php')));
            exit;
        }

        private function send_manual_to_rubika($text, $image_ids) {
            $settings = $this->get_settings();
            $plain_text = trim(wp_strip_all_tags($text));
            if (empty($image_ids)) {
                return $this->send_text_message($settings['bot_token'], array(
                    'chat_id' => $settings['channel'],
                    'text' => $plain_text,
                    'disable_notification' => (bool) $settings['disable_notification'],
                ));
            }

            foreach ($image_ids as $index => $attachment_id) {
                $upload = $this->upload_image_to_rubika($attachment_id, $settings['bot_token'], 0);
                if (!$upload['success']) {
                    return array('success' => false, 'message' => 'Rubika manual image upload failed: ' . $upload['message']);
                }
                $payload = array(
                    'chat_id' => $settings['channel'],
                    'file_id' => $upload['file_id'],
                    'text' => $index === 0 ? $plain_text : '',
                    'disable_notification' => (bool) $settings['disable_notification'],
                );
                $result = $this->send_image_message($settings['bot_token'], $payload);
                if (!$result['success']) {
                    return array('success' => false, 'message' => 'Rubika manual image send failed: ' . $result['message']);
                }
            }
            return array('success' => true, 'message' => 'Manual message sent to Rubika');
        }

        private function send_manual_to_telegram($text, $image_ids) {
            $settings = $this->get_settings();
            if (empty($settings['telegram_relay_url']) || empty($settings['telegram_relay_api_key'])) {
                return array('success' => false, 'message' => 'Telegram relay URL or API key is missing');
            }
            $request_id = $this->build_request_id(0, 'telegram');
            $formatted_text = $this->format_telegram_text($text);
            $payload = array(
                'network' => 'telegram',
                'request_id' => $request_id,
                'type' => 'manual',
                'message' => array(
                    'text' => $formatted_text,
                    'images' => $this->attachment_payloads($image_ids),
                ),
                'options' => array(
                    'image_count' => count($image_ids),
                    'parse_mode' => $settings['telegram_parse_mode'],
                    'send_as_album' => !empty($settings['telegram_send_as_album']),
                ),
            );
            $body = wp_json_encode($payload);
            if (!$body) {
                return array('success' => false, 'message' => 'Could not encode Telegram manual payload');
            }
            $headers = array('Content-Type' => 'application/json; charset=utf-8', 'X-Relay-Api-Key' => $settings['telegram_relay_api_key'], 'X-Request-Id' => $request_id);
            if (!empty($settings['telegram_hmac_secret'])) {
                $headers['X-Relay-Signature'] = hash_hmac('sha256', $body, $settings['telegram_hmac_secret']);
            }
            $response = wp_remote_post($settings['telegram_relay_url'], array('timeout' => 30, 'headers' => $headers, 'body' => $body));
            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }
            $status_code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            if ($status_code < 200 || $status_code >= 300) {
                return array('success' => false, 'message' => 'Relay HTTP ' . $status_code . ': ' . mb_substr(wp_strip_all_tags($raw_body), 0, 200));
            }
            $decoded = json_decode($raw_body, true);
            if (is_array($decoded) && isset($decoded['success']) && !$decoded['success']) {
                return array('success' => false, 'message' => !empty($decoded['error']) ? sanitize_text_field($decoded['error']) : 'Telegram relay returned success=false');
            }
            return array('success' => true, 'message' => 'Manual message sent to Telegram relay');
        }

        private function attachment_payloads($image_ids) {
            $images = array();
            foreach ($image_ids as $image_id) {
                $url = wp_get_attachment_url($image_id);
                if (!$url) {
                    continue;
                }
                $images[] = array('id' => (int) $image_id, 'url' => esc_url_raw($url), 'mime' => get_post_mime_type($image_id) ?: 'image/jpeg');
            }
            return $images;
        }

        public function handle_toggle_queue_pause() {
            $network = isset($_POST['network']) ? $this->normalize_network(sanitize_key(wp_unslash($_POST['network']))) : 'rubika';
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_toggle_queue_pause_' . $network)) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $paused = !empty($_POST['paused']) ? 1 : 0;
            $settings = $this->get_settings();
            $settings['queue_paused_' . $network] = $paused;
            update_option(self::OPTION_KEY, $settings, false);

            $this->add_log($paused ? 'warning' : 'info', $paused ? 'Network queue paused by admin.' : 'Network queue resumed by admin.', array(
                'network' => $network,
                'action' => $paused ? 'pause_queue' : 'resume_queue',
                'status' => $paused ? 'paused' : 'running',
            ));

            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'tab' => $network, 'wcrb_notice' => $paused ? 'queue_paused' : 'queue_resumed', 'network' => $network), admin_url('admin.php')));
            exit;
        }

        public function handle_enqueue_unsynced_network() {
            $network = isset($_POST['network']) ? $this->normalize_network(sanitize_key(wp_unslash($_POST['network']))) : 'rubika';
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_enqueue_unsynced_network_' . $network)) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $result = $this->enqueue_unsynced_products_for_network($network);
            $this->add_log('info', 'Unsynced products scanned for network queue.', array_merge(array('network' => $network, 'action' => 'enqueue_unsynced'), $result));
            wp_safe_redirect(add_query_arg(array_merge(array('page' => 'wcrb-settings', 'tab' => $network, 'wcrb_notice' => 'unsynced_network', 'network' => $network), $result), admin_url('admin.php')));
            exit;
        }

        public function handle_clear_database() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_clear_database')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            $wpdb->query("TRUNCATE TABLE {$table}");

            delete_option(self::LAST_SENT_OPTION);
            delete_option(self::LAST_RUNNER_PING_OPTION);
            delete_option(self::LOG_OPTION);

            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wcrb_last_sent_at','_wcrb_rubika_last_sent_at','_wcrb_rubika_last_payload_hash','_wcrb_telegram_last_sent_at','_wcrb_telegram_last_payload_hash')");
            $this->add_log('warning', 'Plugin database data cleared by admin.');

            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'clear_database'), admin_url('admin.php')));
            exit;
        }

        public function handle_send_test_message() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_send_test_message')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $settings = $this->get_settings();
            $payload = array(
                'chat_id' => $settings['channel'],
                'text' => 'Hello from WooCommerce Social Bridge 👋',
                'disable_notification' => (bool) $settings['disable_notification'],
            );
            $result = $this->rubika_api_request($settings['bot_token'], 'sendMessage', $payload);
            if (!$result['success'] && strpos($result['message'], 'INVALID_INPUT') !== false) {
                $payload = array(
                    'chat_id' => $settings['channel'],
                    'text' => 'Hello from WooCommerce Social Bridge 👋',
                );
                $result = $this->rubika_api_request($settings['bot_token'], 'sendMessage', $payload);
            }

            if ($result['success']) {
                $this->add_log('info', 'Test message sent successfully.');
                wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'test_ok'), admin_url('admin.php')));
                exit;
            }

            $this->add_log('error', 'Test message failed.', array('message' => $result['message']));
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'test_fail'), admin_url('admin.php')));
            exit;
        }

        public function handle_test_telegram_relay() {
            if (!current_user_can('manage_woocommerce') || !check_admin_referer('wcrb_test_telegram_relay')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            $settings = $this->get_settings();
            if (empty($settings['telegram_relay_url']) || empty($settings['telegram_relay_api_key'])) {
                $this->add_log('error', 'Telegram relay test failed.', array('network' => 'telegram', 'message' => 'Relay URL or API key is missing'));
                wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'telegram_test_fail'), admin_url('admin.php')));
                exit;
            }

            $request_id = $this->build_request_id(0, 'telegram');
            $body = wp_json_encode(array('network' => 'telegram', 'request_id' => $request_id, 'action' => 'ping'));
            $headers = array(
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Relay-Api-Key' => $settings['telegram_relay_api_key'],
                'X-Request-Id' => $request_id,
            );
            if (!empty($settings['telegram_hmac_secret'])) {
                $headers['X-Relay-Signature'] = hash_hmac('sha256', $body, $settings['telegram_hmac_secret']);
            }

            $response = wp_remote_post($settings['telegram_relay_url'], array('timeout' => 15, 'headers' => $headers, 'body' => $body));
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) {
                $message = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
                $this->add_log('error', 'Telegram relay test failed.', array('network' => 'telegram', 'request_id' => $request_id, 'message' => $message));
                wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'telegram_test_fail'), admin_url('admin.php')));
                exit;
            }

            $this->add_log('info', 'Telegram relay test succeeded.', array('network' => 'telegram', 'request_id' => $request_id));
            wp_safe_redirect(add_query_arg(array('page' => 'wcrb-settings', 'wcrb_notice' => 'telegram_test_ok'), admin_url('admin.php')));
            exit;
        }

        public function handle_send_now_single() {
            if (!current_user_can('edit_products') || !check_admin_referer('wcrb_send_now_single')) {
                wp_die(esc_html__('Not allowed.', 'wcrb'));
            }

            if (!$this->is_plugin_enabled()) {
                wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'plugin_disabled'), wp_get_referer() ?: admin_url()));
                exit;
            }

            $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            $network = isset($_GET['network']) ? sanitize_key($_GET['network']) : 'rubika';
            $settings = $this->get_settings();
            $force_resend = !empty($settings['allow_manual_force_resend']);
            if ($product_id) {
                $networks = $network === 'all' ? $this->get_enabled_networks() : array($network);
                $all_success = true;
                foreach ($networks as $current_network) {
                    $result = $this->send_product_to_network($product_id, $current_network, $force_resend);
                    if ($result['success']) {
                        $payload_hash = $this->build_payload_hash(wc_get_product($product_id), $current_network);
                        update_post_meta($product_id, $this->sent_meta_key($current_network), current_time('mysql'));
                        update_post_meta($product_id, $this->sent_hash_meta_key($current_network), $payload_hash);
                        if ($current_network === 'rubika') {
                            update_post_meta($product_id, '_wcrb_last_sent_at', current_time('mysql'));
                        }
                    } else {
                        $all_success = false;
                        $this->add_log('error', 'Direct send failed.', array('product_id' => $product_id, 'network' => $current_network, 'message' => $result['message']));
                    }
                }
                if ($all_success) {
                    wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'direct_ok'), wp_get_referer() ?: admin_url()));
                    exit;
                }
            }

            wp_safe_redirect(add_query_arg(array('wcrb_notice' => 'direct_fail'), wp_get_referer() ?: admin_url()));
            exit;
        }

        public function enqueue_newly_published_product($new_status, $old_status, $post) {
            if (!$this->is_plugin_enabled()) {
                return;
            }

            $settings = $this->get_settings();
            if (empty($settings['auto_publish_enabled'])) {
                return;
            }

            if (!($post instanceof WP_Post) || $post->post_type !== 'product') {
                return;
            }

            if ($old_status === 'publish' || $new_status !== 'publish') {
                return;
            }

            foreach ($this->get_enabled_networks() as $network) {
                if ($this->enqueue_product((int) $post->ID, $network)) {
                    $this->add_log('info', 'Newly published product auto-queued.', array('product_id' => (int) $post->ID, 'network' => $network));
                }
            }
        }

        private function can_publish_product($product_id, $network = 'rubika', $manual = false) {
            $network = $this->normalize_network($network);
            if (!$this->is_plugin_enabled()) {
                return array('allowed' => false, 'message' => 'Plugin is disabled');
            }
            if (!$this->is_network_enabled($network)) {
                return array('allowed' => false, 'message' => ucfirst($network) . ' is disabled');
            }
            if (!$this->network_has_required_settings($network)) {
                return array('allowed' => false, 'message' => ucfirst($network) . ' required settings are missing');
            }

            $post = get_post($product_id);
            if (!$post || $post->post_type !== 'product' || $post->post_status !== 'publish') {
                return array('allowed' => false, 'message' => 'Product is not published');
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                return array('allowed' => false, 'message' => 'Invalid product');
            }

            $settings = $this->get_settings();
            if (!empty($settings['block_out_of_stock']) && !$product->is_in_stock()) {
                return array('allowed' => false, 'message' => 'Out-of-stock products are blocked by settings');
            }

            return array('allowed' => true, 'message' => 'OK');
        }

        private function network_has_required_settings($network) {
            $settings = $this->get_settings();
            $network = $this->normalize_network($network);
            if ($network === 'telegram') {
                return !empty($settings['telegram_relay_url']) && !empty($settings['telegram_relay_api_key']);
            }
            return !empty($settings['bot_token']) && !empty($settings['channel']);
        }


        private function enqueue_unsynced_products_for_network($network) {
            $network = $this->normalize_network($network);
            $result = array(
                'scanned' => 0,
                'added' => 0,
                'skipped_synced' => 0,
                'skipped_out_of_stock' => 0,
                'skipped_pending' => 0,
                'skipped_invalid' => 0,
                'errors' => 0,
                'unsynced_found' => 0,
            );

            $products = wc_get_products(array(
                'status' => 'publish',
                'limit' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'return' => 'objects',
            ));

            foreach ($products as $product) {
                $result['scanned']++;
                if (!$product || !is_callable(array($product, 'get_id'))) {
                    $result['skipped_invalid']++;
                    continue;
                }

                $product_id = (int) $product->get_id();
                $payload_hash = $this->build_payload_hash($product, $network);
                if ($this->was_payload_sent($product_id, $network, $payload_hash)) {
                    $result['skipped_synced']++;
                    continue;
                }
                $result['unsynced_found']++;

                $can_publish = $this->can_publish_product($product_id, $network, false);
                if (!$can_publish['allowed']) {
                    if (strpos($can_publish['message'], 'Out-of-stock') !== false) {
                        $result['skipped_out_of_stock']++;
                    } else {
                        $result['skipped_invalid']++;
                    }
                    $this->add_log('warning', 'Unsynced product not queued.', array('product_id' => $product_id, 'network' => $network, 'action' => 'enqueue_unsynced', 'status' => 'skipped', 'message' => $can_publish['message']));
                    continue;
                }

                if ($this->queue_has_pending_payload($product_id, $network, $payload_hash)) {
                    $result['skipped_pending']++;
                    continue;
                }

                if ($this->enqueue_product($product_id, $network, true)) {
                    $result['added']++;
                } else {
                    $result['errors']++;
                }
            }

            return $result;
        }

        private function queue_has_pending_payload($product_id, $network, $payload_hash) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            return (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND network = %s AND payload_hash = %s AND status IN ('pending','processing') LIMIT 1",
                $product_id,
                $this->normalize_network($network),
                $payload_hash
            ));
        }

        private function enqueue_product($product_id, $network = 'rubika', $force = false) {
            $network = $this->normalize_network($network);
            $can_publish = $this->can_publish_product($product_id, $network, false);
            if (!$can_publish['allowed']) {
                $this->add_log('warning', 'Product not queued.', array('product_id' => $product_id, 'network' => $network, 'action' => 'enqueue', 'status' => 'blocked', 'message' => $can_publish['message']));
                return false;
            }

            $product = wc_get_product($product_id);
            $payload_hash = $this->build_payload_hash($product, $network);
            $settings = $this->get_settings();
            if (!$force && !empty($settings['prevent_duplicates']) && $this->was_payload_sent($product_id, $network, $payload_hash)) {
                $this->add_log('info', 'Duplicate payload prevented from queueing.', array(
                    'product_id' => $product_id,
                    'network' => $network,
                    'payload_hash' => $payload_hash,
                ));
                return false;
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;

            $already = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND network = %s AND payload_hash = %s AND status IN ('pending','processing') LIMIT 1",
                $product_id,
                $network,
                $payload_hash
            ));

            if ($already) {
                return false;
            }

            $request_id = $this->build_request_id($product_id, $network);
            $result = $wpdb->insert(
                $table,
                array(
                    'product_id' => $product_id,
                    'network' => $network,
                    'payload_hash' => $payload_hash,
                    'request_id' => $request_id,
                    'status' => 'pending',
                    'attempts' => 0,
                    'scheduled_at' => current_time('mysql', 1),
                    'created_at' => current_time('mysql', 1),
                ),
                array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );

            return (bool) $result;
        }

        public function process_queue($force = false, $network = '') {
            if (!$this->is_plugin_enabled()) {
                return;
            }

            $network = $network !== '' ? $this->normalize_network($network) : '';
            if ($network && $this->is_queue_paused($network)) {
                $this->add_log('info', 'Queue processing skipped because network queue is paused.', array('network' => $network, 'action' => 'queue_process', 'status' => 'skipped', 'reason' => 'queue_paused'));
                return;
            }

            if (!$force && !$this->is_in_send_window()) {
                $this->add_log('info', 'Queue paused: outside send window.', array('network' => $network ?: 'all', 'action' => 'process_queue', 'status' => 'paused'));
                return;
            }

            $settings = $this->get_settings();
            $last_sent = (int) get_option(self::LAST_SENT_OPTION, 0);
            $min_gap = max(1, absint($settings['interval_minutes'])) * 60;
            if (!$force && $last_sent > 0 && (time() - $last_sent) < $min_gap) {
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_SUFFIX;
            if ($network) {
                $item = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status = 'pending' AND network = %s AND scheduled_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT 1",
                    $network
                ));
            } else {
                $paused_networks = $this->paused_networks();
                if (count($paused_networks) >= 2) {
                    $this->add_log('info', 'Queue processing skipped because all network queues are paused.', array('network' => 'all', 'action' => 'queue_process', 'status' => 'skipped', 'reason' => 'queue_paused'));
                    return;
                }
                if (!empty($paused_networks)) {
                    $placeholders = implode(',', array_fill(0, count($paused_networks), '%s'));
                    $item = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$table} WHERE status = 'pending' AND network NOT IN ({$placeholders}) AND scheduled_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT 1",
                        $paused_networks
                    ));
                } else {
                    $item = $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'pending' AND scheduled_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT 1");
                }
            }

            if (!$item) {
                return;
            }

            $claimed = (bool) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'processing' WHERE id = %d AND status = 'pending'",
                $item->id
            ));
            if (!$claimed) {
                return;
            }

            $network = $this->normalize_network($item->network ?? 'rubika');
            $product_id = (int) $item->product_id;
            $stock_check = $this->can_publish_product($product_id, $network, false);
            if (!$stock_check['allowed']) {
                $status = !empty($settings['queued_out_of_stock_behavior']) && $settings['queued_out_of_stock_behavior'] === 'failed' ? 'failed' : 'skipped';
                $wpdb->update(
                    $table,
                    array(
                        'status' => $status,
                        'error_message' => sanitize_text_field($stock_check['message']),
                        'last_response' => sanitize_text_field($stock_check['message']),
                    ),
                    array('id' => $item->id),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
                $this->add_log($status === 'failed' ? 'error' : 'warning', 'Queue item not published.', array(
                    'product_id' => $product_id,
                    'network' => $network,
                    'queue_id' => (int) $item->id,
                    'action' => 'process_queue',
                    'status' => $status,
                    'message' => $stock_check['message'],
                ));
                return;
            }

            $payload_hash = !empty($item->payload_hash) ? $item->payload_hash : $this->build_payload_hash(wc_get_product($product_id), $network);
            $sent = $this->send_product_to_network($product_id, $network, false, $payload_hash, $item->request_id ?? '');
            if ($sent['success']) {
                $wpdb->update(
                    $table,
                    array('status' => 'sent', 'sent_at' => current_time('mysql', 1), 'error_message' => null, 'last_response' => sanitize_text_field($sent['message'] ?? 'OK')),
                    array('id' => $item->id),
                    array('%s', '%s', '%s', '%s'),
                    array('%d')
                );
                update_option(self::LAST_SENT_OPTION, time(), false);
                update_post_meta($product_id, $this->sent_meta_key($network), current_time('mysql'));
                update_post_meta($product_id, $this->sent_hash_meta_key($network), $payload_hash);
                if ($network === 'rubika') {
                    update_post_meta($product_id, '_wcrb_last_sent_at', current_time('mysql'));
                }
                $this->add_log('info', 'Product sent.', array('product_id' => $product_id, 'network' => $network, 'queue_id' => (int) $item->id, 'action' => 'process_queue', 'status' => 'sent', 'payload_hash' => $payload_hash, 'request_id' => $item->request_id ?? '', 'result' => 'sent'));
            } else {
                $attempts = (int) $item->attempts + 1;
                $max_attempts = max(1, absint($settings['max_retry_attempts']));
                $retry_delay = max(1, absint($settings['retry_delay_minutes'])) * 60;
                $status = $attempts >= $max_attempts ? 'failed' : 'pending';
                $wpdb->update(
                    $table,
                    array(
                        'status' => $status,
                        'attempts' => $attempts,
                        'error_message' => sanitize_text_field($sent['message']),
                        'last_response' => sanitize_text_field($sent['message']),
                        'scheduled_at' => gmdate('Y-m-d H:i:s', time() + $retry_delay),
                    ),
                    array('id' => $item->id),
                    array('%s', '%d', '%s', '%s', '%s'),
                    array('%d')
                );
                $this->add_log('error', 'Send failed.', array('product_id' => $product_id, 'network' => $network, 'queue_id' => (int) $item->id, 'action' => 'process_queue', 'status' => $status, 'payload_hash' => $payload_hash, 'request_id' => $item->request_id ?? '', 'message' => $sent['message'], 'attempts' => $attempts));
            }
        }

        private function is_in_send_window() {
            $settings = $this->get_settings();
            if (empty($settings['scheduled_sending_enabled'])) {
                return true;
            }

            $start = $settings['send_window_start'];
            $end = $settings['send_window_end'];

            $now = current_time('H:i');
            if ($start <= $end) {
                return ($now >= $start && $now <= $end);
            }

            return ($now >= $start || $now <= $end);
        }

        private function send_product_to_network($product_id, $network, $force = false, $payload_hash = '', $request_id = '') {
            $network = $this->normalize_network($network);
            if (!$this->is_network_enabled($network)) {
                return array('success' => false, 'message' => ucfirst($network) . ' is disabled');
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                return array('success' => false, 'message' => 'Invalid product');
            }

            $can_publish = $this->can_publish_product($product_id, $network, true);
            if (!$can_publish['allowed']) {
                return array('success' => false, 'message' => $can_publish['message']);
            }

            $payload_hash = $payload_hash ?: $this->build_payload_hash($product, $network);
            if (!$force && !empty($this->get_settings()['prevent_duplicates']) && $this->was_payload_sent($product_id, $network, $payload_hash)) {
                $this->add_log('info', 'Duplicate payload prevented.', array(
                    'product_id' => $product_id,
                    'network' => $network,
                    'payload_hash' => $payload_hash,
                    'result' => 'duplicate_prevented',
                ));
                return array('success' => true, 'message' => 'Duplicate payload already sent');
            }

            if ($network === 'telegram') {
                return $this->send_product_to_telegram($product_id, $payload_hash, $request_id);
            }

            return $this->send_product_to_rubika($product_id);
        }

        private function send_product_to_rubika($product_id) {
            $settings = $this->get_settings();
            $product = wc_get_product($product_id);
            if (!$product || $product->get_status() !== 'publish') {
                return array('success' => false, 'message' => 'Invalid or unpublished product');
            }

            $text = $this->render_network_template($product, 'rubika');
            $images = $this->collect_images($product, (int) $settings['image_count'], $settings['excluded_images']);

            if (empty($images)) {
                $this->add_log('info', 'No product image found; sending text-only Rubika message.', array('product_id' => $product_id));
                return $this->send_text_message($settings['bot_token'], array(
                    'chat_id' => $settings['channel'],
                    'text' => $text,
                    'disable_notification' => (bool) $settings['disable_notification'],
                    'inline_keypad' => $this->build_buy_keypad($product),
                ));
            }

            foreach ($images as $index => $attachment_id) {
                $upload = $this->upload_image_to_rubika($attachment_id, $settings['bot_token'], $product_id);
                if (!$upload['success']) {
                    $this->add_log('error', 'Image upload failed; product will remain queued for retry.', array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'reason' => $upload['message'],
                    ));
                    return array('success' => false, 'message' => 'Image upload failed for attachment ' . $attachment_id . ': ' . $upload['message']);
                }

                $file_payload = array(
                    'chat_id' => $settings['channel'],
                    'file_id' => $upload['file_id'],
                    'text' => $index === 0 ? $text : '',
                    'disable_notification' => (bool) $settings['disable_notification'],
                    'inline_keypad' => $index === 0 ? $this->build_buy_keypad($product) : null,
                );

                $result = $this->send_image_message($settings['bot_token'], array_filter($file_payload, function($value) {
                    return $value !== null;
                }));
                if (!$result['success']) {
                    $this->add_log('error', 'Image send failed; product will remain queued for retry.', array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'reason' => $result['message'],
                    ));
                    return array('success' => false, 'message' => 'Image send failed for attachment ' . $attachment_id . ': ' . $result['message']);
                }

                $this->add_log('info', 'Image sent to Rubika.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'method' => $result['method'] ?? 'unknown',
                ));
            }

            return array('success' => true, 'message' => 'Sent');
        }

        private function send_image_message($token, $payload) {
            $methods = array('sendPhoto', 'sendImage', 'sendFile');
            $last_error = 'Image send failed';

            foreach ($methods as $method) {
                $result = $this->rubika_api_request($token, $method, $payload);
                if ($result['success']) {
                    $result['method'] = $method;
                    return $result;
                }
                if (strpos($result['message'], 'INVALID_INPUT') !== false) {
                    $stripped_payload = $payload;
                    unset($stripped_payload['inline_keypad'], $stripped_payload['disable_notification']);
                    $retry = $this->rubika_api_request($token, $method, $stripped_payload);
                    if ($retry['success']) {
                        $retry['method'] = $method;
                        return $retry;
                    }
                }
                $last_error = $method . ': ' . $result['message'];
            }

            return array('success' => false, 'message' => $last_error);
        }

        private function send_text_message($token, $payload) {
            $result = $this->rubika_api_request($token, 'sendMessage', $payload);
            if ($result['success']) {
                return $result;
            }

            if (strpos($result['message'], 'INVALID_INPUT') !== false) {
                $fallback_payload = array(
                    'chat_id' => $payload['chat_id'],
                    'text' => $payload['text'],
                );
                return $this->rubika_api_request($token, 'sendMessage', $fallback_payload);
            }

            return $result;
        }

        private function send_product_to_telegram($product_id, $payload_hash = '', $request_id = '') {
            $settings = $this->get_settings();
            if (empty($settings['telegram_relay_url']) || empty($settings['telegram_relay_api_key'])) {
                return array('success' => false, 'message' => 'Telegram relay URL or API key is missing');
            }

            $product = wc_get_product($product_id);
            if (!$product || $product->get_status() !== 'publish') {
                return array('success' => false, 'message' => 'Invalid or unpublished product');
            }

            $request_id = $request_id ?: $this->build_request_id($product_id, 'telegram');
            $payload = $this->build_telegram_relay_payload($product, $request_id);
            $body = wp_json_encode($payload);
            if (!$body) {
                return array('success' => false, 'message' => 'Could not encode Telegram relay payload');
            }

            $headers = array(
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Relay-Api-Key' => $settings['telegram_relay_api_key'],
                'X-Request-Id' => $request_id,
            );
            if (!empty($settings['telegram_hmac_secret'])) {
                $headers['X-Relay-Signature'] = hash_hmac('sha256', $body, $settings['telegram_hmac_secret']);
            }

            $this->add_log('info', 'Telegram relay request started.', array(
                'product_id' => $product_id,
                'network' => 'telegram',
                'payload_hash' => $payload_hash,
                'request_id' => $request_id,
            ));

            $response = wp_remote_post($settings['telegram_relay_url'], array(
                'timeout' => 30,
                'headers' => $headers,
                'body' => $body,
            ));

            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            if ($status_code < 200 || $status_code >= 300) {
                return array('success' => false, 'message' => 'Relay HTTP ' . $status_code . ': ' . mb_substr(wp_strip_all_tags($raw_body), 0, 200));
            }

            $decoded = json_decode($raw_body, true);
            if (is_array($decoded) && isset($decoded['success']) && !$decoded['success']) {
                $message = !empty($decoded['message']) ? sanitize_text_field($decoded['message']) : 'Telegram relay returned success=false';
                return array('success' => false, 'message' => $message);
            }

            $this->add_log('info', 'Telegram relay request completed.', array(
                'product_id' => $product_id,
                'network' => 'telegram',
                'payload_hash' => $payload_hash,
                'request_id' => $request_id,
                'result' => 'success',
            ));

            return array('success' => true, 'message' => 'Telegram relay accepted payload');
        }

        private function build_telegram_relay_payload($product, $request_id) {
            $settings = $this->get_settings();
            $image_ids = $this->collect_images($product, (int) $settings['telegram_image_count'], $settings['telegram_excluded_images']);
            $images = array();
            foreach ($image_ids as $image_id) {
                $url = wp_get_attachment_url($image_id);
                if (!$url) {
                    continue;
                }
                $images[] = array(
                    'id' => (int) $image_id,
                    'url' => esc_url_raw($url),
                    'mime' => get_post_mime_type($image_id) ?: 'image/jpeg',
                );
            }

            return array(
                'network' => 'telegram',
                'request_id' => $request_id,
                'product' => array(
                    'id' => $product->get_id(),
                    'title' => $product->get_name(),
                    'url' => get_permalink($product->get_id()),
                    'price' => $this->plain_product_price($product),
                    'short_description' => $this->format_telegram_text($product->get_short_description()),
                    'social_text' => $this->select_social_text($product, 'telegram'),
                    'caption' => $this->render_network_template($product, 'telegram'),
                    'images' => $images,
                ),
                'options' => array(
                    'image_count' => (int) $settings['telegram_image_count'],
                    'parse_mode' => $settings['telegram_parse_mode'],
                    'send_as_album' => !empty($settings['telegram_send_as_album']),
                ),
            );
        }



        private function format_telegram_text($text) {
            $text = (string) $text;
            $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text);
            $text = preg_replace('/<\s*\/\s*(p|div)\s*>/i', "\n\n", $text);
            $text = preg_replace('/<\s*(p|div)\b[^>]*>/i', '', $text);
            $text = preg_replace('/<\s*li\b[^>]*>/i', "\n", $text);
            $text = preg_replace('/<\s*\/\s*li\s*>/i', "\n", $text);
            $text = wp_strip_all_tags($text, false);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
            $text = str_replace(array("\r\n", "\r"), "\n", $text);
            $lines = array_map('trim', explode("\n", $text));
            $text = implode("\n", $lines);
            $text = preg_replace("/\n{3,}/", "\n\n", $text);
            return trim($text);
        }

        private function render_network_template($product, $network) {
            $settings = $this->get_settings();
            $network = $this->normalize_network($network);
            $template = $network === 'telegram' ? $settings['telegram_template'] : $settings['template'];
            $social_text = $this->select_social_text($product, $network);
            $replacements = array(
                '{title}' => $product->get_name(),
                '{short_description}' => $social_text,
                '{social_text}' => $social_text,
                '{price}' => $this->format_product_price($product),
                '{url}' => get_permalink($product->get_id()),
            );
            $rendered = strtr($template, $replacements);
            return $network === 'telegram' ? $this->format_telegram_text($rendered) : $rendered;
        }

        private function select_social_text($product, $network) {
            $product_id = $product->get_id();
            $network_text_key = $network === 'telegram' ? '_wcrb_telegram_text' : '_wcrb_rubika_text';
            $network_text = trim((string) get_post_meta($product_id, $network_text_key, true));
            if ($network_text !== '') {
                return $network === 'telegram' ? $this->format_telegram_text($network_text) : $network_text;
            }

            $general = trim((string) get_post_meta($product_id, '_wcrb_social_text', true));
            if ($general !== '') {
                return $network === 'telegram' ? $this->format_telegram_text($general) : $general;
            }

            $short_description = $network === 'telegram' ? $this->format_telegram_text($product->get_short_description()) : trim(wp_strip_all_tags($product->get_short_description()));
            if ($short_description !== '') {
                return $short_description;
            }

            return $product->get_name() . "\n" . $this->plain_product_price($product) . "\n" . get_permalink($product_id);
        }

        private function render_template($product, $settings) {
            $price = $this->format_product_price($product);

            $replacements = array(
                '{title}' => $product->get_name(),
                '{short_description}' => wp_strip_all_tags($product->get_short_description()),
                '{price}' => $price,
                '{url}' => get_permalink($product->get_id()),
            );

            return strtr($settings['template'], $replacements);
        }

        private function format_product_price($product) {
            if ($product->is_type('variable')) {
                $min_regular = $product->get_variation_regular_price('min', true);
                $min_sale = $product->get_variation_sale_price('min', true);

                if (!empty($min_sale) && $min_sale > 0 && $min_sale < $min_regular) {
                    return sprintf('🔥 %s (به‌جای ~%s~)', $this->format_toman($min_sale), $this->format_toman($min_regular));
                }

                return sprintf('💸 %s', $this->format_toman($product->get_variation_price('min', true)));
            }

            $regular = $product->get_regular_price();
            $sale = $product->get_sale_price();

            if (!empty($sale) && (float) $sale > 0 && (float) $regular > (float) $sale) {
                return sprintf('🔥 %s (به‌جای ~%s~)', $this->format_toman($sale), $this->format_toman($regular));
            }

            return sprintf('💸 %s', $this->format_toman($product->get_price()));
        }

        private function format_toman($amount) {
            $numeric = is_numeric($amount) ? (float) $amount : 0;
            return number_format_i18n($numeric) . ' تومان';
        }

        private function plain_product_price($product) {
            if ($product->is_type('variable')) {
                return $this->format_toman($product->get_variation_price('min', true));
            }
            return $this->format_toman($product->get_price());
        }

        private function collect_images($product, $limit, $excluded_images_csv) {
            $excluded = array_filter(array_map('absint', explode(',', (string) $excluded_images_csv)));
            $ids = array();

            $main_image = $product->get_image_id();
            if ($main_image) {
                $ids[] = $main_image;
            }

            $gallery = $product->get_gallery_image_ids();
            if (!empty($gallery)) {
                $ids = array_merge($ids, $gallery);
            }

            $ids = array_values(array_unique(array_filter($ids)));
            $ids = array_values(array_diff($ids, $excluded));

            if ($limit > 0) {
                $ids = array_slice($ids, 0, $limit);
            }

            return $ids;
        }

        private function upload_image_to_rubika($attachment_id, $token, $product_id = 0) {
            $original_path = get_attached_file($attachment_id);
            if (!$original_path || !file_exists($original_path)) {
                return array('success' => false, 'message' => 'Image file missing');
            }

            $prepared = $this->prepare_image_for_rubika_upload($original_path, $attachment_id, $product_id);
            if (!$prepared['success']) {
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                return array('success' => false, 'message' => $prepared['message']);
            }

            $path = $prepared['path'];
            $this->add_log('info', 'Rubika image upload started.', array(
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
                'file' => basename($path),
                'size' => filesize($path),
            ));

            $request = $this->rubika_api_request($token, 'requestSendFile', array('type' => 'Image'));
            if (!$request['success']) {
                $request = $this->rubika_api_request($token, 'requestSendFile', array('type' => 'File'));
            }
            if (!$request['success']) {
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                $this->add_log('error', 'Rubika upload URL request failed.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'reason' => $request['message'],
                ));
                return $request;
            }

            $upload_url = '';
            if (!empty($request['data']['upload_url'])) {
                $upload_url = $request['data']['upload_url'];
            } elseif (!empty($request['data']['data']['upload_url'])) {
                $upload_url = $request['data']['data']['upload_url'];
            }

            if (empty($upload_url)) {
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                return array('success' => false, 'message' => 'Could not get upload URL');
            }

            $raw_upload_body   = '';
            $upload_http_code  = 0;
            $upload_effective_url = '';

            if (function_exists('curl_init') && class_exists('CURLFile')) {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL            => $upload_url,
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_POSTFIELDS     => array(
                        'file' => new CURLFile($path, 'image/jpeg', basename($path)),
                    ),
                ));
                $curl_response        = curl_exec($curl);
                $upload_http_code     = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $upload_effective_url = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
                $curl_errno           = curl_errno($curl);
                $curl_error_str       = $curl_errno ? curl_error($curl) : '';
                curl_close($curl);

                if ($curl_response !== false) {
                    $raw_upload_body = (string) $curl_response;
                }

                if ($curl_errno || ($upload_http_code > 0 && ($upload_http_code < 200 || $upload_http_code >= 300))) {
                    $this->add_log('error', 'Rubika cURL upload non-2xx or transport error.', array(
                        'product_id'      => $product_id,
                        'attachment_id'   => $attachment_id,
                        'http_code'       => $upload_http_code,
                        'effective_url'   => ($upload_effective_url && $upload_effective_url !== $upload_url) ? $upload_effective_url : null,
                        'curl_error'      => $curl_error_str ?: null,
                        'is_html'         => (bool) preg_match('/<html/i', $raw_upload_body),
                        'response_sample' => mb_substr(wp_strip_all_tags($raw_upload_body), 0, 300),
                    ));
                }
            }

            if ($raw_upload_body === '') {
                $response = wp_remote_post($upload_url, array(
                    'timeout' => 60,
                    'body'    => array(
                        'file' => function_exists('curl_file_create') ? curl_file_create($path, 'image/jpeg', basename($path)) : '@' . $path,
                    ),
                ));

                if (is_wp_error($response)) {
                    $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                    $this->add_log('error', 'Rubika multipart image upload failed.', array(
                        'product_id'    => $product_id,
                        'attachment_id' => $attachment_id,
                        'reason'        => $response->get_error_message(),
                    ));
                    return array('success' => false, 'message' => $response->get_error_message());
                }

                $wp_http_code    = (int) wp_remote_retrieve_response_code($response);
                $raw_upload_body = wp_remote_retrieve_body($response);
                if ($upload_http_code === 0) {
                    $upload_http_code = $wp_http_code;
                }

                if ($wp_http_code < 200 || $wp_http_code >= 300) {
                    $this->add_log('error', 'Rubika wp_remote_post upload non-2xx.', array(
                        'product_id'      => $product_id,
                        'attachment_id'   => $attachment_id,
                        'http_code'       => $wp_http_code,
                        'is_html'         => (bool) preg_match('/<html/i', $raw_upload_body),
                        'response_sample' => mb_substr(wp_strip_all_tags($raw_upload_body), 0, 300),
                    ));
                }
            }

            $json    = json_decode($raw_upload_body, true);
            $file_id = $this->extract_file_id_from_upload_response($json);

            if (empty($file_id)) {
                $fallback_response = wp_remote_post($upload_url, array(
                    'timeout' => 60,
                    'headers' => array(
                        'Content-Type' => 'image/jpeg',
                    ),
                    'body'    => @file_get_contents($path),
                ));

                if (!is_wp_error($fallback_response)) {
                    $fb_http_code  = (int) wp_remote_retrieve_response_code($fallback_response);
                    $fallback_raw  = wp_remote_retrieve_body($fallback_response);
                    if ($upload_http_code === 0) {
                        $upload_http_code = $fb_http_code;
                    }
                    $fallback_json = json_decode($fallback_raw, true);
                    $file_id       = $this->extract_file_id_from_upload_response($fallback_json);
                    if (empty($file_id)) {
                        $raw_upload_body = $fallback_raw;
                        if ($fb_http_code < 200 || $fb_http_code >= 300) {
                            $this->add_log('error', 'Rubika raw-body upload fallback non-2xx.', array(
                                'product_id'      => $product_id,
                                'attachment_id'   => $attachment_id,
                                'http_code'       => $fb_http_code,
                                'is_html'         => (bool) preg_match('/<html/i', $fallback_raw),
                                'response_sample' => mb_substr(wp_strip_all_tags($fallback_raw), 0, 300),
                            ));
                        }
                    }
                }
            }

            if (empty($file_id)) {
                $body_for_log = is_string($raw_upload_body) ? $raw_upload_body : '';
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                $this->add_log('error', 'Rubika image upload returned no file_id.', array(
                    'product_id'      => $product_id,
                    'attachment_id'   => $attachment_id,
                    'http_code'       => $upload_http_code,
                    'effective_url'   => ($upload_effective_url && $upload_effective_url !== $upload_url) ? $upload_effective_url : null,
                    'is_html'         => (bool) preg_match('/<html/i', $body_for_log),
                    'response_sample' => mb_substr(wp_strip_all_tags($body_for_log), 0, 300),
                ));
                return array('success' => false, 'message' => 'No file_id in upload response (HTTP ' . $upload_http_code . '): ' . mb_substr(wp_strip_all_tags($body_for_log), 0, 200));
            }

            $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
            $this->add_log('info', 'Rubika image upload completed.', array(
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
            ));

            return array('success' => true, 'file_id' => $file_id);
        }

        private function prepare_image_for_rubika_upload($path, $attachment_id = 0, $product_id = 0) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $supported_without_conversion = array('jpg', 'jpeg');
            $needs_conversion = !in_array($extension, $supported_without_conversion, true);

            if (!$needs_conversion) {
                $validation = $this->wait_for_valid_image_file($path, true);
                if (!$validation['success']) {
                    $this->add_log('error', 'Original image validation failed.', array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'file' => basename($path),
                        'reason' => $validation['message'],
                    ));
                    return array('success' => false, 'message' => $validation['message'], 'path' => $path, 'temporary' => false, 'generated_files' => array());
                }

                $this->add_log('info', 'Original JPG image validated for Rubika upload.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'file' => basename($path),
                ));
                return array('success' => true, 'path' => $path, 'temporary' => false, 'generated_files' => array(), 'message' => 'Original image ready');
            }

            $this->add_log('info', 'Image conversion started for Rubika upload.', array(
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
                'source' => basename($path),
                'extension' => $extension,
            ));

            $generated_files = array();
            $final_jpg = trailingslashit(get_temp_dir()) . 'wcrb-rubika-' . wp_generate_uuid4() . '.jpg';
            $generated_files[] = $final_jpg;
            $converted = false;

            if (function_exists('wp_get_image_editor')) {
                $editor = wp_get_image_editor($path);
                if (!is_wp_error($editor)) {
                    $saved = $editor->save($final_jpg, 'image/jpeg');
                    $converted = !is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path']);
                    if ($converted && $saved['path'] !== $final_jpg) {
                        $final_jpg = $saved['path'];
                        $generated_files[] = $final_jpg;
                    }
                }
            }

            if (!$converted && function_exists('imagejpeg')) {
                $image_resource = false;
                if ($extension === 'webp' && function_exists('imagecreatefromwebp')) {
                    $image_resource = @imagecreatefromwebp($path);
                } elseif ($extension === 'avif' && function_exists('imagecreatefromavif')) {
                    $image_resource = @imagecreatefromavif($path);
                } else {
                    $raw_contents = @file_get_contents($path);
                    if ($raw_contents !== false && function_exists('imagecreatefromstring')) {
                        $image_resource = @imagecreatefromstring($raw_contents);
                    }
                }

                if ($image_resource) {
                    $converted = @imagejpeg($image_resource, $final_jpg, 90);
                    imagedestroy($image_resource);
                }
            }

            if (!$converted) {
                $prepared = array('path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files);
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                $this->add_log('error', 'Image conversion failed for Rubika upload.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'source' => basename($path),
                ));
                return array('success' => false, 'message' => 'Image conversion failed', 'path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files);
            }

            clearstatcache(true, $final_jpg);
            $validation = $this->wait_for_valid_image_file($final_jpg, true);
            if (!$validation['success']) {
                $prepared = array('path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files);
                $this->cleanup_prepared_image($prepared, $attachment_id, $product_id);
                $this->add_log('error', 'Converted JPG validation failed.', array(
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id,
                    'file' => basename($final_jpg),
                    'reason' => $validation['message'],
                ));
                return array('success' => false, 'message' => $validation['message'], 'path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files);
            }

            $this->add_log('info', 'Image conversion completed and validated for Rubika upload.', array(
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
                'source' => basename($path),
                'file' => basename($final_jpg),
                'size' => filesize($final_jpg),
            ));

            return array('success' => true, 'path' => $final_jpg, 'temporary' => true, 'generated_files' => $generated_files, 'message' => 'Converted image ready');
        }

        private function wait_for_valid_image_file($path, $require_jpeg = true) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                clearstatcache(true, $path);
                if (file_exists($path) && is_readable($path) && filesize($path) > 0) {
                    $image_info = @getimagesize($path);
                    if (is_array($image_info) && !empty($image_info['mime'])) {
                        if (!$require_jpeg || $image_info['mime'] === 'image/jpeg') {
                            return array('success' => true, 'message' => 'Image file is valid');
                        }
                        return array('success' => false, 'message' => 'Validated image is not JPEG: ' . $image_info['mime']);
                    }
                }
                usleep(250000);
            }

            return array('success' => false, 'message' => 'Image file was not ready or valid after retry checks');
        }

        private function cleanup_prepared_image($prepared, $attachment_id = 0, $product_id = 0) {
            if (empty($prepared['temporary'])) {
                return;
            }

            $files = array();
            if (!empty($prepared['generated_files']) && is_array($prepared['generated_files'])) {
                $files = $prepared['generated_files'];
            } elseif (!empty($prepared['path'])) {
                $files[] = $prepared['path'];
            }

            foreach (array_unique(array_filter($files)) as $file) {
                if (file_exists($file)) {
                    $deleted = @unlink($file);
                    $this->add_log($deleted ? 'info' : 'warning', 'Temporary Rubika image cleanup ' . ($deleted ? 'completed.' : 'failed.'), array(
                        'product_id' => $product_id,
                        'attachment_id' => $attachment_id,
                        'file' => basename($file),
                    ));
                }
            }
        }

        private function extract_file_id_from_upload_response($json) {
            if (!is_array($json)) {
                return '';
            }

            $possible_keys = array('file_id', 'fileId', 'id');
            foreach ($possible_keys as $key) {
                if (!empty($json[$key]) && is_scalar($json[$key])) {
                    return (string) $json[$key];
                }
            }

            foreach ($json as $value) {
                if (is_array($value)) {
                    $nested = $this->extract_file_id_from_upload_response($value);
                    if (!empty($nested)) {
                        return $nested;
                    }
                }
            }

            return '';
        }

        private function build_buy_keypad($product) {
            return array(
                'rows' => array(
                    array(
                        'buttons' => array(
                            array(
                                'id' => 'buy_' . $product->get_id(),
                                'type' => 'Simple',
                                'button_text' => '🛒 خرید محصول',
                            ),
                        ),
                    ),
                ),
            );
        }

        private function rubika_api_request($token, $method, $payload) {
            if (empty($token)) {
                return array('success' => false, 'message' => 'Bot token is empty');
            }

            $url = sprintf('https://botapi.rubika.ir/v3/%s/%s', rawurlencode($token), $method);
            $response = wp_remote_post($url, array(
                'timeout' => 45,
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => wp_json_encode($payload),
            ));

            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            $body = json_decode($raw_body, true);

            if ($status_code < 200 || $status_code >= 300) {
                return array('success' => false, 'message' => 'HTTP ' . $status_code . ': ' . wp_strip_all_tags($raw_body));
            }

            if (!is_array($body)) {
                return array('success' => false, 'message' => 'Invalid JSON response');
            }

            if (isset($body['ok']) && !$body['ok']) {
                $error_text = !empty($body['description']) ? $body['description'] : 'Rubika API returned ok=false';
                return array('success' => false, 'message' => $error_text, 'data' => $body);
            }

            if (isset($body['status'])) {
                $normalized = strtoupper((string) $body['status']);
                $allowed = array('OK', 'SUCCESS');
                if (!in_array($normalized, $allowed, true)) {
                    $error_text = !empty($body['description']) ? $body['description'] : ('Rubika status: ' . $body['status']);
                    return array('success' => false, 'message' => $error_text, 'data' => $body);
                }
            }

            return array('success' => true, 'data' => $body, 'message' => 'OK');
        }

        public function admin_bar_publish_button($wp_admin_bar) {
            if (!current_user_can('edit_products')) {
                return;
            }

            $product_id = 0;
            if (is_admin()) {
                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                if ($screen && $screen->base === 'post' && $screen->post_type === 'product') {
                    $product_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
                }
            } elseif (function_exists('is_product') && is_product()) {
                $product_id = get_queried_object_id();
            }

            if (!$product_id) {
                return;
            }

            $post = get_post($product_id);
            if (!$post || $post->post_status !== 'publish') {
                return;
            }

            $wp_admin_bar->add_node(array(
                'id' => 'wcrb_social_menu',
                'title' => __('شبکه اجتماعی', 'wcrb'),
                'href' => false,
            ));

            $actions = array(
                'rubika' => __('ارسال به روبیکا', 'wcrb'),
                'telegram' => __('ارسال به تلگرام', 'wcrb'),
                'all' => __('ارسال به همه شبکه‌های فعال', 'wcrb'),
            );

            foreach ($actions as $network => $title) {
                if ($network !== 'all' && !$this->is_network_enabled($network)) {
                    continue;
                }
                if ($network === 'all' && empty($this->get_enabled_networks())) {
                    continue;
                }
                $url = wp_nonce_url(
                    add_query_arg(
                        array('action' => 'wcrb_send_now_single', 'product_id' => $product_id, 'network' => $network),
                        admin_url('admin-post.php')
                    ),
                    'wcrb_send_now_single'
                );
                $wp_admin_bar->add_node(array(
                    'id' => 'wcrb_publish_product_' . $network,
                    'parent' => 'wcrb_social_menu',
                    'title' => $title,
                    'href' => $url,
                    'meta' => array('class' => 'wcrb-publish-product'),
                ));
            }

        }

        public function admin_notice() {
            if (empty($_GET['wcrb_notice'])) {
                return;
            }



            if ($_GET['wcrb_notice'] === 'manual_no_network') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please select at least one target network for the manual message.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'manual_empty') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Manual message text and images cannot both be empty.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'manual_sent') {
                $summary = isset($_GET['manual_result']) ? json_decode(rawurldecode(sanitize_text_field(wp_unslash($_GET['manual_result']))), true) : array();
                $parts = array();
                if (is_array($summary)) {
                    foreach ($summary as $network => $status) {
                        $parts[] = ucfirst(sanitize_key($network)) . ': ' . sanitize_text_field($status);
                    }
                }
                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Manual message result: ', 'wcrb') . esc_html(implode(' | ', $parts)) . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'single') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Product was queued for enabled social networks.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'bulk') {
                $queued = isset($_GET['queued']) ? absint($_GET['queued']) : 0;
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Queued %d network items for social publishing.', 'wcrb'), $queued)) . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'clear_queue') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Queue has been cleared.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'clear_logs') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Logs cleared.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'run_queue') {
                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Queue runner executed once.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'clear_database') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Plugin database data has been cleared.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'test_ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Test message sent successfully.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'test_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Test message failed. Check logs for details.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'reset_sync') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Synced/unsynced product records were reset.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'direct_ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Product sent directly to the selected social network(s).', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'direct_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Direct send failed. Check logs.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'plugin_disabled') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Social publishing is disabled from plugin settings.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'telegram_test_ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Telegram relay test succeeded.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'telegram_test_fail') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Telegram relay test failed. Check logs.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'clear_failed') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Failed/skipped queue items were cleared for the selected network.', 'wcrb') . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'requeue_failed') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Failed queue items were requeued for the selected network.', 'wcrb') . '</p></div>';
            }



            if ($_GET['wcrb_notice'] === 'queue_paused') {
                $network = isset($_GET['network']) ? sanitize_key(wp_unslash($_GET['network'])) : '';
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(sprintf(__('%s queue was paused. Pending items remain pending.', 'wcrb'), ucfirst($network))) . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'queue_resumed') {
                $network = isset($_GET['network']) ? sanitize_key(wp_unslash($_GET['network'])) : '';
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('%s queue was resumed.', 'wcrb'), ucfirst($network))) . '</p></div>';
            }

            if ($_GET['wcrb_notice'] === 'unsynced_network') {
                $network = isset($_GET['network']) ? sanitize_key(wp_unslash($_GET['network'])) : '';
                $message = sprintf(
                    __('%1$s unsynced scan completed. Scanned: %2$d, unsynced found: %3$d, added: %4$d, already synced: %5$d, out of stock: %6$d, already pending: %7$d, invalid/ineligible: %8$d, errors: %9$d.', 'wcrb'),
                    ucfirst($network),
                    isset($_GET['scanned']) ? absint($_GET['scanned']) : 0,
                    isset($_GET['unsynced_found']) ? absint($_GET['unsynced_found']) : 0,
                    isset($_GET['added']) ? absint($_GET['added']) : 0,
                    isset($_GET['skipped_synced']) ? absint($_GET['skipped_synced']) : 0,
                    isset($_GET['skipped_out_of_stock']) ? absint($_GET['skipped_out_of_stock']) : 0,
                    isset($_GET['skipped_pending']) ? absint($_GET['skipped_pending']) : 0,
                    isset($_GET['skipped_invalid']) ? absint($_GET['skipped_invalid']) : 0,
                    isset($_GET['errors']) ? absint($_GET['errors']) : 0
                );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
            }

        }

        private function normalize_network($network) {
            $network = sanitize_key($network);
            return in_array($network, array('rubika', 'telegram'), true) ? $network : 'rubika';
        }

        private function get_enabled_networks() {
            $networks = array();
            if ($this->is_network_enabled('rubika')) {
                $networks[] = 'rubika';
            }
            if ($this->is_network_enabled('telegram')) {
                $networks[] = 'telegram';
            }
            return $networks;
        }

        private function is_network_enabled($network) {
            if (!$this->is_plugin_enabled()) {
                return false;
            }
            $settings = $this->get_settings();
            $network = $this->normalize_network($network);
            if ($network === 'telegram') {
                return !empty($settings['telegram_enabled']);
            }
            return !empty($settings['rubika_enabled']);
        }

        private function sent_meta_key($network) {
            return '_wcrb_' . $this->normalize_network($network) . '_last_sent_at';
        }

        private function sent_hash_meta_key($network) {
            return '_wcrb_' . $this->normalize_network($network) . '_last_payload_hash';
        }

        private function was_payload_sent($product_id, $network, $payload_hash) {
            if (empty($payload_hash)) {
                return false;
            }
            return hash_equals((string) get_post_meta($product_id, $this->sent_hash_meta_key($network), true), (string) $payload_hash);
        }

        private function build_request_id($product_id, $network) {
            return $this->normalize_network($network) . '-' . absint($product_id) . '-' . wp_generate_uuid4();
        }

        private function build_payload_hash($product, $network) {
            if (!$product) {
                return '';
            }
            $settings = $this->get_settings();
            $network = $this->normalize_network($network);
            $image_count = $network === 'telegram' ? (int) $settings['telegram_image_count'] : (int) $settings['image_count'];
            $excluded_images = $network === 'telegram' ? $settings['telegram_excluded_images'] : $settings['excluded_images'];
            $image_ids = $this->collect_images($product, $image_count, $excluded_images);
            $image_urls = array();
            foreach ($image_ids as $image_id) {
                $image_urls[] = wp_get_attachment_url($image_id);
            }
            $payload = array(
                'product_id' => $product->get_id(),
                'network' => $network,
                'text' => $this->render_network_template($product, $network),
                'url' => get_permalink($product->get_id()),
                'price' => $this->plain_product_price($product),
                'images' => $image_ids,
                'image_urls' => $image_urls,
                'settings' => array(
                    'image_count' => $image_count,
                    'template' => $network === 'telegram' ? $settings['telegram_template'] : $settings['template'],
                    'parse_mode' => $network === 'telegram' ? $settings['telegram_parse_mode'] : '',
                    'send_as_album' => $network === 'telegram' ? !empty($settings['telegram_send_as_album']) : '',
                    'excluded_images' => $excluded_images,
                    'destination' => $network === 'telegram' ? $settings['telegram_relay_url'] : $settings['channel'],
                ),
            );
            return hash('sha256', wp_json_encode($payload));
        }

        private function add_log($level, $message, $context = array()) {
            if (!$this->is_logging_enabled()) {
                return;
            }

            $logs = get_option(self::LOG_OPTION, array());
            if (!is_array($logs)) {
                $logs = array();
            }

            $line = sprintf(
                '[%s] [%s] %s',
                current_time('mysql'),
                strtoupper(sanitize_key($level)),
                sanitize_text_field($message)
            );

            if (!empty($context)) {
                $context = $this->sanitize_log_context($context);
                $encoded = wp_json_encode($context);
                if ($encoded) {
                    $line .= ' | ' . $encoded;
                }
            }

            $logs[] = $line;
            $settings = $this->get_settings();
            $limit = max(50, absint($settings['log_retention_limit'] ?? 300));
            if (count($logs) > $limit) {
                $logs = array_slice($logs, -$limit);
            }
            update_option(self::LOG_OPTION, $logs, false);
        }

        private function is_logging_enabled() {
            $settings = $this->get_settings();
            return !empty($settings['enable_logs']);
        }

        private function is_plugin_enabled() {
            $settings = $this->get_settings();
            return !empty($settings['enable_plugin']);
        }

        private function sanitize_log_context($context) {
            $blocked = array('bot_token', 'telegram_relay_api_key', 'telegram_hmac_secret', 'api_key', 'hmac_secret', 'authorization', 'headers');
            foreach ($context as $key => $value) {
                if (in_array(strtolower((string) $key), $blocked, true)) {
                    $context[$key] = '[redacted]';
                }
            }
            return $context;
        }

        private function get_logs($network = '') {
            $logs = get_option(self::LOG_OPTION, array());
            if (!is_array($logs)) {
                return array();
            }
            if ($network) {
                $logs = array_values(array_filter($logs, function($line) use ($network) {
                    return strpos((string) $line, '"network":"' . $network . '"') !== false;
                }));
            }
            return array_reverse($logs);
        }
    }

    new WCRB_Plugin();
}
