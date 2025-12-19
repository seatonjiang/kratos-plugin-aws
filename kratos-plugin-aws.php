<?php

/**
 * Plugin Name: 对象存储工具包
 * Description: 使用分布式存储服务所需的工具包，提供 CDN 加速以及涉黄、涉恐、涉暴内容自动审核功能，具有高扩展性、低成本、可靠安全等优点。
 * Version: 2025.12.19
 * Plugin URI: https://github.com/seatonjiang/kratos-plugin-aws
 * Author: Seaton Jiang
 * Author URI: https://seatonjiang.com
 * License: MIT License
 * License URI: https://github.com/seatonjiang/kratos-plugin-aws/blob/main/LICENSE
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!has_action('kratos-plugin-aws')) {
    add_action('kratos-plugin-aws', function () {
        require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';
    });
}

if (! class_exists('mishaUpdateChecker')) {

    class mishaUpdateChecker
    {

        public $plugin_slug;
        public $version;
        public $cache_key;
        public $cache_allowed;

        public function __construct()
        {

            $this->plugin_slug = plugin_basename(__DIR__);
            $this->version = '1.0';
            $this->cache_key = 'misha_custom_upd';
            $this->cache_allowed = false;

            add_filter('plugins_api', array($this, 'info'), 20, 3);
            add_filter('site_transient_update_plugins', array($this, 'update'));
            add_action('upgrader_process_complete', array($this, 'purge'), 10, 2);
        }

        public function request()
        {

            $remote = get_transient($this->cache_key);

            if (false === $remote || ! $this->cache_allowed) {

                $remote = wp_remote_get(
                    'https://rudrastyh.com/wp-content/uploads/updater/info.json',
                    array(
                        'timeout' => 10,
                        'headers' => array(
                            'Accept' => 'application/json'
                        )
                    )
                );

                if (
                    is_wp_error($remote)
                    || 200 !== wp_remote_retrieve_response_code($remote)
                    || empty(wp_remote_retrieve_body($remote))
                ) {
                    return false;
                }

                set_transient($this->cache_key, $remote, DAY_IN_SECONDS);
            }

            $remote = json_decode(wp_remote_retrieve_body($remote));

            return $remote;
        }


        function info($res, $action, $args)
        {

            // print_r( $action );
            // print_r( $args );

            // do nothing if you're not getting plugin information right now
            if ('plugin_information' !== $action) {
                return $res;
            }

            // do nothing if it is not our plugin
            if ($this->plugin_slug !== $args->slug) {
                return $res;
            }

            // get updates
            $remote = $this->request();

            if (! $remote) {
                return $res;
            }

            $res = new stdClass();

            $res->name = $remote->name;
            $res->slug = $remote->slug;
            $res->version = $remote->version;
            $res->tested = $remote->tested;
            $res->requires = $remote->requires;
            $res->author = $remote->author;
            $res->author_profile = $remote->author_profile;
            $res->download_link = $remote->download_url;
            $res->trunk = $remote->download_url;
            $res->requires_php = $remote->requires_php;
            $res->last_updated = $remote->last_updated;

            $res->sections = array(
                'description' => $remote->sections->description,
                'installation' => $remote->sections->installation,
                'changelog' => $remote->sections->changelog
            );

            if (! empty($remote->banners)) {
                $res->banners = array(
                    'low' => $remote->banners->low,
                    'high' => $remote->banners->high
                );
            }

            return $res;
        }

        public function update($transient)
        {

            if (empty($transient->checked)) {
                return $transient;
            }

            $remote = $this->request();

            if (
                $remote
                && version_compare($this->version, $remote->version, '<')
                && version_compare($remote->requires, get_bloginfo('version'), '<=')
                && version_compare($remote->requires_php, PHP_VERSION, '<')
            ) {
                $res = new stdClass();
                $res->slug = $this->plugin_slug;
                $res->plugin = plugin_basename(__FILE__); // misha-update-plugin/misha-update-plugin.php
                $res->new_version = $remote->version;
                $res->tested = $remote->tested;
                $res->package = $remote->download_url;

                $transient->response[$res->plugin] = $res;
            }

            return $transient;
        }

        public function purge($upgrader, $options)
        {

            if (
                $this->cache_allowed
                && 'update' === $options['action']
                && 'plugin' === $options['type']
            ) {
                // just clean the cache when new plugin version is installed
                delete_transient($this->cache_key);
            }
        }
    }

    new mishaUpdateChecker();
}
