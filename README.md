# S3 Browser for WordPress

A comprehensive PHP library for integrating S3-compatible storage providers with WordPress, featuring an advanced media browser, file management, and support for multiple cloud storage providers.

## Features

- **Multi-Provider Support**: AWS S3, Cloudflare R2, DigitalOcean Spaces, Linode Object Storage, and more
- **Advanced Media Browser**: Native WordPress media uploader integration with breadcrumb navigation
- **File Management**: Upload, download, delete, and organize files across S3-compatible storage
- **Presigned URLs**: Generate secure, time-limited download URLs for private files
- **Plugin Integration**: Built-in support for WooCommerce and Easy Digital Downloads
- **Bucket Management**: List, browse, and manage buckets across providers
- **Caching**: WordPress transient caching for improved performance
- **File Type Detection**: Automatic MIME type detection and file categorization
- **Search & Filter**: Built-in search functionality for finding files quickly
- **WordPress Integration**: Uses WordPress standards (WP_Error, transients, admin styles)

## Installation

Install via Composer:

```bash
composer require arraypress/wp-s3-browser
```

## Basic Usage

### Initialize the Browser

```php
use ArrayPress\S3\Browser;
use ArrayPress\S3\Providers\CloudflareR2;

// Create a provider instance
$provider = new CloudflareR2( 'default', [ 'account_id' => 'your_account_id' ] );

// Initialize the browser
$browser = new Browser(
	$provider,
	'your_access_key',
	'your_secret_key',
	[ 'post', 'page' ], // Allowed post types (optional)
	'default-bucket', // Default bucket (optional)
	'uploads/'        // Default prefix (optional)
);
```

### Working with Buckets

```php
// Code examples coming soon...
```

### Managing Objects

```php
// Code examples coming soon...
```

### File Uploads and Downloads

```php
// Code examples coming soon...
```

### Integration with WordPress Plugins

```php
// Code examples coming soon...
```

## Supported Providers

- **AWS S3** - Amazon Simple Storage Service
- **Cloudflare R2** - Cloudflare's zero-egress object storage
- **DigitalOcean Spaces** - DigitalOcean's S3-compatible storage
- **Linode Object Storage** - Akamai's object storage solution
- **Vultr Object Storage** - Vultr's S3-compatible storage
- **Custom Providers** - Extensible architecture for custom implementations

## Configuration

The library supports various configuration options:

```php
// Configuration examples coming soon...
```

## Cache Management

Built-in caching using WordPress transients:

```php
// Cache management examples coming soon...
```

## API Documentation

For detailed API documentation and advanced usage examples, visit the [documentation](https://github.com/arraypress/s3-browser/wiki).

## Requirements

- PHP 7.4 or later
- WordPress 6.8.1 or later
- Required PHP extensions: simplexml, curl, json, mbstring

## License

This library is licensed under the GPL v2 or later.

## Credits

Developed by [ArrayPress](https://arraypress.com)