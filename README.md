# Go! AOP Laravel shop demo

A Laravel 13 shop application built with [goaop-laravel-bridge](https://github.com/goaop/goaop-laravel-bridge) 2 and
[Go! AOP](https://github.com/goaop/framework) 4. Logging, caching, transactions, monitoring, auditing and security live
in six aspects. The aspects are resolved through the Laravel container, and the services they apply to stay plain
business code.

The same application is a reproducible benchmark of the bridge: debug, production and prebuilt cache, cold, warm and
hot, with preloading and a primed OPcache file cache in a production-style Docker image. The measured numbers are in
[RESULTS.md](RESULTS.md).

Sister projects with the same shop:
- [goaop/shop-demo](https://github.com/goaop/shop-demo), the framework without a full-stack framework;
- [goaop/shop-demo-symfony](https://github.com/goaop/shop-demo-symfony), with GoAopBundle.

```bash
composer setup                    # install, .env, APP_KEY
composer serve                    # http://127.0.0.1:8080/, local environment, Go! AOP in debug mode
php artisan test
```

## How the bridge is wired in

1. **`composer require goaop/goaop-laravel-bridge`.** Package discovery registers `GoAopServiceProvider`. This demo
   registers it explicitly in [bootstrap/providers.php](bootstrap/providers.php), only so the benchmark can switch it
   off.
2. **List the aspects** in [config/go_aop.php](config/go_aop.php), published with
   `php artisan vendor:publish --tag=goaop-config`. That file also sets the include paths (`app/`). Debug mode follows
   `APP_DEBUG`; production turns on `PREBUILT_CACHE` with `GOAOP_FEATURES=64`.
3. **Write aspects** as classes implementing `Go\Aop\Aspect` ([app/Aspects/](app/Aspects)). Their constructors are
   injected by the container, and an aspect is created on the first call of one of its advices.
4. **Bind by class name** in service providers: the aspect kernel starts at the beginning of the boot phase, so a class
   that a `register()` method resolves would be loaded unwoven
   ([app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php)).
5. **Deploy with `php artisan aop:warmup`, then `php artisan optimize`.** The warm-up weaves the whole application, in
   parallel with `--workers=N`, and writes an `opcache.preload` script with `--preload`. With `PREBUILT_CACHE` the
   warm-up has to come first, because the other artisan commands need a complete AOP cache.

`php artisan about` shows the AOP cache directory and the debug mode.

## The application

| Part | Where | Woven |
|------|-------|:-----:|
| Domain | `app/Domain/`: products, customers, orders, order lines, money, enums | `Product` (property advice) |
| Repositories | `app/Repositories/`: in-memory products, customers, orders (singletons) | yes |
| Services | `app/Services/`: catalog, pricing, discounts, tax, inventory, payment, notifications, reports, the checkout scenario | yes |
| Events | `app/Events/OrderPlaced`, dispatched through Laravel's event dispatcher; `app/Listeners/SendOrderConfirmation` is discovered by event discovery | no |
| Controller | `app/Http/Controllers/ShopController`: `/`, `/checkout`, `/modules` ([routes/web.php](routes/web.php)) | no |
| Infrastructure | `app/Infrastructure/`: in-memory audit log, metrics, transactions (singletons) | no |
| Generated modules | `app/Modules/` (`bin/generate.php [modules=40]`, run on `composer install`): per module a handler and a repository (woven), a validator, a DTO and an event | partly |
| Library code | `lib/` (generated with the modules): stands in for `vendor/`, outside the include paths | no |

The module handlers implement `App\Contracts\ModuleHandler`. `AppServiceProvider` tags them `app.modules`, and
`App\Services\ModuleRunner` receives them with `#[Tag('app.modules')]`.

The infrastructure keeps everything in memory, so the numbers show the cost of the framework, not of I/O. The caching
aspect uses Laravel's `array` cache store. No database is needed: sessions, cache and queue use the array and sync
drivers.

### Aspects

| Aspect | Advice | Pointcut | Depends on |
|--------|--------|----------|------------|
| `LoggingAspect` | `#[Before]` | `@execution(App\Attributes\Loggable)` | `AuditLog` |
| `CachingAspect` | `#[Around]` | `@execution(App\Attributes\Cacheable)` | the cache `Factory` (array store), `Metrics` |
| `TransactionAspect` | `#[Around]` | `@execution(App\Attributes\Transactional)` | `TransactionManager` |
| `MonitoringAspect` | `#[Around]` + `#[AfterThrowing]` | a named `#[Pointcut]` over the repositories, `execution(public App\Services\*->*(*))` | `Metrics`, `AuditLog` |
| `StockAuditAspect` | `#[Around]` on a property (native property hooks) | `access(public App\Domain\Product->stock)` | `AuditLog` |
| `SecurityAspect` | `#[Before]` | `execution(public App\Modules\**\*Service->handle(*))` | `Metrics` |

One request to `/` places 50 orders of 5 lines, builds a sales report and calls every module once. That makes about
2,600 intercepted method calls and 950 intercepted property accesses. The JSON response carries:
- the business result;
- the counters of the aspects;
- timings: total, application boot including the aspect kernel, and handling;
- peak memory, included files and OPcache statistics.

[tests/Feature/ShopTest.php](tests/Feature/ShopTest.php) checks every counter.

### Modes

The modes are real environment variables on top of `.env`:

| Mode | Variables | Go! AOP |
|------|-----------|---------|
| debug | `APP_ENV=local APP_DEBUG=true` | debug mode (follows `APP_DEBUG`): changed classes and aspects are re-woven, XDebug breakpoints work in the original files |
| prod | `APP_ENV=production APP_DEBUG=false` | production mode: woven classes come from the cache through Composer's class map |
| prebuilt | as prod, plus `GOAOP_FEATURES=64` | `PREBUILT_CACHE`: the cache built by `aop:warmup` is trusted completely, never checked or written |
| baseline | as prod, plus `GOAOP_ENABLED=false` | not registered: the same application without AOP, the aspects do not run |

```bash
export APP_ENV=production APP_DEBUG=false GOAOP_FEATURES=64
php artisan aop:warmup --preload && php artisan optimize
php -S 127.0.0.1:8080 -t public public/index.php
```

The benchmark scripts give every mode its own AOP cache (`GOAOP_CACHE_DIR`) and its own Laravel bootstrap caches
(`APP_CONFIG_CACHE`, ...), see [bench/lib.php](bench/lib.php).

### Tests

[tests/Feature/ShopTest.php](tests/Feature/ShopTest.php) runs every test in its own process
(`#[RunTestsInSeparateProcesses]`). A woven method keeps its interceptors, and with them the aspect instances, in a
static variable for the rest of the process, while Laravel creates a new application for every test. In one process,
the aspects of the first test would keep the singletons of the first application.

## Production-style Docker image

```bash
docker build -t shop-demo-laravel .
docker run --rm -p 8080:8080 --read-only --tmpfs /tmp shop-demo-laravel
curl http://127.0.0.1:8080/
```

The [Dockerfile](Dockerfile) builds everything at image build time:
1. an authoritative Composer class map;
2. `aop:warmup --preload` with one worker per CPU, then `optimize`, both with `PREBUILT_CACHE`;
3. the OPcache file cache, with the bridge's `aop:warmup-opcache`.

The container then runs with `PREBUILT_CACHE`, `opcache.validate_timestamps=0` and the AOP preload script
([docker/opcache.ini](docker/opcache.ini)) on a read-only root file system. A new container serves its first request in
about 20 ms. The image uses PHP's built-in server to stay self-contained; production setups use PHP-FPM (or Octane)
with the same `php.ini`.

## Benchmarks

```bash
bash bench/run-all.sh    # ~10 minutes, writes results/
```

Requirements: PHP 8.4+ with OPcache. The profiler runs also need Xdebug installed but not enabled in `php.ini`; the
scripts load it with `-d zend_extension=xdebug` only where they need it. Every run uses `opcache.jit=disable` (the
tracing JIT of PHP 8.5.11/8.5.12 has a bug of its own) and `opcache.file_update_protection=0`, so that files written a
moment before are cached at once.

| Script | Measures |
|--------|----------|
| `bench/smoke.php` | deployment step and two requests in every mode, every counter checked (CI) |
| `bench/run-wallclock.php` | every mode × cold / warm / hot through the built-in web server, and the deployment step |
| `bench/run-startup.php` | the first request of a fresh server with a primed file cache and/or preloading |
| `bench/run-profiles.php` | Xdebug profiler runs (`xdebug.mode=profile`) of every mode and state |
| `bench/analyze-profile.php` | cachegrind analyzer without KCachegrind: time by subsystem (Laravel, the bridge, framework, aspects, application), top functions, file-system calls |

The [CI workflow](.github/workflows/ci.yml) runs on each push:
- the tests and the smoke test, on PHP 8.4 and 8.5;
- the Docker image on a read-only file system.

Run it manually (*Actions → CI → Run workflow*) to get the full benchmark as the job summary and the
`benchmark-results` artifact.

## License

MIT, see [LICENSE](LICENSE).
