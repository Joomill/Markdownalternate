<?php

/**
 * Markdown Alternate
 *
 * @copyright   Copyright (c) 2026 Jeroen Moolenschot | Joomill
 * @license     GNU General Public License version 3 or later; see LICENSE
 * @link        https://www.joomill-extensions.com
 */

// No direct access.
// phpcs:disable PSR1.Files.SideEffects
defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Filesystem\File;

/**
 * Installation script class for Markdown Alternate plugin
 *
 * @since  1.3.0
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace -- Joomla installer script: global class with the manifest-mandated name
class PlgSystemMarkdownalternateInstallerScript implements InstallerScriptInterface
{
    /**
     * Minimum Joomla version to check
     *
     * @var    string
     * @since  1.3.0
     */
    private $minimumJoomlaVersion = '5.0';

    /**
     * Minimum PHP version to check
     *
     * @var    string
     * @since  1.3.0
     */
    private $minimumPHPVersion = JOOMLA_MINIMUM_PHP;

    /**
     * Function called after the extension is installed
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.3.0
     */
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Function called after the extension is updated
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.3.0
     */
    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Function called after the extension is uninstalled
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.3.0
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Function called before extension installation/update/removal procedure commences
     *
     * @param   string            $type    The type of change (install, update, discover_install or uninstall)
     * @param   InstallerAdapter  $parent  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.3.0
     */
    public function preflight(string $type, InstallerAdapter $parent): bool
    {
        try {
            if ($type !== 'uninstall') {
                // Check for the minimum PHP version before continuing
                if (!empty($this->minimumPHPVersion) && version_compare(PHP_VERSION, $this->minimumPHPVersion, '<')) {
                    Log::add(
                        Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', $this->minimumPHPVersion),
                        Log::WARNING,
                        'jerror'
                    );
                    return false;
                }
                // Check for the minimum Joomla version before continuing
                if (!empty($this->minimumJoomlaVersion) && version_compare(JVERSION, $this->minimumJoomlaVersion, '<')) {
                    Log::add(
                        Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', $this->minimumJoomlaVersion),
                        Log::WARNING,
                        'jerror'
                    );
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::add('Error during preflight check: ' . $e->getMessage(), Log::ERROR, 'markdownalternate');
            return false;
        }
    }

    /**
     * Function called after extension installation/update/removal procedure commences
     *
     * @param   string            $type    The type of change (install, update, discover_install or uninstall)
     * @param   InstallerAdapter  $parent  The adapter calling this method
     *
     * @return  boolean  True on success
     *
     * @since   1.3.0
     */
    public function postflight(string $type, InstallerAdapter $parent): bool
    {
        try {
            $this->loadInstallLanguage();

            if ($type === 'install' || $type === 'update') {
                $this->installJoomillUpdateLogging();
            }

            if ($type === 'install') {
                $this->printInstallMessage();
            }

            if ($type === 'uninstall') {
                $this->printUninstallMessage();
            }

            return true;
        } catch (\Exception $e) {
            Log::add('Error during postflight: ' . $e->getMessage(), Log::ERROR, 'markdownalternate');
            // Still return true to not block the installation/uninstallation process
            // The error is logged but we don't want to prevent the process from completing
            return true;
        }
    }

    /**
     * Make the plugin install strings available to the script
     *
     * The installer normally auto-loads the .sys.ini; this is a safety net so the
     * install and uninstall screens never show raw language keys.
     *
     * @return  void
     *
     * @since   1.3.0
     */
    private function loadInstallLanguage(): void
    {
        $language = Factory::getApplication()->getLanguage();
        $language->load('plg_system_markdownalternate.sys', JPATH_ADMINISTRATOR)
            || $language->load('plg_system_markdownalternate.sys', JPATH_PLUGINS . '/system/markdownalternate');
    }

    /**
     * Render the Joomill thank-you and quickstart screen after installation
     *
     * @return  void
     *
     * @since   1.3.0
     */
    private function printInstallMessage(): void
    {
        echo '<style>a[target="_blank"]::before {display: none;}</style>';
        echo '<div class="mb-3 text-center"><img src="https://www.joomill-extensions.com/images/joomill-logo.png" alt="Joomill Extensions" /></div>';
        echo '<div class="mb-3 text-center">' . Text::_('PLG_SYSTEM_MARKDOWNALTERNATE_THANKYOU') . '</div>';
        echo '<br>';
        echo '<h3>' . Text::_('PLG_SYSTEM_MARKDOWNALTERNATE_INSTALL_QUICKSTART') . ':</h3>';
        echo '<ul>';
        echo '<li><a style="text-decoration: underline;" href="index.php?option=com_plugins&view=plugins&filter[folder]=system&filter[element]=markdownalternate" target="_blank">' . Text::_('PLG_SYSTEM_MARKDOWNALTERNATE_INSTALL_CONFIGURATION') . '</a></li>';
        echo '<li><a style="text-decoration: underline;" href="https://www.joomill-extensions.com/documentation/markdown-alternate-plugin" target="_blank">' . Text::_('PLG_SYSTEM_MARKDOWNALTERNATE_INSTALL_NEEDHELP') . '</a></li>';
        echo '</ul>';
        echo '<hr>';
        echo '<div class="text-center">' . Text::_('PLG_SYSTEM_MARKDOWNALTERNATE_FOLLOWME') . ':</div>';
        echo $this->socialIcons();
    }

    /**
     * Render the Joomill thank-you screen after uninstallation
     *
     * @return  void
     *
     * @since   1.3.0
     */
    private function printUninstallMessage(): void
    {
        echo '<style>a[target="_blank"]::before {display: none;}</style>';
        echo '<div class="mb-3 text-center"><img src="https://www.joomill-extensions.com/images/joomill-logo.png" alt="Joomill Extensions" /></div>';
        echo '<br>';
        echo '<h3 class="text-center">' . Text::_('PLG_SYSTEM_MARKDOWNALTERNATE_THANKYOU') . '</h3>';
        echo '<br>';
        echo '<div class="text-center">' . Text::_('PLG_SYSTEM_MARKDOWNALTERNATE_FOLLOWME') . ':</div>';
        echo $this->socialIcons();
    }

    /**
     * Render the Joomill social media follow links
     *
     * @return  string  The social links HTML
     *
     * @since   1.3.0
     */
    private function socialIcons(): string
    {
        return '<div class="text-center">'
            . '<a class="m-2" href="https://www.linkedin.com/in/jeroenmoolenschot/" target="_blank"><i class="fa-brands fa-linkedin"> </i></a>'
            . '<a class="m-2" href="https://www.facebook.com/Joomill" target="_blank"><i class="fa-brands fa-facebook-f"> </i></a>'
            . '<a class="m-2" href="https://www.instagram.com/Joomill" target="_blank"><i class="fa-brands fa-instagram"> </i></a>'
            . '<a class="m-2" href="https://bsky.app/profile/joomill.bsky.social" target="_blank"><i class="fa-brands fa-bluesky"> </i></a>'
            . '<a class="m-2" href="https://joomla.social/@joomill" target="_blank"><i class="fa-brands fa-mastodon"> </i></a>'
            . '<a class="m-2" href="https://www.threads.net/@joomill" target="_blank"><i class="fa-brands fa-threads"> </i></a>'
            . '<a class="m-2" href="https://www.twitter.com/Joomill" target="_blank"><i class="fa-brands fa-x-twitter"> </i></a>'
            . '<a class="m-2" href="https://community.joomla.org/service-providers-directory/listings/67:joomill.html" target="_blank"><i class="fa-brands fa-joomla"> </i></a>'
            . '</div>';
    }

    /**
     * Installs the shared "Joomill - Update Logging" installer plugin (plg_installer_joomill)
     * when it is not yet present on the site. That plugin adds diagnostic request headers with
     * site and environment information to update downloads from the Joomill update server, for
     * update logging; one installed instance covers every Joomill extension on the site and it
     * keeps itself up to date through its own update server afterwards.
     *
     * The download URL is resolved from the plugin's own update server XML (category 38), so no
     * separate download location has to stay in sync with releases. Best effort by design: the
     * whole body is wrapped in try/catch (\Throwable), every failure is swallowed and simply
     * retried on the next install or update. This can never affect the installation or update
     * of the extension itself.
     *
     * @return  void
     *
     * @since   1.3.1
     */
    private function installJoomillUpdateLogging(): void
    {
        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('installer'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('joomill'));
            $db->setQuery($query);

            // Already present (enabled or deliberately disabled): leave it alone.
            // Its updates run through the plugin's own update server.
            if ($db->loadResult()) {
                return;
            }

            // Send the same diagnostic headers the plugin itself would add, so this
            // bootstrap download shows up in the download log as well.
            $headers = ['X-Requesting-Domain' => Uri::getInstance()->getHost()];

            try {
                $headers['X-Requesting-Joomlacms-Version'] = (string) (new Version())->getShortVersion();
            } catch (\Throwable $ignore) {
            }

            $headers['X-Requesting-Php-Version'] = PHP_VERSION;

            $http = HttpFactory::getHttp();

            // Resolve the download URL of the latest version from the update server XML.
            $response = $http->get(
                'https://www.joomill-extensions.com/index.php?option=com_ochsubscriptions&view=updater&format=xml&cat=38',
                $headers,
                5
            );

            if ($response->getStatusCode() !== 200) {
                return;
            }

            $updates = simplexml_load_string((string) $response->getBody(), \SimpleXMLElement::class, LIBXML_NOERROR | LIBXML_NOWARNING);

            $downloadUrl = '';
            $checksum    = '';
            $latest      = '';

            foreach (($updates ? $updates->update : []) as $update) {
                $version = (string) $update->version;

                if ($latest !== '' && version_compare($version, $latest, '<=')) {
                    continue;
                }

                // ochSubscriptions nests the download URL in a <downloads> block;
                // plain update XML may carry <downloadurl> directly on <update>.
                $url = '';

                if (isset($update->downloads->downloadurl)) {
                    $url = trim((string) $update->downloads->downloadurl);
                } elseif (isset($update->downloadurl)) {
                    $url = trim((string) $update->downloadurl);
                }

                if ($url === '') {
                    continue;
                }

                $latest      = $version;
                $downloadUrl = $url;
                $checksum    = strtolower(trim((string) $update->sha256));
            }

            if ($downloadUrl === '') {
                return;
            }

            $package = $http->get($downloadUrl, $headers, 5);

            if ($package->getStatusCode() !== 200 || (string) $package->getBody() === '') {
                return;
            }

            $tmpFile = Factory::getApplication()->get('tmp_path') . '/plg_installer_joomill.zip';

            if (File::write($tmpFile, (string) $package->getBody()) === false) {
                return;
            }

            // Verify the package integrity when the update XML provides a checksum.
            if ($checksum !== '' && !hash_equals($checksum, (string) hash_file('sha256', $tmpFile))) {
                InstallerHelper::cleanupInstall($tmpFile, '');

                return;
            }

            $unpacked = InstallerHelper::unpack($tmpFile, true);

            if (\is_array($unpacked) && !empty($unpacked['dir']) && is_dir($unpacked['dir'])) {
                // Deliberately a NEW Installer instance: Installer::getInstance() would
                // return the installer that is running this extension install right now.
                $installer = new Installer();
                $installer->setDatabase($db);
                $installer->install($unpacked['dir']);
            }

            InstallerHelper::cleanupInstall($tmpFile, \is_array($unpacked) ? (string) ($unpacked['extractdir'] ?? '') : '');
        } catch (\Throwable $e) {
            // Best effort only: never let this affect the extension install or update.
            Log::add('Markdown Alternate: could not install the Joomill Update Logging plugin: ' . $e->getMessage(), Log::WARNING, 'markdownalternate');
        }
    }
}

// phpcs:disable PSR1.Files.SideEffects -- the returned instance is required for the InstallerScriptInterface path
return new PlgSystemMarkdownalternateInstallerScript();
