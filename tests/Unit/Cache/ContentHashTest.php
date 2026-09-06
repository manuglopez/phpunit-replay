<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\ContentHash;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ContentHashTest extends TestCase
{
    public function testOfReturnsNullWhenFileDoesNotExist(): void
    {
        self::assertNull(ContentHash::of('/path/that/does/not/exist.php'));
    }

    public function testOfHashesAnExistingFile(): void
    {
        $dir = TempDir::make('content-hash');
        $path = $dir . '/a.php';
        file_put_contents($path, "<?php echo 'hi';");

        try {
            $hash = ContentHash::of($path);
            self::assertIsString($hash);
            self::assertNotSame('', $hash);
        } finally {
            TempDir::remove($dir);
        }
    }

    public function testPhpHashIgnoresWhitespaceDifferences(): void
    {
        $a = ContentHash::ofContent("<?php \$foo   =   1;\n\necho   \$foo;", 'a.php');
        $b = ContentHash::ofContent('<?php $foo=1; echo $foo;', 'a.php');

        self::assertSame($a, $b);
    }

    public function testPhpHashIgnoresLineComments(): void
    {
        $a = ContentHash::ofContent("<?php\n// a comment\n\$foo = 1;", 'a.php');
        $b = ContentHash::ofContent("<?php\n\$foo = 1;", 'a.php');

        self::assertSame($a, $b);
    }

    public function testPhpHashIgnoresHashStyleComments(): void
    {
        $a = ContentHash::ofContent("<?php\n# hash comment\n\$foo = 1;", 'a.php');
        $b = ContentHash::ofContent("<?php\n\$foo = 1;", 'a.php');

        self::assertSame($a, $b);
    }

    public function testPhpHashIgnoresBlockComments(): void
    {
        $a = ContentHash::ofContent("<?php\n/* a multi\n line comment */\n\$foo = 1;", 'a.php');
        $b = ContentHash::ofContent("<?php\n\$foo = 1;", 'a.php');

        self::assertSame($a, $b);
    }

    public function testPhpHashIgnoresDocComments(): void
    {
        $a = ContentHash::ofContent("<?php\n/**\n * @return int\n */\nfunction foo() { return 1; }", 'a.php');
        $b = ContentHash::ofContent("<?php\nfunction foo() { return 1; }", 'a.php');

        self::assertSame($a, $b);
    }

    public function testPhpHashChangesWhenVariableIsRenamed(): void
    {
        $a = ContentHash::ofContent('<?php $foo = 1;', 'a.php');
        $b = ContentHash::ofContent('<?php $bar = 1;', 'a.php');

        self::assertNotSame($a, $b);
    }

    public function testPhpHashChangesWhenCodeChanges(): void
    {
        $a = ContentHash::ofContent('<?php $foo = 1;', 'a.php');
        $b = ContentHash::ofContent('<?php $foo = 2;', 'a.php');

        self::assertNotSame($a, $b);
    }

    public function testPhpHashFallsBackToRawHashForUnparseableCode(): void
    {
        $hash = ContentHash::ofContent('not valid php at all', 'a.php');

        self::assertIsString($hash);
        self::assertNotSame('', $hash);
    }

    public function testBladeHashStripsBladeComments(): void
    {
        $a = ContentHash::ofContent('<div>{{-- a comment --}}Hello</div>', 'a.blade.php');
        $b = ContentHash::ofContent('<div>Hello</div>', 'a.blade.php');

        self::assertSame($a, $b);
    }

    public function testBladeHashStripsMultilineBladeComments(): void
    {
        $a = ContentHash::ofContent("<div>\n{{--\n multi\n line\n--}}\nHello\n</div>", 'a.blade.php');
        $b = ContentHash::ofContent('<div> Hello </div>', 'a.blade.php');

        self::assertSame($a, $b);
    }

    public function testBladeHashDetectsContentChanges(): void
    {
        $a = ContentHash::ofContent('<div>Hello</div>', 'a.blade.php');
        $b = ContentHash::ofContent('<div>Goodbye</div>', 'a.blade.php');

        self::assertNotSame($a, $b);
    }

    public function testJsHashStripsLineComments(): void
    {
        $a = ContentHash::ofContent("// a comment\nconst foo = 1;", 'a.js');
        $b = ContentHash::ofContent('const foo = 1;', 'a.js');

        self::assertSame($a, $b);
    }

    public function testJsHashDoesNotStripInlineTrailingComments(): void
    {
        $a = ContentHash::ofContent('const foo = 1; // inline', 'a.js');
        $b = ContentHash::ofContent('const foo = 1;', 'a.js');

        self::assertNotSame($a, $b);
    }

    public function testJsHashDetectsCodeChanges(): void
    {
        $a = ContentHash::ofContent('const foo = 1;', 'a.js');
        $b = ContentHash::ofContent('const foo = 2;', 'a.js');

        self::assertNotSame($a, $b);
    }

    public function testUnknownExtensionHashesRawContent(): void
    {
        $a = ContentHash::ofContent('hello  world', 'a.txt');
        $b = ContentHash::ofContent('hello world', 'a.txt');

        self::assertNotSame($a, $b, 'whitespace must not be normalised for unknown extensions');
    }

    public function testUnknownExtensionSameContentHashesTheSame(): void
    {
        $a = ContentHash::ofContent('hello world', 'a.txt');
        $b = ContentHash::ofContent('hello world', 'a.txt');

        self::assertSame($a, $b);
    }

    public function testFileWithNoExtensionHashesRawContent(): void
    {
        $a = ContentHash::ofContent("all:\n\techo hi", 'Makefile');
        $b = ContentHash::ofContent("all:\n\techo hi", 'Makefile');

        self::assertSame($a, $b);
    }

    public function testOutputIsA32CharacterHexHash(): void
    {
        $hash = ContentHash::ofContent('<?php $foo = 1;', 'a.php');

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash);
    }
}
