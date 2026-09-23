# CacheArmor – REST API Response Cache

Caches expensive read-only WordPress REST API responses to disk.

Built for a real problem: a public documentation site whose `/wp/v2/pages`
endpoint was polled by hundreds of client installations. Each call rendered 27
page-builder pages, took around 19 seconds, and held a PHP-FPM worker for the
whole time. Enough concurrent calls filled the worker pool and the site stopped
responding. With caching in place the same call returns in about 2.5 seconds
and only one render happens per cache lifetime.

## Features

- **Stale-while-revalidate** — once an entry exists, it keeps being served
  after it expires while a single request refreshes it in the background
- **Stampede protection** — when an entry is missing or has expired entirely,
  exactly one request regenerates it and the rest wait briefly for that result.
  Without this, an expensive endpoint gets a burst of simultaneous renders every
  time the cache clears
- **Settings screen** — pick routes from a filterable list of everything
  registered on the site, including custom endpoints from your own code or other
  plugins; pause caching; see usage and when the cache was last cleared, and why
- **Safe by default** — only anonymous `GET` requests are cached; per-user and
  per-session routes such as `/wp/v2/users` and the WooCommerce cart and checkout
  are blocked; requests carrying a shopping session are never served from cache
- **Bounded** — stops growing at 1,000 entries or 256 MB
- **Multisite aware** — each site keeps its cache in its own uploads directory
- Fails safe: an error, a corrupt file or an unwritable directory falls back to
  normal uncached behaviour
- No custom tables, no external requests, no tracking

## Installation

Copy the folder into `wp-content/plugins/` and activate, or upload the zip via
**Plugins → Add New → Upload Plugin**.

Then open **Settings → CacheArmor** and tick the routes you want cached.
**Nothing is cached until you do.** That is deliberate: caching an endpoint that
returns user-specific data could serve one visitor's data to another.

## Configuring

### From the settings screen

Each route has:

- a checkbox to enable caching
- an optional **query filter**, for example `categories=42`, so only matching
  requests are cached. Leave it empty to cache every variation of the route
- an optional per-route lifetime, overriding the default

Routes are marked **Permission check** or **No permission check** based on
whether they declare a permission callback. Neither label means "safe":

- Most core collection routes declare a callback that filters *what* you see
  rather than gating the route. `/wp/v2/pages` shows "Permission check" and is
  still safe to cache, because only anonymous responses are ever stored.
- "No permission check" routes can still vary per visitor. WooCommerce's
  `/wc/store/v1/cart` has no permission check yet returns each shopper's own
  cart, which is why it is blocked outright.

The question to ask is whether the response depends on anything other than the
URL. Blocked routes cannot be enabled at all; that is enforced both when saving
and at request time.

### From code

Rules from the settings screen and from filters are combined.

```php
add_filter( 'cachearmor_rules', function ( $rules ) {
    $rules[] = array(
        'route' => '/wp/v2/pages',              // exact REST route
        'query' => array( 'categories' => 42 ), // all of these must match
        'ttl'   => 900,                         // optional, seconds
    );
    return $rules;
} );
```

Global settings:

```php
add_filter( 'cachearmor_config', function ( $c ) {
    $c['ttl']             = 900;        // serve fresh for 15 minutes
    $c['grace']           = 1800;       // then serve stale for 30 more while refreshing
    $c['lock_ttl']        = 120;        // max seconds one refresh may hold the lock
    $c['cold_wait']       = 15;         // seconds a request waits for another's render
    $c['max_bytes']       = 8388608;    // never store a payload larger than this
    $c['max_entries']     = 1000;       // stop storing new entries past this many
    $c['max_total_bytes'] = 268435456;  // or past this much disk
    $c['dir']             = '';         // empty: uploads/cachearmor (per site)
    return $c;
} );
```

Narrow when purging happens:

```php
// Ignore term changes; only purge for posts.
add_filter( 'cachearmor_should_purge', function ( $purge, $type, $object_id ) {
    return 'post' === $type;
}, 10, 3 );
```

Exclude more routes, or individual requests:

