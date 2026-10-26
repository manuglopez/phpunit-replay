<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Closure;
use Illuminate\Translation\Translator;
use Manuglopez\Replay\Record\Recorder;
use Throwable;

/**
 * Records which translation keys each test resolves (`translations => 'per-key'`): the second
 * half of what lets `Laravel\Rules\TranslationRule` narrow a change in `lang/**` to the tests
 * that read the changed keys. Same shape as {@see TableTracker} and {@see BladeTracker}:
 * `arm()` takes the already-resolved Illuminate application container (`$app`), duck-typed.
 *
 * `arm()` does not resolve the translator. It asks the container to `extend('translator', …)`,
 * so that when the application first builds it, {@see RecordingTranslator} takes its place
 * (the container keeps one shared instance, so every `__()`, `trans()`, `Lang::get()`, view and
 * validator then reaches it). A translator that is not exactly
 * `Illuminate\Translation\Translator` is left alone — a subclass may override what `get()`
 * does, a mock has no keys to see.
 *
 * ## What cannot be traced: the `*` entry
 *
 * Whatever this class cannot see for the open test is recorded as `*`
 * ({@see Recorder::markTranslationsUntrackable()}), and the selection falls back to the broad
 * rule for that one test file:
 *
 *  - (a) the translator was already resolved when arming, and is not ours: whoever resolved it
 *    may have kept it (a provider's `register()`, a service holding it);
 *  - (b) the container binds something that is not exactly `Illuminate\Translation\Translator`
 *    (a subclass, a custom translator, a mock);
 *  - (c) when the test is over, `translator` is no longer our {@see RecordingTranslator}
 *    ({@see self::verify()}): `Lang::swap()`, `Lang::shouldReceive()`,
 *    `$this->app->instance('translator', …)`.
 *
 * Known limits, **not** detected (documented, like the ones of the table and blade trackers):
 * a key resolved in a provider's `register()` before this armed; a translated value one test
 * cached in a static that a later test reads; lines read straight from the loader
 * (`app('translation.loader')`) or from `lang/*.php` with `require`; the missing-key handlers.
 */
final class TranslationTracker
{
    private const TRANSLATOR = 'translator';

    /**
     * Arms `$app` so that the translator it builds records into `$recorder`. A no-op when the
     * package of the translator is not installed (nothing to record).
     */
    public static function arm(object $app, Recorder $recorder): void
    {
        if (! class_exists(Translator::class)) {
            return;
        }

        if (! method_exists($app, 'extend') || ! method_exists($app, 'resolved') || ! method_exists($app, 'make')) {
            $recorder->markTranslationsUntrackable();

            return;
        }

        if ($app->resolved(self::TRANSLATOR)) {
            // Already built: ours when another tracker armed this application first (the early
            // and late arming of `ArmLaravelTrackersOnPrepared` share a marker, so this is not
            // expected), someone else's otherwise — case (a).
            if (! self::isRecording($app)) {
                $recorder->markTranslationsUntrackable();
            }

            return;
        }

        $app->extend(self::TRANSLATOR, self::wrap($recorder));
    }

    /**
     * At the end of the test, before the application is torn down: the `translator` the
     * container holds must still be the {@see RecordingTranslator} armed for it, or the test
     * resolved keys through a replacement nobody could see — case (c). A translator the test
     * never resolved is fine: it resolved no key.
     */
    public static function verify(object $app, Recorder $recorder): void
    {
        if (! class_exists(Translator::class)) {
            return;
        }

        if (! method_exists($app, 'resolved') || ! method_exists($app, 'make')) {
            $recorder->markTranslationsUntrackable();

            return;
        }

        if ($app->resolved(self::TRANSLATOR) && ! self::isRecording($app)) {
            $recorder->markTranslationsUntrackable();
        }
    }

    /** @return Closure(object, object): object what `extend('translator', …)` runs */
    private static function wrap(Recorder $recorder): Closure
    {
        return static function (object $translator) use ($recorder): object {
            if ($translator instanceof RecordingTranslator) {
                return $translator;
            }

            // Exactly Illuminate's translator, and nothing else (case (b)).
            if (! $translator instanceof Translator || $translator::class !== Translator::class) {
                $recorder->markTranslationsUntrackable();

                return $translator;
            }

            return RecordingTranslator::wrapping($translator, $recorder);
        };
    }

    /** @param object $app a container that has `make()` */
    private static function isRecording(object $app): bool
    {
        try {
            /** @phpstan-ignore method.notFound */
            return $app->make(self::TRANSLATOR) instanceof RecordingTranslator;
        } catch (Throwable) {
            return false;
        }
    }
}
