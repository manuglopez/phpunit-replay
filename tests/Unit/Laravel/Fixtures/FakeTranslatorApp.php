<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel\Fixtures;

use Closure;

/**
 * Just enough of `Illuminate\Container\Container` for `Laravel\TranslationTracker` and the
 * arming subscriber: singletons, `extend()` (the closure runs when the service is first built,
 * like Laravel's), `resolved()`, and `bound()`/`instance()` for the arming marker.
 */
final class FakeTranslatorApp
{
    public int $extenders = 0;

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, list<Closure>> */
    private array $extending = [];

    /** @var array<string, true> */
    private array $resolved = [];

    /** @param Closure(): object $factory builds the `translator` service */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function extend(string $abstract, Closure $closure): void
    {
        ++$this->extenders;
        $this->extending[$abstract][] = $closure;
    }

    public function resolved(string $abstract): bool
    {
        return isset($this->resolved[$abstract]);
    }

    public function forceResolved(string $abstract): void
    {
        $this->resolved[$abstract] = true;
    }

    public function bound(string $abstract): bool
    {
        return isset($this->instances[$abstract]);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function swap(string $abstract, object $instance): void
    {
        $this->resolved[$abstract] = true;
        $this->instances[$abstract] = $instance;
    }

    public function make(string $abstract): mixed
    {
        if (! isset($this->instances[$abstract])) {
            $instance = ($this->factory)();

            foreach ($this->extending[$abstract] ?? [] as $extender) {
                $instance = $extender($instance, $this);
            }

            $this->instances[$abstract] = $instance;
            $this->resolved[$abstract] = true;
        }

        return $this->instances[$abstract];
    }
}