```php
add_filter( 'cachearmor_blocked_routes', function ( $routes ) {
    $routes[] = '/myplugin/v1/basket'; // prefix match
    return $routes;
} );

add_filter( 'cachearmor_bypass_request', function ( $bypass, $request ) {
    return $bypass || ! empty( $_COOKIE['my_session'] );
}, 10, 2 );
```

## Operating it

Responses carry `X-CacheArmor` (`HIT`, `STALE` or `MISS`) and
`X-CacheArmor-Age` in seconds.

```bash
curl -sI 'https://yoursite.example/wp-json/wp/v2/pages?categories=42' | grep -i x-cachearmor
```

Pause: tick **Pause caching** on the settings screen, or

```php
define( 'CACHEARMOR_DISABLE', true );   // in wp-config.php
```

Purge: **Clear cache now** on the settings screen, **Clear REST cache** in the
admin bar, or `CacheArmor::purge_all()`. The cache is also cleared when
published content, or a term in a public taxonomy, changes. Drafts,
auto-drafts and post types hidden from the REST API, such as oEmbed caches and
WooCommerce orders, do not trigger it.

## Design notes

### Why files rather than transients

The payload in the original case was about 2 MB. Stored as a transient, every
cache hit becomes a 2 MB read from `wp_options`, and the table grows. Files are
faster to read and keep the database clean.

### Where cache files live, and how they are protected

The cache is a `cachearmor` folder in the uploads directory, resolved at runtime
with `wp_upload_dir( null, false )`. That follows WordPress.org's guidance for
plugin data, and it is the one location that is writable even on hosts that
make the rest of `wp-content` read-only. WordPress resolves the uploads
directory per site, so each site in a network gets its own cache. The
`.htaccess` and `index.html` go in the cache folder only, never its parent: a
deny rule in the uploads root would block every media file on the site.

Entries are plain `.json` data files; WordPress.org does not allow plugins to
write files containing executable code. They are protected by:

- **unguessable names** — an MD5 of the request salted with the site's own auth
  salt, so a filename cannot be derived from a URL
- **no listing** — an empty `index.html` in every cache directory
- **`.htaccess`** — `Require all denied`, for Apache
- **public contents** — only anonymous, session-free responses are stored, so
  an entry holds nothing a visitor could not fetch from the API anyway

### Why the cache key comes from `REQUEST_URI`

WordPress mutates request parameters during dispatch: `sanitize_params()`
converts `categories=467` from a string to an array, adds defaults, and casts
types. Parameters read at `rest_pre_dispatch` therefore differ from the same
parameters at `rest_post_dispatch`. Deriving the key from the request object
produces a different hash on read and write, so the cache never hits.
`REQUEST_URI` is immutable across both hooks.

The URI is normalised before hashing: query parameters are sorted, so their
order does not create duplicates, and jQuery's `_` cache buster is dropped. The
host and site ID are part of the key, so neither a domain-per-language setup nor
a multisite network can serve one site's entry to another.

### Why `rename()` and `fopen( 'x' )` are used directly

`rename()` on a single filesystem is atomic, so a reader never sees a
half-written entry. `WP_Filesystem::move()` offers no such guarantee.

The refresh lock is created with `fopen( $lock, 'x' )`, which creates the file
only if it does not already exist and does so atomically, so exactly one of any
number of simultaneous requests wins. An earlier check-then-`touch()` lock let
several win when requests arrived at the same instant; the concurrency test
below was written to catch exactly that. WP_Filesystem may be backed by FTP or
SSH, which is neither local nor atomic.

## Tests

The tests are not shipped in the distributed package. They run against stubbed
WordPress functions, so they need only the PHP CLI. Every check passes or fails,
and each file exits non-zero on any failure.

```bash
bash tests/run.sh                  # everything
php tests/test-package.php         # shipped files vs the review team's common issues
php tests/test-core.php            # hits, misses, bypasses, staleness, purging
php tests/test-hardening.php       # isolation, sessions, limits, locking, uninstall
php tests/test-admin.php           # settings, discovery, rendering, accessibility
php tests/test-concurrency.php     # 20 real parallel processes
```

The concurrency test fires 20 processes at the same instant, synchronised on a
start barrier, against both a cold and a fully expired entry. It passes only if
exactly one process renders and the other nineteen are served its result.

## License

GPL-2.0-or-later. See `LICENSE.txt`.
