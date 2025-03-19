# Vhost Generator CLI

A command-line tool built with Symfony Console that automatically generates Apache and Nginx virtual host configurations from YAML definitions, updates system hosts files, restarts web servers, and manages PHP caches (OPCache, APCu, sessions). Supports both Linux and Windows environments.

---

## Features

- Cross-platform: runs on Linux and Windows.
- Generates vhost configs for both Apache and Nginx from simple YAML definitions.
- Injects global config snippets into each server’s main config file (placing them next to confFile).
- Updates /etc/hosts or C:\Windows\System32\drivers\etc\hosts for local domain resolution.
- Respects existing files; uses backups and --force to prevent accidental overwrites.
- Clears PHP caches (OPCache, APCu, sessions) on multiple PHP versions (Linux with Remi).
- Service restarts: can optionally restart Apache, Nginx, and PHP-FPM.
- Verbosity levels with colored [INFO], [OK], [WARNING], and [ERROR] logs.

---

## Requirements

- PHP 8.0+
- Composer (to install dependencies)
- Symfony Console and Symfony YAML libraries (handled by composer.json)
- Linux: systemctl, apachectl, nginx commands for restarts/tests (or suitable equivalents)
- Windows: net stop, net start with correct service names
- Two YAML config files: config.yaml and sites.yaml

---

## Installation

1. Clone or download this repository.
2. Run 'composer install'.
3. Ensure 'bin/vhost.php' is executable (chmod +x if on Linux).
4. Make sure 'config.yaml' and 'sites.yaml' exist in the root directory.

---

## Configuration Files

### config.yaml

Defines per-platform (Linux, Windows) settings, including main config files, vhost directories, service names, default SSL certs, optional global config snippets, and your templates.

Example minimal structure:
```yaml
platforms:
    linux:
        rootDir: /mnt/web/
        hostsFile: /etc/hosts
    
        apacheEnabled: true
        nginxEnabled: true
    
        apache:
          confFile: /etc/httpd/conf/httpd.conf
          confDir: /etc/httpd/conf.d/vhosts
          globalSnippet: |
            # Additional directives for Apache
          logDir: /var/log/httpd
          templateFile: templates/linux/apache.conf
    
        nginx:
          confFile: /etc/nginx/nginx.conf
          confDir: /etc/nginx/conf.d
          globalSnippet: |
            # Additional directives for Nginx
          logDir: /var/log/nginx
          templateFile: templates/linux/nginx.conf
    
        services:
          apache: httpd
          nginx: nginx
    
        defaultSsl:
          certificate: /etc/ssl/default.crt
          certificateKey: /etc/ssl/default.key
    
    windows:
        rootDir: "C:\\Dev\\Web\\"
        hostsFile: "C:\\Windows\\System32\\drivers\\etc\\hosts"
    
        apacheEnabled: true
        nginxEnabled: true
    
        apache:
          confFile: "C:\\httpd\\conf\\httpd.conf"
          confDir: "C:\\httpd\\conf\\vhosts"
          globalSnippet: |
            # Windows Apache directives
          logDir: "C:\\httpd\\logs"
          templateFile: "templates\\windows\\apache.conf"
    
        nginx:
          confFile: "C:\\nginx\\conf\\nginx.conf"
          confDir: "C:\\nginx\\conf.d"
          globalSnippet: |
            # Windows Nginx directives
          logDir: "C:\\nginx\\logs"
          templateFile: "templates\\windows\\nginx.conf"
    
        services:
          apache: "Apache2.4"
          nginx: "Nginx"
    
        defaultSsl:
          certificate: "C:\\ssl\\default.crt"
          certificateKey: "C:\\ssl\\default.key"

servers:
localhost: 127.0.0.1
```

---

### sites.yaml

Lists all the domains to configure, their document root paths, any aliases, custom SSL certs, and optional 'custom' snippet.

Example structure:
```yaml
sites:
- name: myapp.local
  root: MyApp/public/
  php: "83"
  variables:
  custom: |
      # Site-level directives
      php_value upload_max_filesize 128M

- name: admin.myapp.local
  root: MyApp/admin/public/
  aliases:
    - backoffice.myapp.local
      php: "83"
      variables:
      ssl_certificate: /etc/ssl/myapp.crt
      ssl_certificate_key: /etc/ssl/myapp.key
      custom: |
      # Admin-specific directives
```
---

## Usage

Run the command from your project root:
```bash
php bin/vhost.php [options]
```

### CLI Options

`--force, -f`  
Overwrite any existing files (site vhosts, global snippets, main config blocks).

`--no-backup`  
Disable creating .bak-YYYYmmddHHMMSS copies before overwriting.

`--os=VALUE`  
Override OS detection with 'linux' or 'windows'. Defaults to PHP_OS_FAMILY.

`--restart, -r`  
Restart Apache/Nginx after config generation.

`--restart-php`  
Restart discovered PHP-FPM services on Linux.

`--clear-opcache`  
Clear OPCache on all discovered PHP versions (Remi-based).

`--clear-apcu`  
Clear APCu on all discovered PHP versions (Remi-based).

`--clear-sessions`  
Remove session files based on each version’s session.save_path.

### Examples

1) Basic generation (no backups, no restarts):
   ```bash
   php bin/vhost.php --no-backup
   ```
   
