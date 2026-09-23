=== CacheArmor ===
Contributors: mandaffaaord
Tags: rest-api, cache, performance, api
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disk cache for expensive WP REST API responses, with stale-while-revalidate and stampede protection. Full route picker, no paid tier.

== Description ==

Most REST caching stops at "store the response, serve it until it expires". The hard part is the moment it *does* expire: that is when every client that was being served instantly is suddenly queued behind one slow render.

This plugin is built around that moment.

**What makes it different**

* **Stale-while-revalidate.** Once an entry exists, it keeps being served after it expires while a single request refreshes it in the background, so visitors are not held up by the refresh.
* **Stampede protection.** When an entry is missing or has expired entirely, exactly one request regenerates it. The others wait briefly for that result instead of running the same expensive render in parallel.
* **Files on disk, no custom tables.** Cached responses are files, not database rows, and there is no must-use plugin to drop into `mu-plugins`. The only things stored in the database are your settings and a note of when the cache was last cleared.
* **Opt-in per route, from a settings screen.** Choose exactly which routes to cache, set a query filter and a lifetime for each, and see which routes carry a permission check. Nothing is cached until you enable it.
* **Safe by default.** Only anonymous GET requests are ever cached. Routes whose responses differ per user or per shopping session, such as `/wp/v2/users` and the WooCommerce cart and checkout, cannot be enabled at all, and requests that carry a shopping session are never served from the cache.
* **Bounded.** The cache stops growing at 1,000 entries or 256 MB, whichever comes first, so it can never fill the disk.
* **Multisite aware.** Every site in a network has its own cache.
* **Fails open.** An error, a corrupt file, or an unwritable directory falls back to normal uncached behaviour rather than breaking the endpoint.
* **No external requests, no telemetry, no data collection.** Ever.

**Why caching is opt-in**

Caching the wrong endpoint can serve one visitor's data to another. So the plugin does nothing until you enable a route yourself.

The settings screen lists every REST route registered on your site, including routes added by other plugins and your own custom endpoints, with a filter to find the ones you want. Each is marked "Permission check" or "No permission check" so you can see which ones restrict access before you decide.

**A worked example**

A collection request that renders page-builder content for dozens of posts can take many seconds and hold a PHP worker for the whole time. When several clients poll that endpoint, the worker pool fills and the whole site slows down. Cache that one route and the cost collapses to a file read, even at the moment the entry expires.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/cachearmor/` or install it through the Plugins screen.
2. Activate the plugin.
3. Go to **Settings > CacheArmor** and tick the routes you want cached. Until you do, nothing is cached.

Optionally set a query filter per route, for example `categories=42`, so only matching requests are cached. Leave it empty to cache every variation of that route.

Developers can add rules in code instead, in a theme's `functions.php` or a site-specific plugin. Rules from both sources are combined:

`add_filter( 'cachearmor_rules', function ( $rules ) {
    $rules[] = array(
        'route' => '/wp/v2/pages',
        'query' => array( 'categories' => 42 ),
        'ttl'   => 900,
    );
    return $rules;
} );`

== Frequently Asked Questions ==

= Why does it cache nothing after activation? =

By design. Caching the wrong endpoint can serve one visitor's data to another, so you must choose which routes are safe to cache under Settings > CacheArmor.

= Does it support custom endpoints? =

Yes. The settings screen lists all registered REST routes, including those added by other plugins and your own code, so custom endpoints appear alongside the core ones. Routes with placeholders, such as a single post by ID, cannot be selected there yet; add those with the `cachearmor_rules` filter.

= What do "Permission check" and "No permission check" mean? =

A route with a permission check may give different responses to different visitors, so cache it only if you are certain every anonymous visitor receives the same response.

"No permission check" means anyone may call the route. It does not guarantee that every visitor gets the same response: a route can still vary by cookie, session or location. The same care applies.

= How do I know whether a response came from cache? =

Responses carry an `X-CacheArmor` header with a value of HIT, STALE or MISS, plus `X-CacheArmor-Age` in seconds.

= How do I pause caching? =

Tick **Pause caching** under Settings > CacheArmor. To pause it from code instead, add `define( 'CACHEARMOR_DISABLE', true );` to `wp-config.php`.

= How do I clear the cache? =

Click **Clear cache now** on the settings screen, or **Clear REST cache** in the admin bar. Developers can call `CacheArmor::purge_all()`.

The cache is also cleared automatically when content visitors can see is published, updated, unpublished or deleted, and when a category, tag or other public term changes. Drafts and internal post types do not trigger it. Narrow it further with the `cachearmor_should_purge` filter. The settings screen shows when the cache was last cleared and why.

= Where are the cache files stored? =

In a `cachearmor` folder inside your uploads directory, usually `wp-content/uploads/cachearmor/`, resolved at runtime with `wp_upload_dir()`. On multisite, each site's lives in that site's own uploads directory. The folder is protected from direct access and removed when you delete the plugin.

Entries are plain JSON data; the plugin never writes files containing code.

= What is never cached? =

Logged-in requests, anything other than GET, non-200 responses, routes with no matching rule, blocked routes, requests carrying a shopping session, and payloads larger than `max_bytes` (8 MB by default). Add your own exclusions with the `cachearmor_blocked_routes` and `cachearmor_bypass_request` filters.

= What happens when the cache is full? =

New responses are simply not stored until the cache is next cleared; existing entries keep being served and refreshed. The settings screen tells you when this happens. Narrowing your query filters is usually the fix, or raise the limits as below.

= Can I change the timings and limits? =

Yes, with the `cachearmor_config` filter. Options are `ttl`, `grace`, `lock_ttl`, `cold_wait`, `max_bytes`, `max_entries`, `max_total_bytes`, `send_headers`, and `dir` to store the cache somewhere other than the uploads directory.

= Does it work on multisite? =

Yes. Each site in the network keeps its own cache, so one site can never be served another's responses, and clearing one site's cache leaves the others alone.

= Does it work with object caching or a page cache? =

Yes. It operates inside the REST dispatch cycle and does not interact with page caches, which normally exclude `/wp-json/` anyway.

= Where is the source code? =

The source lives at the URL given in the Plugin URI field. The distributed plugin contains the complete, unminified source; there is no build step.

== Changelog ==

= 1.3.0 =
* First public release.
