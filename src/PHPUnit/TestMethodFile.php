<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use PHPUnit\Event\Code\TestMethod;
use ReflectionClass;
use Throwable;

/**
 * The file a `TestMethod` counts against for every cache/graph purpose this package has:
 * dependency edges (`Record\Recorder::beginTest()`), the in-process replay decision
 * (`ReplayState::decide()`), result attribution (`Record\ResultCollector::testPrepared()`),
 * a class-level `#[NotCacheable]` marker, and the Laravel database-table widening list all
 * key themselves by "the file a future pass selects and runs" — never necessarily the file
 * `TestMethod::file()` itself returns.
 *
 * `TestMethod::file()` resolves through PHPUnit's own
 * `Event\Code\TestMethodBuilder::fromTestCase()` -> `Util\Reflection::sourceLocationFor()` ->
 * `(new ReflectionMethod($className, $methodName))->getFileName()` — native PHP reflection,
 * which for an inherited method returns the file of the class that DECLARES the method body,
 * not the class actually running it. A concrete, `final` test class whose methods are all
 * inherited from an abstract base therefore has every one of its tests attributed to the
 * base's file: one PHPUnit never runs a test against directly (either it is abstract, so
 * PHPUnit's own suite loader skips it after a warning — `Framework\TestSuite::addTestFile()`
 * catching `Runner\TestSuiteLoader`'s `ClassIsAbstractException` — or, renamed away from the
 * configured test suffix so it stops matching at all, it is not "a test file" this package's
 * own `Select\TestPaths::isTestFile()` recognises in the first place). Either way the
 * concrete subclass — the file that actually needs to be re-run when its dependencies change
 * — never accumulates a single edge.
 *
 * `TestMethod::className()` is always `$testCase::class`: the concrete, actually-running
 * class, regardless of which class declares the method body. Reflecting THAT and taking its
 * own file is a strict improvement over `file()` for the one case that mattered (an inherited
 * method) and identical to it for the overwhelmingly common one (an ordinary test class
 * declares its own methods, so its declaring file and its running file are the same file).
 * Neither a `#[DataProvider]` dataset nor a `#[Depends]`/`#[DependsExternal]` relationship
 * changes which class is running: `TestMethodBuilder::dataFor()` only attaches constructor-
 * style test data to the SAME `$testCase`, and a dependency is resolved by class+method id
 * (`ReplayState::isDependsProvider()`), never by file — so both fall out of this unaffected,
 * and so does a repeated/retried attempt (`#[Repeat]`/`#[Retry]` only change `TestMethod::id()`,
 * never `className()`).
 *
 * Falls back to `$test->file()` — the pre-fix behaviour — only when the running class itself
 * cannot be reflected, or reflection resolves no file for it. In ordinary operation this does
 * not happen: a `TestMethod` is only ever built from an already-instantiated test object
 * (`TestMethodBuilder::fromTestCase()`), so its class necessarily exists and is loaded from a
 * real file. The fallback exists for whatever is bizarre enough that reflection cannot
 * resolve it (an anonymous or otherwise dynamically-synthesised test class) rather than
 * turning that into a hard failure — `Record\Recorder::beginTest()` already tolerates an
 * "eval()'d code" pseudo-path or an empty one by simply not recording anything for it, so a
 * caller here is never worse off than the pre-fix code was.
 */
final class TestMethodFile
{
    public static function of(TestMethod $test): string
    {
        try {
            $file = (new ReflectionClass($test->className()))->getFileName();
        } catch (Throwable) {
            return $test->file();
        }

        return $file !== false && $file !== '' ? $file : $test->file();
    }
}
