# H.A.N.T.I. v8 Catalyst - CKPool Node Dashboard

**Version:** v90 (Fortress Edition)

Professional, lightweight, and robust statistics dashboard for Solo Mining CKPool nodes.
This project provides real-time visualization of miner performance, network statistics, and difficulty predictions, now secured with enterprise-grade input sanitization.

## 🟢 Live Version
**Check the live node stats here:** [https://88x.pl/btcnode/](https://88x.pl/btcnode/)

---

## 🚀 Key Features

* **Real-time Monitoring:** Reads directly from CKPool logs and status files with negligible overhead.
* **H.A.N.T.I. v8 "Catalyst" Engine:** Proprietary algorithm for estimating difficulty adjustments.
    * *New:* Now factors in **Fee Pressure** and **BTC Price Momentum** as catalysts for hashrate changes.
    * *Equation:* `Δ Diff = ∫(Block Time × Hashrate) × Catalyst(Price + Fees)`
* **High-Resolution Charts:**
    * **24h:** Raw 5-minute data granularity.
    * **7d / 30d:** Precise hourly averages (1h resolution).
    * **1y:** Daily historical view with trend lines.
* **Optimized Core (v90 Fortress):**
    * **Security First:** Refactored backend preventing Command Injection via argument escaping.
    * **High Performance:** SQLite tables now utilize indexing for millisecond-latency queries.
    * **Memory Safe:** "The Tank" parser optimized for low-RAM environments.
* **Responsive Design:** Fully adaptive Dark Mode interface with dynamic status badges.

## 🛠️ Technical Stack

* **Backend:** PHP 7.4+ (CLI & FPM)
* **Database:** SQLite3 (WAL disabled for maximum stability & portability)
* **Frontend:** Vanilla JS + Chart.js 4.4 (Zero dependencies)
* **System:** Designed for Linux environments (Debian/Ubuntu) running ckpool-dr.

## ⚙️ Installation

1.  Clone the repository to your web directory.
2.  Ensure PHP has write permissions to the `/data` folder:
    ``` bash
    chown -R www-data:www-data /path/to/btcnode/data
    chmod -R 775 /path/to/btcnode/data
    ```
3.  Set up the cron job for the parsers (e.g., every 5min for parser, and 4h for prediction_parser):
    ``` cron
    * * * * * /usr/bin/php /path/to/btcnode/parser.php >/dev/null 2>&1
    * * * * * /usr/bin/php /path/to/btcnode/prediction_parser.php >/dev/null 2>&1
    ```

## 🔒 Nginx Configuration (Crucial)

To ensure the security of your node logs and database files, you must block access to the `/data` directory.

``` nginx
server {
    listen 80;
    server_name your-node.com;
    root /var/www/btcnode;
    index index.php;

    # SECURITY: Deny access to internal data, git, and sensitive files
    location ~ ^/data/ { deny all; return 403; }
    location ~ /\.git { deny all; return 403; }
    location ~ \.(db|log|txt|state)$ { deny all; return 403; }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php7.4-fpm.sock; # Adjust PHP version if needed
    }
}
```

## ☕ Support development

If you find this dashboard useful for your solo mining operation, consider supporting the development.

**BTC Donation Address:**
`1HANfVCfy9CFp5JAjNBhKWPWbavjXxdCRR`

---
*Powered by H.A.N.T.I. v8 Catalyst Engine*