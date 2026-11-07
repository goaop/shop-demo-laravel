# Results

Reference run of `bash bench/run-all.sh`:
- Laravel 13.40, goaop-laravel-bridge 2.0.0, Go! AOP 4.0.0;
- PHP 8.5.12 NTS (Ubuntu packages) on 4 vCPUs (cloud sandbox);
- OPcache on, `opcache.jit=disable`, `opcache.file_update_protection=0`;
- Xdebug 3.5.3, for the profiles only.

Machines differ: compare the modes with each other, not the absolute numbers with your hardware.

One request (`/`): 50 orders × 5 lines, a sales report and one call into each of the 40 modules. That makes about
2,600 intercepted method calls and 950 intercepted property accesses. It goes through Laravel's `web` middleware group
(cookies, session, CSRF).

## Web requests

The built-in web server (one worker, OPcache shared memory kept between requests), median time from the first line of
`public/index.php` to the response:

| Mode | cold | warm | hot | hot, `validate_timestamps=0` | peak memory (hot) | files included |
|------|-----:|-----:|----:|-----------------------------:|------------------:|---------------:|
| baseline (no AOP, aspects do not run) | 239 ms | 262 ms | **4.2 ms** | 4.3 ms | 1.1 MB | 754 |
| debug | 563 ms | 292 ms | **23.0 ms** | 21.8 ms | 2.7 MB | 984 |
| prod | 582 ms | 265 ms | **10.8 ms** | 10.6 ms | 2.0 MB | 915 |
| prebuilt | built by `aop:warmup` | 263 ms | **10.5 ms** | 10.4 ms | 2.0 MB | 915 |

- **cold**: no AOP cache and no Laravel caches; the first request weaves (debug, prod).
- **warm**: after the deployment step (`aop:warmup`, `optimize`), freshly started server (empty OPcache).
- **hot**: both caches filled, the steady state.

The deployment step from scratch takes:
- `aop:warmup` + `optimize`: about 1.05 s (`prod`, `prebuilt`);
- `optimize` alone: 0.39 s (`baseline`).

Unlike Symfony, Laravel has no compiled container. Even a cold request is fully woven: all 800 audit records.

## Pod start

First request of a freshly started server after the deployment step of `prebuilt`:

| Variant | first request | hot | files included |
|---------|--------------:|----:|---------------:|
| `prebuilt` | 242 ms | 10.3 ms | 915 |
| + `opcache.file_cache` primed by `aop:warmup-opcache` | 48 ms | 11.0 ms | 915 |
| + `opcache.preload` (`_preload.php` of `aop:warmup --preload`) | 177 ms | 9.8 ms | 549 |
| + both | **32 ms** | 10.0 ms | 549 |
| baseline, no AOP | 212 ms | 4.4 ms | 754 |
| baseline + primed file cache | 36 ms | 4.2 ms | 754 |

The preload script covers the woven and the untransformed classes of `app/`, not Laravel itself, which has no preload
file of its own. The primed file cache is what makes a new container fast. The Docker image of this repository combines
both; a new container serves its first request in about 20 ms.

## Where the time goes

Share of the profiled hot request in the `prebuilt` mode (Xdebug profiler; it slows call-heavy code down the most, so
interception is overstated):

| Bucket | share |
|--------|------:|
| Laravel (container, HTTP kernel, middleware, routing) | 43 % |
| runtime interception (joinpoints, interceptors) | 23 % |
| application code | 18 % |
| advice bodies | 6 % |
| class loading + cache metadata (framework) | 5 % |
| Composer autoloader | 0.7 % |
| framework kernel + container | 0.4 % |
| goaop-laravel-bridge | 0.2 % |

The hot request of `prebuilt` costs 6.3 ms more than `baseline`. The interception itself takes roughly 1.5–2 ms of that:
about 0.3 µs per intercepted call, see the per-call numbers of [goaop/shop-demo](https://github.com/goaop/shop-demo).
The rest is the work the advices do: the caching aspect goes through Laravel's cache repository (`rememberForever()`),
and the other aspects keep timers and records.

File-system calls of one hot request:

| Call | prebuilt | prod | debug | baseline |
|------|---------:|-----:|------:|---------:|
| `filemtime` | 0 | 0 | 245 | 0 |
| `filesize` | 0 | 0 | 236 | 0 |
| `file_get_contents` | 0 | 0 | 97 | 0 |
| `file_exists` | 7 | 7 | 9 | 7 |
