<?php

declare(strict_types=1);

/**
 * A minimal stand-in for Illuminate\Translation\Translator, so the unit tests can exercise
 * `Laravel\RecordingTranslator` and `Laravel\TranslationTracker` without the package ever
 * depending on illuminate/*. It mirrors what the tracker relies on: the property set that must
 * be copied, `get()`'s signature, and the protected `localeArray()` (which honours
 * `determineLocalesUsing`). The real class is exercised end to end by
 * `tests/Integration/LaravelLiteTranslationsTest.php`, against the fixture's own vendor.
 * Loaded via require_once — never autoloaded.
 */

namespace Illuminate\Translation;

/**
 * Laravel's `Translator` extends `NamespacedItemResolver`, which holds the parsed-key cache.
 * The private property is an addition of this stand-in: state that only a walk up the class
 * hierarchy can copy (a Laravel upgrade could add one).
 */
class NamespacedItemResolver
{
    /** @var array<string, mixed> */
    protected $parsed = [];

    private string $resolverMemo = '';

    public function remember(string $memo): void
    {
        $this->resolverMemo = $memo;
    }

    public function remembered(): string
    {
        return $this->resolverMemo;
    }
}

class Translator extends NamespacedItemResolver
{
    /** @var array<string, array<string, string>> */
    protected $loaded = [];

    /** @var string */
    protected $locale;

    /** @var string */
    protected $fallback = '';

    /** @var (callable(array<int, string>): array<int, string>)|null */
    protected $determineLocalesUsing;

    /** @var object */
    protected $loader;

    /** @var list<string> every `get()` that reached the parent implementation */
    public array $served = [];

    /** @param string $locale */
    public function __construct($loader, $locale)
    {
        $this->loader = $loader;
        $this->locale = $locale;
    }

    /**
     * @param string $key
     * @param array<string, string> $replace
     * @param string|null $locale
     * @param bool $fallback
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $locale = $locale ?: $this->locale;
        $this->served[] = (is_string($key) ? $key : 'non-string') . '@' . $locale;

        if (! is_string($key)) {
            return $key;
        }

        foreach ($fallback ? $this->localeArray($locale) : [$locale] as $candidate) {
            if (isset($this->loaded[$candidate][$key])) {
                return $this->loaded[$candidate][$key];
            }
        }

        return $key;
    }

    /** @return string */
    public function getLocale()
    {
        return $this->locale;
    }

    /** @return string */
    public function getFallback()
    {
        return $this->fallback;
    }

    /** @param string $locale */
    public function setFallback($locale)
    {
        $this->fallback = $locale;
    }

    /** @param array<string, array<string, string>> $loaded */
    public function preload(array $loaded): void
    {
        $this->loaded = $loaded;
    }

    /** @param callable(array<int, string>): array<int, string> $callback */
    public function determineLocalesUsing($callback)
    {
        $this->determineLocalesUsing = $callback;
    }

    /**
     * @param string|null $locale
     * @return array<int, string>
     */
    protected function localeArray($locale)
    {
        $locales = array_filter([$locale ?: $this->locale, $this->fallback]);

        $determined = call_user_func($this->determineLocalesUsing ?: fn ($locales) => $locales, $locales);

        return array_values(array_unique($determined));
    }
}
