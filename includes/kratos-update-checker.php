<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Kratos_Update_Checker_AWS')) {
    class Kratos_Update_Checker_AWS
    {
        public string $plugin_slug;
        public string $plugin_basename_file;
        public string $version;
        public bool $cache_allowed = true;

        /**
         * 用于在当前请求生命周期内缓存远程响应。
         * @var mixed
         */
        private $remote_response;

        /**
         * 标记远程响应是否已被缓存。
         * @var bool
         */
        private bool $response_cached = false;

        /**
         * 构造函数。
         *
         * @param string $plugin_file 插件主文件的绝对路径 (例如 __FILE__ )。
         * @param string $update_url  用于获取更新信息的 JSON文件的 URL。
         */
        public function __construct(
            public string $plugin_file,
            public string $update_url,
            public string $transient_key
        ) {
            // 确保 get_plugin_data() 函数可用
            if (!function_exists('get_plugin_data')) {
                require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }

            $plugin_data = get_plugin_data($this->plugin_file);

            $this->version = $plugin_data['Version'];
            $this->plugin_basename_file = plugin_basename($this->plugin_file);
            $this->plugin_slug = dirname($this->plugin_basename_file);

            $this->add_hooks();
        }

        /**
         * 添加 WordPress 钩子。
         */
        public function add_hooks()
        {
            add_filter('plugins_api', [$this, 'info'], 20, 3);
            add_filter('pre_set_site_transient_update_plugins', [$this, 'update']);
            add_action('upgrader_process_complete', [$this, 'purge'], 10, 2);
            add_filter('plugin_row_meta', [$this, 'add_details_link'], 10, 2);
            add_action('admin_footer', [$this, 'sponsor_modal_script']);
        }

        /**
         * 远程获取更新信息。
         *
         * @return object|false
         */
        public function request()
        {
            // 如果在当前请求中已有缓存，则直接返回
            if ($this->response_cached) {
                return $this->remote_response;
            }

            $remote = get_transient($this->transient_key);
            $force_check = isset($_GET['force-check']) && 1 === (int) $_GET['force-check'];

            // 如果不是强制检查，并且有有效的缓存，则直接使用缓存
            if (!$force_check && false !== $remote && $this->cache_allowed) {
                $this->remote_response = $remote;
                $this->response_cached = true;
                return $this->remote_response;
            }

            // 如果是强制检查，删除旧的瞬态缓存
            if ($force_check) {
                delete_transient($this->transient_key);
            }

            // 执行远程请求
            $response = wp_remote_get(
                $this->update_url,
                [
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                ]
            );

            if (
                is_wp_error($response)
                || 200 !== wp_remote_retrieve_response_code($response)
                || empty(wp_remote_retrieve_body($response))
            ) {
                $this->remote_response = false;
                $this->response_cached = true;
                return false;
            }

            $remote = json_decode(wp_remote_retrieve_body($response));

            // 请求成功，设置瞬态缓存
            set_transient($this->transient_key, $remote, HOUR_IN_SECONDS * 12);

            // 缓存响应结果以备在当前请求中复用
            $this->remote_response = $remote;
            $this->response_cached = true;

            return $this->remote_response;
        }

        /**
         * 在插件信息弹窗中显示自定义内容。
         *
         * @param object|false $res
         * @param string $action
         * @param object $args
         * @return object|false
         */
        public function info($res, $action, $args)
        {
            if ('plugin_information' !== $action) {
                return $res;
            }

            if ($this->plugin_slug !== $args->slug) {
                return $res;
            }

            $remote = $this->request();

            if (!$remote) {
                return $res;
            }

            $res = new stdClass();
            $res->name = $remote->name;
            $res->slug = $remote->slug;
            $res->version = $remote->version;
            $res->tested = $remote->tested;
            $res->requires = $remote->requires;
            $res->author = $remote->author;
            $res->homepage = $remote->homepage;
            $res->download_link = $remote->download_url;
            $res->requires_php = $remote->requires_php;

            if (!empty($remote->sections)) {
                $res->sections = (array) $remote->sections;
            }

            if (!empty($remote->banners)) {
                $res->banners = (array) $remote->banners;
            }

            if (!empty($remote->icons)) {
                $res->icons = (array) $remote->icons;
            }

            return $res;
        }

        /**
         * 检查并注入更新信息到 WordPress 更新瞬态缓存。
         *
         * @param object $transient
         * @return object
         */
        public function update($transient)
        {
            if (!is_object($transient)) {
                $transient = new stdClass();
            }

            if (!property_exists($transient, 'response') || !is_array($transient->response)) {
                $transient->response = [];
            }

            $remote = $this->request();

            if (
                $remote
                && isset($remote->version) && version_compare($this->version, $remote->version, '<')
                && isset($remote->requires) && version_compare($remote->requires, get_bloginfo('version'), '<=')
                && isset($remote->requires_php) && version_compare($remote->requires_php, PHP_VERSION, '<=')
            ) {
                $res = new stdClass();
                $res->slug = $this->plugin_slug;
                $res->plugin = $this->plugin_basename_file;
                $res->new_version = $remote->version;
                $res->tested = $remote->tested ?? '';
                $res->package = $remote->download_url ?? '';
                $res->url = $remote->homepage ?? '';
                $res->icons = (array) ($remote->icons ?? []);
                $res->banners = (array) ($remote->banners ?? []);
                $res->banners_rtl = [];
                $res->requires_php = $remote->requires_php;

                $transient->response[$res->plugin] = $res;
            }

            return $transient;
        }

        /**
         * 在插件更新完成后清除缓存。
         *
         * @param \WP_Upgrader $upgrader
         * @param array $options
         */
        public function purge($upgrader, $options)
        {
            if (
                $this->cache_allowed
                && 'update' === ($options['action'] ?? '')
                && 'plugin' === ($options['type'] ?? '')
                && !empty($options['plugins'])
                && in_array($this->plugin_basename_file, $options['plugins'], true)
            ) {
                delete_transient($this->transient_key);
            }
        }

        /**
         * 在插件列表页添“检查更新”链接。
         *
         * @param array $links
         * @param string $file
         * @return array
         */
        public function add_details_link($links, $file)
        {
            if ($this->plugin_basename_file === $file) {
                $update_link = sprintf(
                    '<a href="%s">%s</a>',
                    add_query_arg('force-check', 1, self_admin_url('update-core.php')),
                    __('检查更新', 'kratos')
                );
                $links[] = $update_link;

                $sponsor_link = sprintf(
                    '<a href="#" class="kratos-sponsor-link"><span class="dashicons dashicons-star-filled" aria-hidden="true" style="font-size:14px;line-height:1.3"></span>%s</a>',
                    __('赞助', 'kratos')
                );
                $links[] = $sponsor_link;
            }

            return $links;
        }

        /**
         * 输出赞助模态框的脚本和样式。
         */
        public function sponsor_modal_script()
        {
            global $pagenow;
            if ('plugins.php' !== $pagenow) {
                return;
            }
?>
            <div id="kratos-sponsor-modal" style="display:none;">
                <div class="modal-content">
                    <span class="close">&times;</span>
                    <p style="margin-bottom: 10px; font-weight: bold; font-size: 16px;"><?php esc_html_e('微信赞赏码', 'kratos'); ?></p>
                    <img src="<?php echo esc_url(plugins_url('/.github/assets/wechat-reward.png', $this->plugin_file)); ?>" alt="微信赞赏码">
                </div>
            </div>
<?php
        }
    }
}
