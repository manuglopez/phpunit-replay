<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Illuminate\Translation\Translator;
use Manuglopez\Replay\Record\Recorder;
use ReflectionClass;

/**
 * The application's translator, with `get()` recording every `<locale>:<key>` it is asked for
 * (`Record\Recorder::linkTranslation()`) before delegating to the original implementation.
 *
 * `__()`, `trans()`, `trans_choice()`, `Lang::get()`, `@lang`, `choice()`, `has()`,
 * `hasForLocale()` and the validator's messages all end in `Translator::get()`, so this is the
 * one method to override. It records the key for **every** locale the lookup may be served
 * from — `localeArray()` when falling back (the requested locale, then the fallback locale,
 * whatever `determineLocalesUsing()` adds), only the requested one otherwise — because a
 * change to the fallback's file changes the answer of a test that asked for the other locale.
 * What it returns is exactly what the original returns: it neither alters nor short-circuits a
 * lookup.
 *
 * Built by {@see self::wrapping()}, which copies the **whole** state of the original (loader,
 * locale, fallback, loaded lines, parsed keys, the `determineLocalesUsing` callback, the
 * missing-key callbacks…): the container then holds an equivalent translator that happens to
 * record. Only a translator that is exactly `Illuminate\Translation\Translator` is wrapped
 * ({@see TranslationTracker}), so no subclass override is ever bypassed.
 *
 * The package does not depend on `illuminate/*`: this class is only autoloaded by
 * `TranslationTracker`, after it has checked the parent exists.
 */
final class RecordingTranslator extends Translator
{
    private Recorder $recorder;

    /**
     * Not built with `new` (the original's state is copied, not rebuilt from its loader and
     * locale): use {@see self::wrapping()}. A translator that is exactly
     * `Illuminate\Translation\Translator` is the only thing it can wrap.
     *
     * A translator equivalent to `$original` that records into `$recorder`. */
    public static function wrapping(Translator $original, Recorder $recorder): self
    {
        $wrapper = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $wrapper->recorder = $recorder;

        for ($class = new ReflectionClass(Translator::class); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if (! $property->isStatic() && $property->getDeclaringClass()->getName() === $class->getName()) {
                    $property->setValue($wrapper, $property->getValue($original));
                }
            }
        }

        return $wrapper;
    }

    /**
     * @param string $key
     * @param array<string, mixed> $replace
     * @param string|null $locale
     * @param bool $fallback
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if (is_string($key)) {
            $requested = is_string($locale) && $locale !== '' ? $locale : $this->getLocale();

            foreach ($fallback ? $this->localeArray($requested) : [$requested] as $candidate) {
                if (is_string($candidate)) {
                    $this->recorder->linkTranslation($candidate, $key);
                }
            }
        }

        return parent::get($key, $replace, $locale, $fallback);
    }
}
