<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Unit;

use IndianOpinion\MetaWriter\Input;
use IndianOpinion\MetaWriter\Reason;
use IndianOpinion\MetaWriter\Tests\Support\TestCase;
use IndianOpinion\MetaWriter\Text\Cleaner;

final class InputTest extends TestCase
{
    /** T9, case 10 */
    public function testCleanerDecodesAndStrips(): void
    {
        $html = "<!-- wp:paragraph --><p>Tom &amp; Jerry&#8217;s   show</p><!-- /wp:paragraph -->\n"
            . '<figure><img src="x.jpg" /><figcaption>Photo: agency</figcaption></figure>'
            . '[caption id="a"]<img /> A caption[/caption]'
            . '<script>alert(1)</script><style>p{}</style>'
            . '[gallery ids="1,2"]<p>Second&nbsp;para</p>[button url="x"]Click here[/button]';
        $this->assertSame("Tom & Jerry\u{2019}s show Second para Click here", Cleaner::clean($html));
    }

    /** T10, case 11 */
    public function testShortcodeIsNeverExecuted(): void
    {
        $ran = false;
        // A shortcode handler with a side effect, as WordPress would register it.
        $GLOBALS['shortcode_tags'] = ['side_effect' => static function () use (&$ran): string {
            $ran = true;

            return 'rendered';
        }];
        $this->post(1, ['post_content' => self::body() . '[side_effect]inner[/side_effect]']);
        $input = Input::fromPost(get_post(1), $this->c->settings);
        $this->assertFalse($ran);
        $this->assertStringNotContainsString('rendered', $input->body);
        $this->assertStringNotContainsString('[side_effect', $input->body);
        unset($GLOBALS['shortcode_tags']);

        // An operator cleaner replaces ours.
        add_filter('meta_writer_clean_input', static fn (string $t): string => 'custom ' . $t, 10, 2);
        $this->assertStringStartsWith('custom ', Input::fromPost(get_post(1), $this->c->settings)->body);
    }

    /** T11, case 12 */
    public function testBodyOverBudgetIsCutAtABoundaryAndStaysValidUtf8(): void
    {
        $text = str_repeat('नमस्ते दुनिया यह एक परीक्षण वाक्य है। ', 400);
        $cut = Cleaner::budget($text, 1000);
        $this->assertLessThanOrEqual(1000, mb_strlen($cut));
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
        $this->assertStringEndsWith('।', $cut);

        $words = str_repeat('alpha beta gamma ', 100);
        $cut = Cleaner::budget($words, 50);
        $this->assertLessThanOrEqual(50, mb_strlen($cut));
        $this->assertMatchesRegularExpression('/(alpha|beta|gamma)$/', $cut);
    }

    /** T12, cases 13 and 72 */
    public function testThinAndImageOnlyPostsAreSkippedWithoutACall(): void
    {
        $this->post(1, ['post_content' => '<p>Short note with only a few words.</p>']);
        $this->post(2, ['post_content' => '<figure><img src="a.jpg" /><figcaption>A long caption that describes the photo in some detail for readers</figcaption></figure>']);
        $this->assertSame(Reason::TOO_LITTLE_CONTENT, $this->c->generator->generate(1, ['dry_run' => false])->reason);
        $this->assertSame(Reason::TOO_LITTLE_CONTENT, $this->c->generator->generate(2, ['dry_run' => false])->reason);
        $this->assertCount(0, $this->caller->calls);

        // Scripts without spaces count characters divided by two.
        $this->assertSame(30, Cleaner::wordCount(str_repeat('日本語', 20)));
    }

    /** T13, case 14 */
    public function testLanguageFilterReachesThePrompt(): void
    {
        $this->post(1);
        add_filter('meta_writer_post_language', static fn (string $l, \WP_Post $p): string => 'ta-IN', 10, 2);
        add_filter('meta_writer_language_scripts', static fn (array $t): array => ['ta' => 'Latin'] + $t);
        $this->suggestFor(1);
        $this->assertStringContainsString('ta-IN', $this->caller->calls[0][1]['content']);
    }

    public function testLanguageDefaultsToTheSiteLocale(): void
    {
        update_option('WPLANG', 'hi_IN');
        $this->assertSame('hi-IN', Input::fromPost($this->post(2), $this->c->settings)->language);
    }

    /** T14, case 15 */
    public function testPromptCarriesNoPersonalDataOrCustomFields(): void
    {
        $this->post(1, ['post_author' => 7]);
        update_post_meta(1, 'secret_field', 'CUSTOM-FIELD-VALUE');
        update_post_meta(1, '_private_meta', 'PRIVATE-META-VALUE');
        $this->suggestFor(1);
        $payload = (string) json_encode($this->caller->calls[0]);
        foreach (['CUSTOM-FIELD-VALUE', 'PRIVATE-META-VALUE', '@', 'author'] as $needle) {
            $this->assertStringNotContainsString($needle, $payload);
        }
    }
}
