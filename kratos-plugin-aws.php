<?php

/**
 * Plugin Name: 对象存储工具包
 * Description: 使用对象存储服务所需的工具包，对象存储是一种专为海量非结构化数据设计的云存储架构，为客户提供海量、安全、高可靠、低成本的数据存储能力。
 * Version: 2025.12.21
 * Plugin URI: https://github.com/seatonjiang/kratos-plugin-aws
 * Author: Seaton Jiang
 * Author URI: https://seatonjiang.com
 * License: MIT License
 * License URI: https://github.com/seatonjiang/kratos-plugin-aws/blob/main/LICENSE
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
}

class Kratos_Update_Plugin
{
    public string $plugin_slug;
    public string $plugin_basename_file;
    public string $version;
    public string $transient_key = 'kratos_update_plugin_aws';
    public string $update_url = 'http://82.156.122.116/theme.json';
    public bool $cache_allowed = true;

    public function __construct()
    {
        $this->plugin_slug = plugin_basename(__DIR__);
        $this->plugin_basename_file = plugin_basename(__FILE__);

        $plugin_data = get_plugin_data(__FILE__);
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

        // 如果用户在后台点击了检查更新，则强制刷新
        if (isset($_GET['force-check']) && 1 === (int) $_GET['force-check']) {
            $remote = false;
        }

        // 如果缓存为空或不允许使用缓存，则发起新的网络请求
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

            // 检查请求是否成功
            if (
                is_wp_error($response)
                || 200 !== wp_remote_retrieve_response_code($response)
                || empty(wp_remote_retrieve_body($response))
            ) {
                // 请求失败，返回 false
                return false;
            }

            // 解码 JSON 数据
            $remote = json_decode(wp_remote_retrieve_body($response));

            // 将解码后的对象序列化后存入缓存，有效期为 12 小时
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
        // 仅在请求插件信息时执行
        if ('plugin_information' !== $action) {
            return $res;
        }

        // 检查是否是当前插件
        if ($this->plugin_slug !== $args->slug) {
            return $res;
        }

        // 获取远程更新信息
        $remote = $this->request();

        if (! $remote) {
            return $res; // 获取失败则返回原始数据
        }

        // 填充插件信息
        $res                 = new stdClass();
        $res->name           = $remote->name;
        $res->slug           = $remote->slug;
        $res->version        = $remote->version;
        $res->tested         = $remote->tested;
        $res->requires       = $remote->requires;
        $res->author         = $remote->author;
        $res->homepage       = $remote->homepage;
        $res->download_link  = $remote->download_url;
        $res->requires_php   = $remote->requires_php;

        // 填充描述、安装说明、更新日志等区域
        $res->sections = array(
            'description'  => $remote->sections->description
        );

        // 填充插件 Banner 图片
        if (! empty($remote->banners)) {
            $res->banners = array(
                'low'  => $remote->banners->low,
                'high' => $remote->banners->high,
            );
        }

        // 填充插件图标
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
        // 确保 $transient 是一个有效的对象
        if (! is_object($transient)) {
            $transient = new stdClass();
        }

        // 确保 response 属性存在且为数组
        if (! property_exists($transient, 'response') || ! is_array($transient->response)) {
            $transient->response = [];
        }

        $remote = $this->request();

        // 检查是否有新版本，并确保所有必需的属性都存在
        if (
            $remote
            && isset($remote->version) && version_compare($this->version, $remote->version, '<')
            && isset($remote->requires) && version_compare($remote->requires, get_bloginfo('version'), '<=')
            && isset($remote->requires_php) && version_compare($remote->requires_php, PHP_VERSION, '<=')
        ) {
            // 发现新版本，构建更新对象
            $res               = new stdClass();
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

            // 将更新信息注入到 transient
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

add_action('init', function () {
    new Kratos_Update_Plugin();
});
