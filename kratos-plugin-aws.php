<?php

/**
 * Plugin Name: 对象存储工具包
 * Description: 使用对象存储服务所需的工具包，对象存储是一种专为海量非结构化数据设计的云存储架构，为客户提供海量、安全、高可靠、低成本的数据存储能力。
 * Version: 2025.12.23
 * Plugin URI: https://github.com/seatonjiang/kratos-plugin-aws
 * Author: Seaton Jiang
 * Author URI: https://seatonjiang.com
 * License: MIT License
 * License URI: https://github.com/seatonjiang/kratos-plugin-aws/blob/main/LICENSE
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';


add_action('init', function () {
    $UpdateChecker = new Kratos_Update_Checker_Plugin(__FILE__, 'http://82.156.122.116/theme.json');
});
