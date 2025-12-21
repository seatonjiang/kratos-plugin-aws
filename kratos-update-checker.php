<?php

namespace Kratos_Update_Checker;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    public string $plugin_slug;
    public string $plugin_basename_file;
    public string $version;
    public bool $cache_allowed = true;

    public function __construct(public string $update_url, public string $transient_key)
    {
        $this->plugin_slug = plugin_basename(dirname(__DIR__));
        $this->plugin_basename_file = plugin_basename(dirname(__DIR__)) . '/kratos-plugin-aws.php';

        $plugin_data = get_plugin_data(plugin_dir_path(dirname(__FILE__)) . 'kratos-plugin-aws.php');
        $this->version = $plugin_data['Version'];

        add_filter('plugins_api', array($this, 'info'), 20, 3);
        add_filter('pre_set_site_transient_update_plugins', array($this, 'update'));
        add_action('upgrader_process_complete', array($this, 'purge'), 10, 2);
        add_filter('plugin_row_meta', array($this, 'add_details_link'), 10, 2);
    }

    /**
     * 远程获取更新信息
     *
     * @return object|false
     */
    public function request()
    {
        $remote = get_transient($this->transient_key);

        if (isset($_GET['force-check']) && 1 === (int) $_GET['force-check']) {
            $remote = false;
        }

        if (false === $remote || ! $this->cache_allowed) {

            $response = wp_remote_get(
                $this->update_url,
                array(
                    'timeout' => 10,
                    'headers' => array(
                        'Accept' => 'application/json',
                    ),
                )
            );

            if (
                is_wp_error($response)
                || 200 !== wp_remote_retrieve_response_code($response)
                || empty(wp_remote_retrieve_body($response))
            ) {
                return false;
            }

            $remote = json_decode(wp_remote_retrieve_body($response));

            set_transient($this->transient_key, $remote, HOUR_IN_SECONDS * 12);
        }

        return $remote;
    }

    /**
     * 自定义插件信息内容
     *
     * @param object|false $res
     * @param string       $action
     * @param object       $args
     * @return object|false
     */
    function info($res, $action, $args)
    {
        if ('plugin_information' !== $action) {
            return $res;
        }

        if ($this->plugin_slug !== $args->slug) {
            return $res;
        }

        $remote = $this->request();

        if (! $remote) {
            return $res;
        }

        $res                 = new \stdClass();
        $res->name           = $remote->name;
        $res->slug           = $remote->slug;
        $res->version        = $remote->version;
        $res->tested         = $remote->tested;
        $res->requires       = $remote->requires;
        $res->author         = $remote->author;
        $res->homepage       = $remote->homepage;
        $res->download_link  = $remote->download_url;
        $res->requires_php   = $remote->requires_php;

        $res->sections = array(
            'description'  => $remote->sections->description
        );

        if (! empty($remote->banners)) {
            $res->banners = array(
                'low'  => $remote->banners->low,
                'high' => $remote->banners->high,
            );
        }

        if (! empty($remote->icons)) {
            $res->icons = array(
                '1x'  => $remote->icons->{'128'},
                '2x'  => $remote->icons->{'256'},
                'svg' => $remote->icons->svg,
            );
        }

        return $res;
    }

    /**
     * 检查并注入更新信息到 WordPress 更新瞬态缓存
     *
     * @param object $transient
     * @return object
     */
    public function update($transient)
    {
        if (! is_object($transient)) {
            $transient = new \stdClass();
        }

        if (! property_exists($transient, 'response') || ! is_array($transient->response)) {
            $transient->response = [];
        }

        $remote = $this->request();

        if (
            $remote
            && isset($remote->version) && version_compare($this->version, $remote->version, '<')
            && isset($remote->requires) && version_compare($remote->requires, get_bloginfo('version'), '<=')
            && isset($remote->requires_php) && version_compare($remote->requires_php, PHP_VERSION, '<=')
        ) {
            $res               = new \stdClass();
            $res->slug         = $this->plugin_slug;
            $res->plugin       = $this->plugin_basename_file;
            $res->new_version  = $remote->version;
            $res->tested       = $remote->tested ?? '';
            $res->package      = $remote->download_url ?? '';
            $res->url          = $remote->homepage ?? '';
            $res->icons        = (array) ($remote->icons ?? []);
            $res->banners      = (array) ($remote->banners ?? []);
            $res->banners_rtl  = array();
            $res->requires_php = $remote->requires_php;

            $transient->response[$res->plugin] = $res;
        }

        return $transient;
    }

    /**
     * 在插件更新完成后清除缓存
     *
     * @param WP_Upgrader $upgrader
     * @param array       $options
     */
    public function purge($upgrader, $options)
    {
        if (
            $this->cache_allowed
            && 'update' === $options['action']
            && 'plugin' === $options['type']
            && isset($options['plugins'])
            && in_array($this->plugin_basename_file, $options['plugins'], true)
        ) {
            delete_transient($this->transient_key);
        }
    }

    /**
     * 插件列表页添加检查更新按钮
     *
     * @param array  $links
     * @param string $file
     * @return array
     */
    public function add_details_link($links, $file)
    {
        if ($this->plugin_basename_file === $file) {
            $update_link = sprintf(
                '<a href="%s">%s</a>',
                add_query_arg('force-check', 1, self_admin_url('update-core.php')),
                __('检查更新', 'kratos-plugin-aws')
            );
            $links[] = $update_link;
        }

        return $links;
    }
}
