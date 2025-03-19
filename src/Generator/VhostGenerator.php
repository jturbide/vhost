<?php

namespace App\Generator;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use App\Util\HostsFileManager;

/**
 * Class VhostGenerator
 *
 * Generates Apache / Nginx vhost configs, updates hosts file, manages
 * global snippet injection into the main config file, restarts services,
 * clears PHP caches, etc.
 *
 * Usage typically orchestrated by a Symfony Console command which instantiates this class.
 */
class VhostGenerator
{
    /**
     * @var array $config      Holds parsed data from config.yaml
     * @var array $sites       Holds parsed data from sites.yaml
     */
    private array $config = [];
    private array $sites = [];
    
    /**
     * Constructor that stores CLI flags/options and initializes config data.
     *
     * @param string          $os                 Detected or overridden OS (e.g. "Linux", "Windows").
     * @param bool            $force              Overwrite existing files if true.
     * @param bool            $backup             Create backups of existing files if true.
     * @param bool            $verbose            Whether we log extra info or not.
     * @param bool            $restartApacheNginx Whether to restart the web services after generation.
     * @param bool            $restartPhpFpm      Whether to restart PHP-FPM services (Linux only).
     * @param bool            $clearOpcache       If true, clear OPCache on all discovered PHP versions.
     * @param bool            $clearApcu          If true, clear APCu on all discovered PHP versions.
     * @param bool            $clearSessions      If true, remove session files from each discovered PHP version.
     * @param OutputInterface $output             Symfony Console output for logging.
     */
    public function __construct(
        private string $configFile,
        private string $sitesFile,
        private string $os,
        private bool $force,
        private bool $backup,
        private bool $verbose,
        private bool $restartApacheNginx,
        private bool $restartPhpFpm,
        private bool $clearOpcache,
        private bool $clearApcu,
        private bool $clearSessions,
        private OutputInterface $output
    ) {
        // Load the config.yaml + sites.yaml data
        $this->loadConfigs();
    }
    
    /**
     * Main entry point after instantiation:
     *  - Create or update global snippet files + inject lines into main config
     *  - Generate site-level vhost configs
     *  - Update hosts file
     *  - Restart web servers and/or PHP-FPM if requested
     *  - Clear caches if requested
     */
    public function run(): void
    {
        // 1) Create or update global snippet for each enabled server (Apache/Nginx)
        $this->createGlobalSnippets();
        
        // 2) Generate site vhost configs
        foreach ($this->sites as $site) {
            $this->generateSiteConfigs($site);
        }
        
        // 3) Update system hosts file
        $this->updateHostsFile();
        
        // 4) Optionally restart Apache & Nginx
        if ($this->restartApacheNginx) {
            $this->restartWebServers();
        }
        
        // 5) Optionally restart PHP-FPM (Linux only)
        if ($this->restartPhpFpm && strtolower($this->os) === 'linux') {
            $this->restartPhpFpmServices();
        }
        
        // 6) Optionally clear PHP caches
        if ($this->clearOpcache || $this->clearApcu || $this->clearSessions) {
            $this->clearPhpCaches();
        }
    }
    
    /**
     * Loads config.yaml and sites.yaml from the local filesystem.
     * Throws errors and exits if mandatory sections are missing or files don't exist.
     */
    private function loadConfigs(): void
    {
        $config = $this->configFile;
        $sites = $this->sitesFile;
        
        // 1) Load config.yaml
        if (!file_exists($config)) {
            $this->printError("Cannot find $config");
            exit(1);
        }
        
        try {
            $this->config = Yaml::parseFile($config);
        } catch (\Exception $e) {
            $this->printError("Failed to parse $config: " . $e->getMessage());
            exit(1);
        }
        
        if (!isset($this->config['platforms'])) {
            $this->printError("Missing 'platforms' section in $config");
            exit(1);
        }
        
        // 2) Load sites.yaml
        if (!file_exists($sites)) {
            $this->printError("Cannot find $sites");
            exit(1);
        }
        
        try {
            $sitesData = Yaml::parseFile($sites);
        } catch (\Exception $e) {
            $this->printError("Failed to parse $sites: " . $e->getMessage());
            exit(1);
        }
        
        if (!isset($sitesData['sites']) || !is_array($sitesData['sites'])) {
            $this->printError("Missing or invalid 'sites' section in sites.yaml");
            exit(1);
        }
        
        $this->sites = $sitesData['sites'];
        
        $this->printOk("YAML configuration loaded successfully.", OutputInterface::VERBOSITY_VERBOSE);
    }
    
