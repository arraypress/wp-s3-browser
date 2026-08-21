# S3 Browser for WordPress

Browse, upload and manage S3-compatible storage from inside WordPress — as a media modal tab, or on
an admin screen of your own.

Built for plugins that sell or deliver files: the browser drops into the EDD download editor and the
WooCommerce product editor, and hands back an object key your plugin stores.

## Requirements

- PHP 8.2+
- WordPress 6.8+
- Extensions: `simplexml`, `curl`, `json`, `mbstring`

## Installation

```bash
composer require arraypress/wp-s3-browser
```

## Getting started

```php
use ArrayPress\S3\Browser;
use ArrayPress\S3\Provider;

$browser = new Browser(
    Provider::r2( 'your-account-id' ),
    $access_key,
    $secret_key,
    [ 'download' ],   // post types this browser appears for; [] means all
    'my-bucket',      // bucket it opens on
    'upload_files',   // capability required to use it
    'edd'             // context: which integration this instance serves
);

// Confine it to one bucket. Worth doing whenever you can.
$browser->set_allowed_buckets( [ 'my-bucket' ] );
```

That registers a media modal tab, the admin assets, and a REST namespace. Nothing else is required.

### Providers

The provider list, endpoints and addressing rules live in
[`arraypress/wp-s3-signer`](https://github.com/arraypress/wp-s3-signer), which this package depends
on and re-exports as `SignerProvider`:

```php
use ArrayPress\S3Signer\Provider as SignerProvider;

Provider::r2( 'account-id' );                       // Cloudflare R2
Provider::aws( 'eu-west-1' );                       // Amazon S3
Provider::regional( SignerProvider::DigitalOcean, 'nyc3' );
Provider::custom( 'minio.example.com:9000', 'us-east-1' );
```

Region lists for a settings dropdown come from the same enum:

```php
foreach ( SignerProvider::Aws->regions() as $code => $label ) {
    printf( '<option value="%s">%s</option>', esc_attr( $code ), esc_html( $label ) );
}
```

## Using the client directly

`Browser` owns a `Client`; you can also build one on its own.

```php
use ArrayPress\S3\Client;
use ArrayPress\S3\Provider;

$client = new Client( Provider::r2( 'account-id' ), $access_key, $secret_key );

$objects = $client->get_object_models( 'my-bucket', 100, 'invoices/' );

if ( $objects->is_successful() ) {
    foreach ( $objects->get_data()['objects'] as $object ) {
        echo $object->get_filename(), ' — ', $object->get_size(), "\n";
    }
}
```

Every call returns a `ResponseInterface`. Check `is_successful()`; on failure the response carries
the provider's own error code, which is what distinguishes a bucket-scoped token from wrong
credentials:

```php
if ( ! $objects->is_successful() ) {
    error_log( $objects->get_error_code() );    // e.g. 'AccessDenied', 'NoSuchBucket'
    return $objects->to_wp_error();             // for returning from a REST callback
}
```

### Presigned URLs

```php
$url = $client->get_presigned_url( 'my-bucket', 'invoices/2026-01.pdf', 15 );

if ( $url->is_successful() ) {
    wp_redirect( $url->get_url() );
}
```

### Cache

Responses are cached in transients. Invalidation bumps a generation counter folded into every key,
so it works on sites running a persistent object cache, where deleting rows from `wp_options` would
clear nothing.

```php
$client->cache()->flush_bucket( 'my-bucket' );
$client->cache()->flush();
```

### Permissions

There is no S3 call that reports what a token may do, so this finds out by writing a small object
and deleting it again. The result is cached for a day, because each check costs a write against the
bucket.

```php
$permissions = $client->permissions()->check( 'my-bucket' );
// [ 'read' => true, 'write' => true, 'delete' => false, ... ]

// Reads only — no write is attempted.
$permissions = $client->permissions()->check( 'my-bucket', true, false );
```

## Browser uploads need CORS

Uploads go straight from the visitor's browser to the provider over a presigned URL, so the bucket
needs a CORS rule naming your site.

```php
$client->set_cors_scenario( 'my-bucket', 'upload_only', [ home_url() ] );
```

On Cloudflare R2 this needs an **Admin Read & Write** API token. An **Object Read & Write** token can
list, upload and delete objects perfectly well but cannot call `PutBucketCors`, and R2 answers with
`AccessDenied` — which reads like bad credentials and is not. Set CORS from the Cloudflare dashboard
instead, or issue an admin token for the initial setup.

## Running two browsers on one site

A site with both an EDD plugin and a WooCommerce plugin bundling this library gets two `Browser`
instances. Give each a distinct `$context` and they separate cleanly: media tab ids, asset handles
and REST route bases all derive from it.

REST namespaces additionally derive from the Composer/Strauss prefix, so two separately-distributed
copies do not collide on a route path — a URL is global in a way PHP namespaces are not, and
`WP_REST_Server` merges same-path registrations rather than replacing them, which would otherwise
have one plugin serving the other's requests with its own credentials.

## Layout

```
src/
  Browser.php        Composition root: builds everything below and hooks it up
  Client.php         Cached, validating operations returning response objects
  Api.php            Signing and transport
  Provider.php       Endpoints and addressing
  Cache.php          Transient cache with generation-counter invalidation
  Permissions.php    What a set of credentials can actually do
  Admin/             Media tab, assets, templates, screen tests, translations
  Rest/              REST routes, permission checks and handlers
  Xml/               Parsing S3 payloads, and building request bodies
  Cors/              Rule generation and analysis
  Models/            S3Object, S3Bucket, S3Prefix
  Responses/         Typed success and error responses
  Tables/            WP_List_Table implementations
  Utils/             Path, filename, MIME and validation helpers
```

## Tests

```bash
composer test
```

Transport is covered end to end against real provider payloads with no network: `tests/Support/FakeHttp`
stands in for the WordPress HTTP API and `tests/fixtures/` holds captured S3 and R2 responses.

## License

GPL-2.0-or-later. Developed by [ArrayPress](https://arraypress.com).
