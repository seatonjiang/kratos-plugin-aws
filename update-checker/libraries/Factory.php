<?php

namespace KratosUpdateChecker;

use KratosUpdateChecker\Plugin;
use KratosUpdateChecker\Theme;

if (!class_exists(Factory::class, false)):

    /**
     * A factory that builds update checker instances.
     *
     * 当同一类的多个版本被加载时（例如 PluginUpdateChecker 4.0 和 4.1），
     * 该工厂始终使用最新的次要版本。通过调用 {@link Factory::addVersion()} 注册类版本。
     *
     * 目前，该工厂只能构建 UpdateChecker 类的实例。其他类主要用于内部使用，直接引用特定实现。
     */
    class Factory
    {
        const MAJOR_VERSION = '5';
        protected static $classVersions = array();
        protected static $sorted = false;

        protected static $myMajorVersion = '';
        protected static $latestCompatibleVersion = '';

        /**
         * A wrapper method for buildUpdateChecker() that reads the metadata URL from the plugin or theme header.
         *
         * @param string $fullPath Full path to the main plugin file or the theme's style.css.
         * @param array $args Optional arguments. Keys should match the argument names of the buildUpdateChecker() method.
         * @return Plugin\UpdateChecker|Theme\UpdateChecker
         */
        public static function buildFromHeader($fullPath, $args = array())
        {
            $fullPath = self::normalizePath($fullPath);

            //Set up defaults.
            $defaults = array(
                'metadataUrl'  => '',
                'slug'         => '',
                'checkPeriod'  => 24,
                'optionName'   => '',
                'muPluginFile' => '',
            );
            $args = array_merge($defaults, array_intersect_key($args, $defaults));
            extract($args, EXTR_SKIP);

            //Read metadata URL from header "Update URI" if not provided.
            if (empty($metadataUrl) && is_readable($fullPath)) {
                $data = get_file_data($fullPath, array('update_uri' => 'Update URI'));
                if (!empty($data['update_uri'])) {
                    $metadataUrl = $data['update_uri'];
                }
            }
            if (empty($metadataUrl)) {
                throw new \RuntimeException(
                    sprintf('Missing metadata URL for "%s"', esc_html($fullPath))
                );
            }

            return self::buildUpdateChecker($metadataUrl, $fullPath, $slug, $checkPeriod, $optionName, $muPluginFile);
        }

        /**
         * Create a new instance of the update checker.
         *
         * @see UpdateChecker::__construct
         *
         * @param string $metadataUrl The URL of the JSON metadata file.
         * @param string $fullPath Full path to the main plugin file or to the theme directory.
         * @param string $slug Custom slug. Defaults to the name of the main plugin file or the theme directory.
         * @param int $checkPeriod How often to check for updates (in hours).
         * @param string $optionName Where to store bookkeeping info about update checks.
         * @param string $muPluginFile The plugin filename relative to the mu-plugins directory.
         * @return Plugin\UpdateChecker|Theme\UpdateChecker
         */
        public static function buildUpdateChecker($metadataUrl, $fullPath, $slug = '', $checkPeriod = 24, $optionName = '', $muPluginFile = '')
        {
            $fullPath = self::normalizePath($fullPath);
            $id = null;

            $themeDirectory = self::getThemeDirectoryName($fullPath);
            if (self::isPluginFile($fullPath)) {
                $type = 'Plugin';
                $id = $fullPath;
            } else if ($themeDirectory !== null) {
                $type = 'Theme';
                $id = $themeDirectory;
            } else {
                throw new \RuntimeException(sprintf(
                    '无法判断 "%s" 是插件还是主题！',
                    esc_html($fullPath)
                ));
            }

            $checkerClass = $type . '\\UpdateChecker';

            $checkerClass = self::getCompatibleClassVersion($checkerClass);
            if ($checkerClass === null) {
                //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
                trigger_error(
                    esc_html(sprintf(
                        'PUC %s does not support updates for %ss',
                        self::$latestCompatibleVersion,
                        strtolower($type)
                    )),
                    E_USER_ERROR
                );
            }

            return new $checkerClass($metadataUrl, $id, $slug, $checkPeriod, $optionName, $muPluginFile);
        }

        /**
         *
         * Normalize a filesystem path. Introduced in WP 3.9.
         * Copying here allows use of the class on earlier versions.
         * This version adapted from WP 4.8.2 (unchanged since 4.5.6)
         *
         * @param string $path Path to normalize.
         * @return string Normalized path.
         */
        public static function normalizePath($path)
        {
            if (function_exists('wp_normalize_path')) {
                return wp_normalize_path($path);
            }
            $path = str_replace('\\', '/', $path);
            $path = preg_replace('|(?<=.)/+|', '/', $path);
            if (substr($path, 1, 1) === ':') {
                $path = ucfirst($path);
            }
            return $path;
        }

        /**
         * Check if the path points to a plugin file.
         *
         * @param string $absolutePath Normalized path.
         * @return bool
         */
        protected static function isPluginFile($absolutePath)
        {
            //Is the file inside the "plugins" or "mu-plugins" directory?
            $pluginDir = self::normalizePath(WP_PLUGIN_DIR);
            $muPluginDir = self::normalizePath(WPMU_PLUGIN_DIR);
            if ((strpos($absolutePath, $pluginDir) === 0) || (strpos($absolutePath, $muPluginDir) === 0)) {
                return true;
            }

            //Is it a file at all? Caution: is_file() can fail if the parent dir. doesn't have the +x permission set.
            if (!is_file($absolutePath)) {
                return false;
            }

            //Does it have a valid plugin header?
            //This is a last-ditch check for plugins symlinked from outside the WP root.
            if (function_exists('get_file_data')) {
                $headers = get_file_data($absolutePath, array('Name' => 'Plugin Name'), 'plugin');
                return !empty($headers['Name']);
            }

            return false;
        }

        /**
         * Get the name of the theme's directory from a full path to a file inside that directory.
         * E.g. "/abc/public_html/wp-content/themes/foo/whatever.php" => "foo".
         *
         * Note that subdirectories are currently not supported. For example,
         * "/xyz/wp-content/themes/my-theme/includes/whatever.php" => NULL.
         *
         * @param string $absolutePath Normalized path.
         * @return string|null Directory name, or NULL if the path doesn't point to a theme.
         */
        protected static function getThemeDirectoryName($absolutePath)
        {
            if (is_file($absolutePath)) {
                $absolutePath = dirname($absolutePath);
            }

            if (file_exists($absolutePath . '/style.css')) {
                return basename($absolutePath);
            }
            return null;
        }


        /**
         * Get the latest version of the specified class that has the same major version number
         * as this factory class.
         *
         * @param string $class Partial class name.
         * @return string|null Full class name.
         */
        protected static function getCompatibleClassVersion($class)
        {
            if (isset(self::$classVersions[$class][self::$latestCompatibleVersion])) {
                return self::$classVersions[$class][self::$latestCompatibleVersion];
            }
            return null;
        }

        /**
         * Get the specific class name for the latest available version of a class.
         *
         * @param string $class
         * @return null|string
         */
        public static function getLatestClassVersion($class)
        {
            if (!self::$sorted) {
                self::sortVersions();
            }

            if (isset(self::$classVersions[$class])) {
                return reset(self::$classVersions[$class]);
            } else {
                return null;
            }
        }

        /**
         * Sort available class versions in descending order (i.e. newest first).
         */
        protected static function sortVersions()
        {
            foreach (self::$classVersions as $class => $versions) {
                uksort($versions, array(__CLASS__, 'compareVersions'));
                self::$classVersions[$class] = $versions;
            }
            self::$sorted = true;
        }

        protected static function compareVersions($a, $b)
        {
            return -version_compare($a, $b);
        }

        /**
         * Register a version of a class.
         *
         * @access private This method is only for internal use by the library.
         *
         * @param string $generalClass Class name without version numbers, e.g. 'PluginUpdateChecker'.
         * @param string $versionedClass Actual class name, e.g. 'PluginUpdateChecker_1_2'.
         * @param string $version Version number, e.g. '1.2'.
         */
        public static function addVersion($generalClass, $versionedClass, $version)
        {
            if (empty(self::$myMajorVersion)) {
                self::$myMajorVersion = self::MAJOR_VERSION;
            }

            //Store the greatest version number that matches our major version.
            $components = explode('.', $version);
            if ($components[0] === self::$myMajorVersion) {

                if (
                    empty(self::$latestCompatibleVersion)
                    || version_compare($version, self::$latestCompatibleVersion, '>')
                ) {
                    self::$latestCompatibleVersion = $version;
                }
            }

            if (!isset(self::$classVersions[$generalClass])) {
                self::$classVersions[$generalClass] = array();
            }
            self::$classVersions[$generalClass][$version] = $versionedClass;
            self::$sorted = false;
        }
    }

endif;