    /**
     * Iterates over (apache/nginx) for the current platform
     * and if enabled, calls createOrUpdateGlobalSnippet() to
     * manage the global snippet file & injection into main conf.
     */
    private function createGlobalSnippets(): void
    {
        $normalizedOs = strtolower($this->os);
        if (!isset($this->config['platforms'][$normalizedOs])) {
            $this->printWarning("No platform config found for OS $normalizedOs");
            return;
        }
        
        $platformConfig = $this->config['platforms'][$normalizedOs];
        
        // If Apache is enabled, handle its global snippet
        if (!empty($platformConfig['apacheEnabled'])) {
            $this->createOrUpdateGlobalSnippet('apache', $platformConfig);
        }
        
        // If Nginx is enabled, handle its global snippet
        if (!empty($platformConfig['nginxEnabled'])) {
            $this->createOrUpdateGlobalSnippet('nginx', $platformConfig);
        }
    }
    
    /**
     * Creates or updates a global snippet file (e.g. "global-apache.conf")
     * placed in the same directory as the main confFile, then injects
     * an "Include" line in the main config if not already present.
     *
     * @param string $serverType     "apache" or "nginx"
     * @param array  $platformConfig The config array relevant to this OS
     */
    private function createOrUpdateGlobalSnippet(string $serverType, array $platformConfig): void
    {
        // Retrieve the snippet text from config.yaml
        $snippet = $platformConfig[$serverType]['globalSnippet'] ?? '';
        $snippet = trim($snippet);
        if (!$snippet) {
            // No snippet => do nothing
            $this->printInfo("No global snippet defined for $serverType.", OutputInterface::VERBOSITY_VERY_VERBOSE);
            return;
        }
        
        // The main config file path (e.g. /etc/httpd/conf/httpd.conf)
        $confFile = $this->normalizePath($platformConfig[$serverType]['confFile'] ?? '', $this->os);
        if (!$confFile) {
            $this->printWarning("No confFile set for $serverType; cannot create global snippet.");
            return;
        }
        
        // We'll place the snippet in the same directory as $confFile
        $snippetDir = dirname($confFile);
        $snippetDir = rtrim($snippetDir, '\\/');
        if (!is_dir($snippetDir)) {
            $this->printWarning("Snippet directory does not exist: $snippetDir");
            return;
        }
        
        // We'll name it "global-apache.conf" or "global-nginx.conf"
        $snippetFilename = "global-{$serverType}.conf";
        $snippetPath = $snippetDir . '/' . $snippetFilename;
        
        // Create or overwrite the snippet file if --force is specified
        if (file_exists($snippetPath) && !$this->force) {
            $this->printWarning("Global snippet file exists (use --force): $snippetPath");
        } else {
            // If a file already exists, backup if requested
            if ($this->backup && file_exists($snippetPath)) {
                $bakName = $snippetPath . '.bak-' . date('YmdHis');
                if (@copy($snippetPath, $bakName)) {
                    $this->printInfo("Backup created: $bakName", OutputInterface::VERBOSITY_VERBOSE);
                }
            }
            
            // Write the snippet text
            file_put_contents($snippetPath, $snippet . PHP_EOL);
            $this->printOk("Created/updated snippet file: $snippetPath");
        }
        
        // Now inject the include line into the main conf file
        // Apache uses "Include", Nginx uses "include"
        $includeDirective = ($serverType === 'apache')
            ? "Include $snippetPath"
            : "include $snippetPath;";
        
        $this->injectIncludeLine(
            confFile: $confFile,
            includeLine: $includeDirective,
            serverType: $serverType
        );
    }
    
