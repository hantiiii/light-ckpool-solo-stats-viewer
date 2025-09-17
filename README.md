# CKPool Solo Stats Viewer

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-8.0%2B-blue.svg)](https://www.php.net/)

A modern, fast, and secure PHP-based web interface for viewing ckpool statistics. It features a lightweight design, dynamic multi-series charts, automatic light/dark theme switching, and is built with performance and security in mind.

![CKPool Stats Viewer Screenshot](https://i.imgur.com/f8VzaNc.png)


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

## 🚀 Installation

1.  **Clone the Repository:**
    ```bash
    git clone [https://github.com/hantiiii/light-ckpool-stats-viewer.git](https://github.com/hantiiii/light-ckpool-stats-viewer.git) /var/www/html/btcnode
    ```
 

2.  **Configure NGINX:**
    Add a server block to your NGINX configuration to serve the application and secure the parser script.
    ```nginx
    server {
        listen 443 ssl http2;
        server_name your_domain.com;
        root /var/www/html;
        index index.php;

        # SSL config...

        location /btcnode/ {
            try_files $uri $uri/ /btcnode/index.php?$args;
        }

        location = /btcnode/parser.php {
            deny all;
        }

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; // Adjust to your PHP version
        }
    }
    ```
    Then, test and restart NGINX: `sudo nginx -t && sudo systemctl restart nginx`

3.  **Set Permissions:**
    The parser script, run by `root`, will create and manage permissions for its data files. You just need to set the base directory ownership.
    ```bash
    # Replace 'www-data' with your web server's group (e.g., 'client1')
    sudo chown -R root:www-data /var/www/html/btcnode
    sudo chmod -R 775 /var/www/html/btcnode
    ```

4.  **Configure the Parser:**
    Edit the configuration variables at the top of the `parser.php` file:
    ```php
    // in /var/www/html/btcnode/parser.php
    $logFilePath = '/var/log/ckpool/ckpool.log'; 
    $webUser = 'www-data'; // e.g., 'web1'
    $webGroup = 'www-data'; // e.g., 'client1'
    ```

5.  **Set Up the Cron Job:**
    Open the root crontab (`sudo crontab -e`) and add this line to run the parser every 5 minutes:
    ```crontab
    */5 * * * * /usr/bin/php /var/www/html/btcnode/parser.php >/dev/null 2>&1
    ```
    Run it once manually to initialize everything: `sudo /usr/bin/php /var/www/html/btcnode/parser.php`

---

## 💖 Support

If you find this project useful and want to support its development, donations are welcome!

**BTC:** `1HANfVCfy9CFp5JAjNBhKWPWbavjXxdCRR`

---

## 📄 License

This project is licensed under the MIT License.
