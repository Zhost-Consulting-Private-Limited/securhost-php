# SecurHost PHP SDK

Official PHP SDK for the [SecurHost AI Gateway](https://securhost.com). PHP 8.1+ compatible.

[![Release](https://img.shields.io/github/v/release/Zhost-Consulting-Private-Limited/securhost-php?color=blue)](https://github.com/Zhost-Consulting-Private-Limited/securhost-php/releases)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## 📦 Installation

### Install via Composer from GitHub
In your `composer.json`:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Zhost-Consulting-Private-Limited/securhost-php.git"
        }
    ],
    "require": {
        "securhost/ai-sdk": "^0.1.0"
    }
}
```
Or via Packagist:
```bash
composer require securhost/ai-sdk
```

---

## 🚀 Quickstart

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use SecurHost\SecurHostClient;

$client = new SecurHostClient('nxs_live_...');

$reply = $client->chat([
    ['role' => 'user', 'content' => 'Categorize customer feedback sentiment.']
], [
    'model' => 'gpt-4o',
    'request_type' => 'classification'
]);

echo "Response: " . $reply->outputText() . PHP_EOL;
echo "Cost: $" . $reply->cost->amount . " | Saved: $" . $reply->cost->saved . PHP_EOL;
```

---

## 🛠️ Publishing to Packagist

1. Log in to [Packagist.org](https://packagist.org).
2. Submit repository URL: `https://github.com/Zhost-Consulting-Private-Limited/securhost-php`.
3. Set up the GitHub Service Webhook for automatic version updates.

---

## 📄 License
MIT License. Copyright (c) 2026 SecurHost.