    /**
     * Injects a block in the main confFile that references our snippet file,
     * wrapped with # BEGIN AUTOGENERATED-$serverType ... # END AUTOGENERATED-$serverType
     *
     * @param string $confFile    Path to the main config file
     * @param string $includeLine The line we want to inject (e.g. "Include /path/to/global-apache.conf")
     * @param string $serverType  "apache" or "nginx"
     */
    private function injectIncludeLine(string $confFile, string $includeLine, string $serverType): void
    {
        if (!file_exists($confFile)) {
            $this->printWarning("Main config file not found: $confFile");
            return;
        }
        
        $content = file_get_contents($confFile);
        
        // If the line is already present, do nothing
        if (str_contains($content, $includeLine)) {
            $this->printInfo("Include line already present in $confFile", OutputInterface::VERBOSITY_VERY_VERBOSE);
            return;
        }
        
        $beginTag = "# BEGIN AUTOGENERATED-$serverType";
        $endTag   = "# END AUTOGENERATED-$serverType";
        $pattern  = "/$beginTag.*?$endTag/s";
        
        // If we already have a block from #BEGIN to #END, we can replace it if --force
        if (preg_match($pattern, $content)) {
            if (!$this->force) {
                $this->printWarning("Autogenerated block exists in $confFile (use --force to overwrite).");
                return;
            }
            // Replace existing block
            $replacement = $beginTag . PHP_EOL . $includeLine . PHP_EOL . $endTag;
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            // Otherwise, append at the end
            $insertBlock = PHP_EOL . $beginTag . PHP_EOL
                . $includeLine . PHP_EOL
                . $endTag . PHP_EOL;
            $content .= $insertBlock;
        }
        
        // Backup if needed
        if ($this->backup) {
            $bakName = $confFile . '.bak-' . date('YmdHis');
            if (@copy($confFile, $bakName)) {
                $this->printInfo("Backup created: $bakName", OutputInterface::VERBOSITY_VERBOSE);
            }
        }
        
        // Write back
        file_put_contents($confFile, $content);
        $this->printOk("Updated main config: $confFile");
    }
    
    /**
     * Generate site-level vhost configs for the current site,
     * for both Apache and Nginx if each is enabled in config.yaml
     *
     * @param array $site The site data from sites.yaml
     */
    private function generateSiteConfigs(array $site): void
    {
        $normalizedOs = strtolower($this->os);
        if (!isset($this->config['platforms'][$normalizedOs])) {
            $this->printError("No platform config found for OS: $normalizedOs");
            return;
        }
        
        $platformConfig = $this->config['platforms'][$normalizedOs];
        
        // If Apache is enabled
        if (!empty($platformConfig['apacheEnabled'])) {
            $this->generateSingleServerConfig($site, 'apache', $platformConfig);
        }
        
        // If Nginx is enabled
        if (!empty($platformConfig['nginxEnabled'])) {
            $this->generateSingleServerConfig($site, 'nginx', $platformConfig);
        }
    }
    