2) Force overwrite and restart services:
   ```bash
   php bin/vhost.php --force --restart
   ```

3) Clear all caches (OPCache, APCu, sessions):
   ```bash
   php bin/vhost.php --clear-opcache --clear-apcu --clear-sessions
   ```

4) Manually set OS:
   ```bash
   php bin/vhost.php --os=windows
   ```

---

## How It Works

### Global Snippet Injection

Reads your `config.yaml` for each server (apache or nginx). Places your `globalSnippet` text into a file named `global-apache.conf` or `global-nginx.conf` located in the same directory as `confFile`. Then injects an `Include` or `include` line into your main config, marked by `# BEGIN AUTOGENERATED-apache` or `# BEGIN AUTOGENERATED-nginx`.

### Site Vhost Generation

For each site in sites.yaml:
- Loads the templateFile (e.g. `templates/linux/apache.conf`).
- Fills placeholders like `{{name}}`, `{{root}}`, `{{ssl_certificate}}`, etc.
- Merges optional `{{custom}}` snippet if defined.
- Writes the final `.conf` to the confDir (e.g. `/etc/httpd/conf.d/vhosts/myapp.local.conf`).
- Skips overwriting unless `--force`. If doc root doesn't exist, logs a warning and skips.

### Hosts File Updates

Appends domain entries to your system hosts file in a block marked `# BEGIN WEB.LOCAL` / `# END WEB.LOCAL`. Respects backups if needed. Uses the IP from `servers.localhost` in `config.yaml` by default, or a custom `ip` in `sites.yaml` if present.

### Service Restarts

If `--restart` is specified:
- On Linux, runs 'apachectl configtest && systemctl restart httpd' and 'nginx -t && systemctl restart nginx' (or whatever service names are set in config.yaml).
- On Windows, runs 'net stop' and 'net start' for each service name.

If `--restart-php` is used, it restarts any discovered 'php.*fpm' services on Linux.

### PHP Cache Clearing

If `--clear-opcache` or `--clear-apcu`, the script runs `php -r ...` in each discovered `/opt/remi/phpXX` version to reset OPCache or APCu. If `--clear-sessions`, removes session files in each version’s session.save_path (parsed from php.ini). This feature is Linux-specific by default.

---

## Advanced Topics

### Overwriting and Backups

- By default, files are not overwritten if they exist. Use `--force` to overwrite.
- If backups are enabled, the script copies existing files to filename.bak-YYYYmmddHHMMSS.

### Directory Checks

- If the site document root doesn’t exist, the script logs a warning and skips that site’s vhost generation.

### Customization

- Add more placeholders to your template files (e.g. `{{myVar}}`).
- Modify the script to parse them from site or config variables as needed.
- Adjust session clearing logic to match your system’s layout if not using Remi or if on Windows.

---

## Installing Apache Lounge on Windows and Setting Up the Service

### Step-by-Step Guide

1. **Download Apache from Apache Lounge**
    - Visit [Apache Lounge](https://www.apachelounge.com/) and download the appropriate version of Apache for your
      system (e.g., 64-bit or 32-bit).

2. **Extract Apache Files**
    - Extract the downloaded `.zip` file to a desired location (e.g., `C:\httpd\`).

3. **Configure Apache**
    - Open the `httpd.conf` file located in the `conf` directory of the extracted files (e.g.,
      `C:\httpd\conf\httpd.conf`).
    - Modify the following lines to suit your environment:
        - Update the `ServerRoot` to the extracted path, e.g.:
          ```raw
          ServerRoot "C:/httpd"
          ```
        - Update the `DocumentRoot` to your desired root directory, e.g.:
          ```raw
          DocumentRoot "C:/Dev/Web"
          ```

        - Ensure you have configured your virtual host settings correctly (refer to the relevant sections in this
          document).

4. **Install the Service**
    - Open a Command Prompt with Administrator privileges.
    - Navigate to the `bin` directory of the extracted Apache folder, e.g.:
      ```bash
      cd C:\httpd\bin
      ```
    - Run the following command to install Apache as a Windows service:
      ```bash
      httpd.exe -k install
      ```

5. **Verify Installation**
    - After running the command, Apache is registered as a Windows service.
    - Open the Windows Services Manager (press `Win + R`, type `services.msc`, and hit Enter).
    - Look for the service named `Apache2.4` (or the name defined in your Apache setup).
    - Confirm that the service appears as "Automatic" in the Startup Type column.

6. **Start the Service**
    - You can start Apache in any of the following ways:
        - Run the command in the `bin` directory:
          ```bash
          httpd.exe -k start
          ```
        - Or, start the service from the Windows Services Manager by right-clicking on `Apache2.4` and selecting "
          Start."

7. **Test Your Setup**
    - Open a web browser and navigate to `http://localhost`.
    - If everything is configured correctly, you should see the Apache default page or your defined `DocumentRoot`
      content.

### Uninstalling the Apache Service

- If you need to remove the Apache service, navigate to the `bin` directory and run:
  ```bash
  httpd.exe -k uninstall
  ```

This should properly unregister Apache as a Windows service.

---

## License

This sample project is provided as is, under no specific license unless you add one. Feel free to incorporate it into your private or commercial workflow.

For any questions or suggestions, please open an issue or contact the maintainer.

---

Enjoy automating your virtual host configurations across Linux and Windows!
