# CKPool Solo Stats Viewer

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-8.0%2B-blue.svg)](https://www.php.net/)

A modern, fast, and secure PHP-based web interface for viewing **ckpool** statistics. It features a lightweight design, dynamic multi-series charts, automatic light/dark theme switching, and is built with performance and security in mind using SQLite for historical data storage.

---

## Live Demo

A live version of this stats viewer is running at:

* **Pool Overview:** [88x.pl/btcnode/](https://88x.pl/btcnode/)
* **User Example:** [1HANfVC...](https://88x.pl/btcnode/?btc_address=1HANfVCfy9CFp5JAjNBhKWPWbavjXxdCRR)

---

## Prerequisites

This project is a statistics viewer **for an existing, running instance of ckpool**. It does not include the mining pool software itself. You must have a functional `ckpool` installation writing logs and user/pool status files.

* **ckpool Source & Info:** [ckolivas/ckpool on Bitbucket](https://bitbucket.org/ckolivas/ckpool-solo/src/solobtc/)

## ? Key Features

* **Live User/Pool Statistics:** Periodically updates stats for all active users (including individual workers) and the pool by parsing ckpool's status files.
* **Network Status:** Fetches live network hashrate and difficulty adjustment predictions from external APIs.
* **Interactive Charts:** Dynamic, multi-series charts for user, worker, pool, and network hashrate/difficulty with selectable time ranges (24H, 7D, 30D).
* **Highly Performant:** Background cron jobs incrementally parse new data and store historical stats in optimized SQLite databases (`stats.db`, `network.db`). The frontend reads pre-processed data, ensuring instant page loads.
* **Dual Themes:** A clean interface ("Liquid Lava" dark theme default) with automatic light/dark mode detection based on system preferences, plus a manual override switch.
* **Secure by Design:** Protected against common web vulnerabilities (XSS, SQL Injection). Parser scripts are secured from public web access via NGINX.
* **Self-Contained Core:** User/Pool stats rely only on local ckpool data. Network data uses public APIs.
* **Detailed Metrics:** Includes "time ago" formatting, block finding probability estimates, rejected share rates, and difficulty adjustment progress.

---

## ? Installation Guide

### 1. Clone the Repository

Clone this repository into your desired web server directory.

```bash
git clone [https://github.com/hantiiii/light-ckpool-solo-stats-viewer.git](https://github.com/hantiiii/light-ckpool-solo-stats-viewer.git) /var/www/html/btcnode
```
*(Adjust the path `/var/www/html/btcnode` if necessary)*

### 2. Configure NGINX

Add a server block to your NGINX configuration to serve the application and secure the parser scripts.

```nginx
server {
    listen 443 ssl http2;
    server_name your_domain.com;
    root /var/www/html; # Adjust if your root is different
    index index.php;

    # SSL configuration...

    # Handle the application routing
    location /btcnode/ {
        try_files $uri $uri/ /btcnode/index.php?$args;
    }

    # Block public access to parser scripts
    location = /btcnode/parser.php { deny all; }
    location = /btcnode/prediction_parser.php { deny all; }

    # Process PHP files
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        # Adjust to your PHP-FPM socket path and version
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; 
    }
}
```

After saving, test and restart NGINX:

```bash
sudo nginx -t && sudo systemctl restart nginx
```

### 3. Set Permissions

Set the ownership and permissions for the project directory. The parsers, run by `root`, will manage permissions for the database files they create within the `data/` directory.

```bash
# Replace 'www-data' with your web server's group (e.g., 'client1')
# Replace /var/www/html/btcnode with your actual project path
WEB_GROUP=www-data 
PROJECT_PATH=/var/www/html/btcnode

sudo chown -R root:${WEB_GROUP} ${PROJECT_PATH}

# Set secure permissions: directories 775, files 644
sudo find ${PROJECT_PATH} -type d -exec chmod 775 {} \;
sudo find ${PROJECT_PATH} -type f -exec chmod 644 {} \;

# Ensure parser scripts are executable by root
sudo chmod +x ${PROJECT_PATH}/parser.php
sudo chmod +x ${PROJECT_PATH}/prediction_parser.php
```

### 4. Configure the Parsers

Edit the configuration variables at the top of **both** `parser.php` and `prediction_parser.php` files to match your environment.

```php
// In /var/www/html/btcnode/parser.php AND prediction_parser.php

// User and group of your web server process (e.g., nginx, apache)
$webUser     = 'www-data'; // e.g., 'web1'
$webGroup    = 'www-data'; // e.g., 'client1'

// Path to ckpool log file (needed by prediction_parser.php for difficulty)
$logFilePath = '/var/log/ckpool/ckpool.log'; 

// Paths to ckpool status directories (needed by parser.php)
$usersDir = '/var/log/ckpool/users/';
$poolDir = '/var/log/ckpool/pool/'; 
```

### 5. Set Up Cron Jobs

Two cron jobs are needed, running as `root` to ensure permissions to read logs/status files and write database files.

1.  Open the root crontab editor:
    ```bash
    sudo crontab -e
    ```
2.  Add the following lines (adjust paths and schedule as needed) and save the file:

    ```cron
    # Update user/pool stats every 5 minutes
    */5 * * * * /usr/bin/php /var/www/html/btcnode/parser.php >/dev/null 2>&1

    # Update network stats & difficulty prediction (e.g., 4 times a day at minute 33)
    33 2,8,14,20 * * * /usr/bin/php /var/www/html/btcnode/prediction_parser.php >/dev/null 2>&1 
   ```

3.  Run both parsers manually **once** to initialize the databases:
    ```bash
    sudo /usr/bin/php /var/www/html/btcnode/parser.php
    sudo /usr/bin/php /var/www/html/btcnode/prediction_parser.php
   ```

Your stats page should now be live and collecting data!

---

## ? Support

If you find this project useful and want to show your appreciation, donations are welcome!

**BTC:** 1HANfVCfy9CFp5JAjNBhKWPWbavjXxdCRR

---

## ? License

This project is licensed under the MIT License.