    /**
     * Generates a single vhost file for the given serverType (apache or nginx).
     * Also checks if the doc root directory actually exists, warns & skips if not.
     *
     * @param array  $site           The site data from sites.yaml
     * @param string $serverType     "apache" or "nginx"
     * @param array  $platformConfig The config array for this OS
     */
    private function generateSingleServerConfig(array $site, string $serverType, array $platformConfig): void
    {
        // 1) Validate the template file for this server
        if (!isset($platformConfig[$serverType]['templateFile'])) {
            $this->printWarning("No templateFile defined for $serverType in config.yaml");
            return;
        }
        
        
        $templatePath = $platformConfig[$serverType]['templateFile'];
        if (!str_starts_with($templatePath, '/') && !preg_match('/^[a-zA-Z]:\\\\/', $templatePath)) {
            // If the path is relative, prepend it with the base directory
            $templatePath = __DIR__ . '/../../' . $templatePath;
        }
        $templatePath = $this->normalizePath($templatePath, $this->os);
        
        if (!is_readable($templatePath)) {
            $this->printError("Template file not found or unreadable: $templatePath");
            return;
        }
        
        // 2) Load the template
        $template = file_get_contents($templatePath);
        
        // 3) Build the doc root path (normalize slashes for current OS)
        $rootDir  = $this->normalizePath($platformConfig['rootDir'] ?? '', $this->os);
        $siteRoot = $this->normalizePath($site['root'] ?? '', $this->os);
        $fullRootPath = rtrim($rootDir, '\\/') . '/' . trim($siteRoot, '\\/');
        
        // Check if the doc root directory actually exists
        if (!is_dir($fullRootPath)) {
            $this->printWarning(
                "Document root does not exist: $fullRootPath. Skipping $serverType vhost for site: " . ($site['name'] ?? 'unknown')
            );
            return;
        }
        
        // 4) SSL placeholders
        $sslDefaults = $platformConfig['defaultSsl'] ?? [];
        $defaultSslCert = $sslDefaults['certificate'] ?? '';
        $defaultSslKey  = $sslDefaults['certificateKey'] ?? '';
        
        $sslCert = $site['variables']['ssl_certificate'] ?? $defaultSslCert;
        $sslKey  = $site['variables']['ssl_certificate_key'] ?? $defaultSslKey;
        
        // 5) Site-level custom block (for <Directory> directives, etc.)
        $customBlock = $site['variables']['custom'] ?? '';
        
        // 6) Create placeholder replacements
        $replacements = [
            '{{name}}'                => $site['name'] ?? '',
            '{{aliases}}'             => implode(' ', $site['aliases'] ?? []),
            '{{root}}'                => $fullRootPath,
            '{{php}}'                 => $site['php'] ?? '',
            '{{logDir}}'              => $this->normalizePath($platformConfig[$serverType]['logDir'] ?? '', $this->os),
            '{{ssl_certificate}}'     => $this->normalizePath($sslCert, $this->os),
            '{{ssl_certificate_key}}' => $this->normalizePath($sslKey, $this->os),
            '{{custom}}'              => $customBlock,
        ];
        
        // 7) Perform the replacements, remove leftover placeholders
        $finalConfig = str_replace(array_keys($replacements), array_values($replacements), $template);
        $finalConfig = preg_replace('/\{\{[^}]+\}\}/', '', $finalConfig);
        
        // 8) Determine the final path to write the vhost .conf file
        $confDir = $this->normalizePath($platformConfig[$serverType]['confDir'] ?? '', $this->os);
        $confPath = rtrim($confDir, '\\/') . '/' . ($site['name'] ?? 'unnamed') . '.conf';
        
        // 9) Check if file exists and skip if not forcing
        if (file_exists($confPath) && !$this->force) {
            $this->printWarning("Skipped existing file (use --force): $confPath");
            return;
        }
        
        // 10) Backup existing if requested
        if ($this->backup && file_exists($confPath)) {
            $bakName = $confPath . '.bak-' . date('YmdHis');
            if (@copy($confPath, $bakName)) {
                $this->printInfo("Backup created: $bakName", OutputInterface::VERBOSITY_VERBOSE);
            }
        }
        
        // 11) Write out the final config
        file_put_contents($confPath, $finalConfig);
        $this->printOk("Generated $serverType vhost: $confPath");
    }
    
    /**
     * Updates the system hosts file with site entries, between # BEGIN WEB.LOCAL and # END WEB.LOCAL markers.
     * Uses HostsFileManager::updateHosts() under the hood.
     */
    private function updateHostsFile(): void
    {
        $normalizedOs = strtolower($this->os);
        $hostsFile = $this->config['platforms'][$normalizedOs]['hostsFile'] ?? '';
        
        if (empty($hostsFile)) {
            $this->printWarning("No hostsFile configured for OS: $normalizedOs");
            return;
        }
        
        // Build an array of lines "IP domain"
        $entries = [];
        $defaultIp = $this->config['servers']['localhost'] ?? '127.0.0.1';
        
        foreach ($this->sites as $site) {
            $ip = $defaultIp;
            
            if (!empty($site['server'])) {
                if (isset($this->config['servers'][$site['server']])) {
                    $ip = $this->config['servers'][$site['server']];
                }
                else {
                    $this->printWarning("Server '{$site['server']}' is not configured in config.yaml. Using default IP: $defaultIp");
                }
            }
            
            if (!empty($site['ip'])) {
                $ip = $site['ip'];
            }
            
            // site name
            if (isset($site['name'])) {
                $entries[] = "$ip {$site['name']}";
            } else {
                $this->printWarning("Site does not have a name configured. Skipping.", OutputInterface::VERBOSITY_VERBOSE);
            }
            
            // aliases
            foreach ($site['aliases'] ?? [] as $alias) {
                $entries[] = "$ip $alias";
            }
        }
        
        // Defer actual insertion to a utility class
        if (!is_array($hostsFile)) $hostsFile = [$hostsFile];
        foreach ($hostsFile as $file) {
            if (!HostsFileManager::updateHosts($entries, $file, $this->backup)) {
                $this->printError("Failed updating hosts file: $file");
            } else {
                $this->printOk("Hosts file updated: $file");
            }
        }
    }
    
