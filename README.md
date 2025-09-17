# CKPool Solo Stats Viewer

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-8.0%2B-blue.svg)](https://www.php.net/)

A modern, fast, and secure PHP-based web interface for viewing ckpool statistics. It features a lightweight design, dynamic multi-series charts, automatic light/dark theme switching, and is built with performance and security in mind.

![CKPool Stats Viewer Screenshot](https://i.imgur.com/f8VzaNc.png)

---

## Live Demo

A live version of this stats viewer is running at:

* **Pool Overview:** [88x.pl/btcnode/](https://88x.pl/btcnode/)
* **User Example:** [1HANfVC...](https://88x.pl/btcnode/?btc_address=1HANfVCfy9CFp5JAjNBhKWPWbavjXxdCRR)

---

## Prerequisites

This project is a statistics viewer **for an existing, running instance of ckpool**. It does not include the mining pool software itself. You must have a functional `ckpool` installation.

* **ckpool Source & Info:** [ckolivas/ckpool on Bitbucket](https://bitbucket.org/ckolivas/ckpool-solo/src/solobtc/)

## ✨ Key Features

* **Live Statistics:** Periodically updates stats for all active users and the pool from new `ckpool.log` entries.
* **Interactive Charts:** Dynamic, multi-series charts for user and pool hashrate with selectable time ranges (24H, 7D, 30D).
* **Highly Performant:** A background cron job incrementally parses new log entries and stores historical data in an optimized SQLite database. The frontend reads pre-processed data, ensuring instant page loads regardless of log file size.
* **Dual Themes:** A beautiful and clean interface with automatic light/dark mode detection based on system preferences, plus a manual override switch.
* **Secure by Design:** Protected against common web vulnerabilities (XSS, SQL Injection). The parser script is secured from public web access via NGINX.
* **Self-Contained:** No external API calls are needed for core functionality.
* **Detailed Metrics:** Includes "time ago" formatting, block finding probability estimates, and rejected share rates.

---

## 🚀 Installation Guide

### 1. Clone the Repository

Clone this repository into your desired web server directory.

```bash
git clone https://github.com/hantiiii/light-ckpool-solo-stats-viewer.git /var/www/html/btcnode
```

### 2. Configure NGINX

Add a server block to your NGINX configuration to serve the application and secure the parser script.

```nginx
server {
    listen 443 ssl http2;
    server_name your_domain.com;
    root /var/www/html;
    index index.php;

    # SSL configuration...

    # Handle the application routing
    location /btcnode/ {
        try_files $uri $uri/ /btcnode/index.php?$args;
    }

    # Block public access to the parser script
    location = /btcnode/parser.php {
        deny all;
    }

    # Process PHP files
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        # Adjust to your PHP-FPM socket path
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    }
}
```

After saving, test and restart NGINX:

```bash
sudo nginx -t && sudo systemctl restart nginx
```

### 3. Set Permissions

Set the ownership and permissions for the project directory. This ensures the web server can read files, but not modify the application code.

```bash
# Replace 'www-data' with your web server's group (e.g., 'client1')
sudo chown -R root:www-data /var/www/html/btcnode

# Set secure permissions: directories 755, files 644
sudo find /var/www/html/btcnode -type d -exec chmod 755 {} \;
sudo find /var/www/html/btcnode -type f -exec chmod 644 {} \;
```

The `parser.php` script, run by `root`, will automatically handle permissions for the data files it creates (`.db`, `.json`, `.state`).

### 4. Configure the Parser

Edit the configuration variables at the top of the `parser.php` file to match your environment.

```php
// in /var/www/html/btcnode/parser.php
$logFilePath = '/var/log/ckpool/ckpool.log'; 
$webUser     = 'www-data'; // e.g., 'web1'
$webGroup    = 'www-data'; // e.g., 'client1'
```

### 5. Set Up the Cron Job

The parser script needs to run periodically. A 5-minute interval is recommended.

1.  Open the root crontab editor:

    ```bash
    sudo crontab -e
    ```

2.  Add the following line and save the file:

    ```cron
    */5 * * * * /usr/bin/php /var/www/html/btcnode/parser.php >/dev/null 2>&1
    ```

3.  Run the parser manually once to initialize the database and stats file:

    ```bash
    sudo /usr/bin/php /var/www/html/btcnode/parser.php
    ```

Your stats page should now be live and collecting data!

---

## 💖 Support

If you find this project useful and want to show your appreciation, donations are welcome!

**BTC:** `1HANfVCfy9CFp5JAjNBhKWPWbavjXxdCRR`

---

## 📄 License

This project is licensed under the MIT License.
