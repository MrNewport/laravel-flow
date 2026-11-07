# Laravel Flow

Transactional workflow steps, transitions, assignment records and after-commit action events for Laravel. Use the engine independently, or compose it with Laravel Workflow for scoped runs, immutable definitions, command replay and audited history.

**[Interactive workshop](https://matthewnewport.com/packages/laravel-flow/lab)** — Try guided examples in your browser.

```sh
composer require mrnewport/laravel-flow:^2.2
php artisan vendor:publish --tag=flow-config
php artisan migrate
```

Choose `flow.morph_key_type` (`int`, `uuid`, `ulid`) **before the first migration**. If your Composer registry has not indexed the tag, add the GitHub VCS repository:

```sh
composer config repositories.laravel-flow vcs https://github.com/MrNewport/laravel-flow
composer require mrnewport/laravel-flow:^2.2
```

Laravel 12/PHP 8.2+ and Laravel 13 with its supported PHP runtime are supported. Laravel discovers the provider. No Fields, Filament, Workflow, DocSign or frontend build is required.

```php
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\{FlowStep, FlowTransition};

FlowStep::firstOrCreate(['id' => 'review.v1'], ['name' => 'Review']);
FlowTransition::firstOrCreate([
    'step_id' => 'review.v1', 'action' => 'approve', 'next_step_id' => 'END',
]);
$instance = FlowManager::startFlow('review.v1', $savedSubject);
$step = $instance->steps()->whereNull('finished_at')->sole();
$results = FlowManager::actionStep($step, 'approve'); // [null]: terminal branch
```

Resolve and authorize the saved subject and its step in the host application. Native Flow deliberately leaves actor policy, tenant ownership, idempotency, immutable graph versions and external delivery to that application or the optional Workflow layer.

The [handbook](docs/HANDBOOK.md) covers installation, every model/trait/manager method, assignments, transactions, parallel branches, commands, migrations and delivery recovery. The [feature index](docs/FEATURES.md) links signatures, returns, failures and examples. The [recipes](docs/RECIPES.md) provide transactional approval, independent parallel reviews, custom assignment composition and optional Fields/Workflow integration. [Executable definitions](examples/review.php) are checked by the documentation test.

Run `composer check` in the package checkout for Pint, Larastan and tests. CI also exercises minimum dependencies, supported PHP/Laravel combinations and isolated MySQL behavior. See the handbook before upgrading saved workflow data or enforcing morph aliases.

[Changelog](CHANGELOG.md) · [MIT license](LICENSE)