    /**
     * Restarts web servers (Apache/Nginx) depending on OS:
     * - Linux: systemctl
     * - Windows: net stop/start
     */
    private function restartWebServers(): void
    {
        $normalizedOs = strtolower($this->os);
        
        // Platform-specific configurations for Apache/Nginx
        $platformConfig = $this->config['platforms'][$normalizedOs] ?? null;
        
        if (!$platformConfig) {
            $this->printWarning("No platform configuration found for OS: $normalizedOs");
            return;
        }
        
        $apacheEnabled = $platformConfig['apacheEnabled'] ?? false;
        $nginxEnabled  = $platformConfig['nginxEnabled'] ?? false;
        
        // Skip restarting Apache if disabled
        if (!$apacheEnabled) {
            $this->printInfo("Apache is disabled in the configuration. Skipping restart.", OutputInterface::VERBOSITY_VERY_VERBOSE);
        }
        
        // Skip restarting Nginx if disabled
        if (!$nginxEnabled) {
            $this->printInfo("Nginx is disabled in the configuration. Skipping restart.", OutputInterface::VERBOSITY_VERY_VERBOSE);
        }
        
        // Proceed with restart only if the services are enabled
        if ($apacheEnabled) {
            if (str_contains($normalizedOs, 'win')) {
                $apacheService = $platformConfig['services']['apache'] ?? 'Apache2.4';
                $this->execCmd("net stop \"$apacheService\"");
                $this->execCmd("net start \"$apacheService\"");
            } else {
                $apacheBin = $platformConfig['services']['apache'] ?? 'httpd';
                $this->execCmd("apachectl configtest && systemctl restart $apacheBin");
            }
        }
        
        if ($nginxEnabled) {
            if (str_contains($normalizedOs, 'win')) {
                $nginxService = $platformConfig['services']['nginx'] ?? 'Nginx';
                $this->execCmd("net stop \"$nginxService\"");
                $this->execCmd("net start \"$nginxService\"");
            } else {
                $nginxBin = $platformConfig['services']['nginx'] ?? 'nginx';
                $this->execCmd("nginx -t && systemctl restart $nginxBin");
            }
        }
    }
    
    /**
     * Attempts to restart all discovered PHP-FPM services on Linux.
     * We detect them by listing systemd units that match php.*fpm
     */
    private function restartPhpFpmServices(): void
    {
        exec("systemctl list-units --type=service --all | grep 'php.*fpm' | awk '{print \$1}'", $services);
        foreach ($services as $service) {
            $service = trim($service);
            if (!$service) {
                continue;
            }
            $this->execCmd("systemctl restart {$service}");
        }
    }
    
    /**
     * Clears OPCache, APCu, and/or session files for all discovered Remi-based
     * PHP versions on Linux (i.e. scanning /opt/remi/phpXX).
     */
    private function clearPhpCaches(): void
    {
        $normalizedOs = strtolower($this->os);
        if (!str_contains($normalizedOs, 'linux')) {
            $this->printWarning("PHP cache clearing is only implemented for Linux.");
            return;
        }
        
        // Check if /opt/remi directory exists
        if (!is_dir('/opt/remi')) {
            $this->printWarning("No /opt/remi directory found, skipping PHP cache clearing.");
            return;
        }
        
        // Attempt to list directories matching "php" under /opt/remi
        exec("ls /opt/remi | grep php", $versions);
        
        foreach ($versions as $version) {
            $version = trim($version);
            if (!$version) {
                continue;
            }
            
            // e.g.: /opt/remi/php83/root/usr/bin/php
            $phpBin = "/opt/remi/{$version}/root/usr/bin/php";
            // e.g.: /opt/remi/php83/root/etc/php.ini
            $phpIni = "/opt/remi/{$version}/root/etc/php.ini";
            
            if (!file_exists($phpBin)) {
                continue;
            }
            
            // Build up small php script for clearing caches
            $cmdPieces = [];
            
            // Clear apcu memory from PHP
            if ($this->clearApcu) {
                $cmdPieces[] = "if (function_exists('apcu_clear_cache')) apcu_clear_cache();";
            }
            
            // Clear opcache from PHP
            if ($this->clearOpcache) {
                $cmdPieces[] = "if (function_exists('opcache_reset')) opcache_reset();";
                
                // clear php-fpm default opcache path
                $opcachePath = "/var/opt/remi/{$version}/lib/php/opcache/*";
                $this->execCmd("rm -rf $opcachePath", "Cleared opcache for PHP FPM version {$version}");
                $this->printInfo("PHP FPM version $version opcache path is: $opcachePath", OutputInterface::VERBOSITY_VERY_VERBOSE);
            }
            
            // Remove session files if requested
            if ($this->clearSessions) {
                // Parse php.ini to find session.save_path
                $sessionPath = $this->getSessionPathFromIni($phpIni);
                if ($sessionPath) {
                    $this->execCmd("rm -f {$sessionPath}/sess_*", "Cleared sessions in {$sessionPath}");
                }
                
                // Forcing php-fpm default session path
                $wwwSessionPath = "/var/opt/remi/{$version}/lib/php/session/sess_*";
                $this->execCmd("rm -f {$sessionPath}/sess_*", "Cleared sessions for PHP FPM version $version");
                $this->printInfo("PHP FPM version $version session path is: $wwwSessionPath", OutputInterface::VERBOSITY_VERY_VERBOSE);
            }
            
            // If we have any actual commands to run via php -r
            if (!empty($cmdPieces)) {
                $phpCmd = "$phpBin -r \"" . implode('', $cmdPieces) . "\"";
                $this->execCmd($phpCmd, "Cleared caches for PHP version $version");
            }
        }
    }
    
    /**
     * Helper: parse a php.ini file for session.save_path
     * Returns null if the file doesn't exist or the setting isn't found.
     */
    private function getSessionPathFromIni(string $iniPath): ?string
    {
        if (!file_exists($iniPath)) {
            return null;
        }
        
        // parse_ini_file with sections (true) and raw scanner
        $iniData = @parse_ini_file($iniPath, true, INI_SCANNER_RAW);
        if (!is_array($iniData)) {
            return null;
        }
        
        // Might be in [Session] or in the global config
        if (isset($iniData['Session']['session.save_path'])) {
            return $iniData['Session']['session.save_path'];
        } elseif (isset($iniData['session.save_path'])) {
            return $iniData['session.save_path'];
        }
        
        return null;
    }
    
    /**
     * Helper to execute a shell command and log success or failure.
     * On success, if $successMsg is provided, prints that; else prints "Executed: $command".
     * On failure, logs an error.
     *
     * @param string      $command
     * @param string|null $successMsg
     */
    private function execCmd(string $command, ?string $successMsg = null): void
    {
        exec($command, $outputLines, $exitCode);
        if ($exitCode === 0) {
            $this->printOk($successMsg ?: "Executed: $command", OutputInterface::VERBOSITY_VERBOSE);
        } else {
            $this->printError("Failed to execute: $command");
        }
    }
    
    /**
     * Normalizes filesystem paths depending on the OS:
     *  - On Windows, convert "/" to "\".
     *  - On Linux, convert "\" to "/".
     */
    private function normalizePath(string $path, string $os): string
    {
//        $lowerOs = strtolower($os);
//        if (str_contains($lowerOs, 'win')) {
//            // Convert forward slashes to backslashes
//            return str_replace('/', '\\', $path);
//        }
        // Otherwise, convert backslashes to forward slashes
        return str_replace('\\', '/', $path);
    }
    
    /* ==================== PRINTING / LOGGING HELPERS ==================== */
    
    /**
     * Print a success message with [OK] prefix in green, visible at the given verbosity level.
     */
    private function printOk(string $message, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        if ($this->output->getVerbosity() >= $verbosity) {
            $this->output->writeln("<fg=green>[OK]</> <fg=white>$message</>");
        }
    }
    
    /**
     * Print an error message with [ERROR] prefix in red, visible at the given verbosity level.
     */
    private function printError(string $message, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        if ($this->output->getVerbosity() >= $verbosity) {
            $this->output->writeln("<fg=red>[ERROR]</> <fg=white>$message</>");
        }
    }
    
    /**
     * Print a warning message with [WARNING] prefix in yellow, visible at the given verbosity level.
     */
    private function printWarning(string $message, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        if ($this->output->getVerbosity() >= $verbosity) {
            $this->output->writeln("<fg=yellow>[WARNING]</> <fg=white>$message</>");
        }
    }
    
    /**
     * Print an info message with [INFO] prefix in blue, visible at the given verbosity level.
     */
    private function printInfo(string $message, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        if ($this->output->getVerbosity() >= $verbosity) {
            $this->output->writeln("<fg=blue>[INFO]</> <fg=white>$message</>");
        }
    }
}
