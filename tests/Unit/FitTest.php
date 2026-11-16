<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Unit;

use IndianOpinion\MetaWriter\Field;
use IndianOpinion\MetaWriter\FitResult;
use IndianOpinion\MetaWriter\Tests\Support\TestCase;
use IndianOpinion\MetaWriter\Text\DetailCheck;
use IndianOpinion\MetaWriter\Text\Fitter;
use IndianOpinion\MetaWriter\Text\Length;
use IndianOpinion\MetaWriter\Text\ScriptCheck;
use IndianOpinion\MetaWriter\Validator;

final class FitTest extends TestCase
{
    /** T20, case 23 */
    public function testNormaliseRemovesQuotesLabelsBreaksAndTags(): void
    {
        $this->assertSame('Monsoon arrives early', Fitter::normalise('"Monsoon arrives early"'));
        $this->assertSame('Monsoon arrives early', Fitter::normalise("\u{201C}Monsoon arrives early\u{201D}"));
        $this->assertSame('Monsoon arrives early', Fitter::normalise('Title: Monsoon arrives early'));
        $this->assertSame('Rain in two districts', Fitter::normalise("Meta description: \u{00AB}Rain in\ntwo districts\u{00BB}"));
        $this->assertSame('Rain & relief for farmers', Fitter::normalise('<b>Rain</b> &amp; relief  for   farmers'));
        $this->assertSame('He said "yes" and left', Fitter::normalise('He said "yes" and left'));
        $this->assertSame('Tab separated', Fitter::normalise("Tab\tseparated\x07"));
    }

    /** T21, cases 24 and 73 */
    public function testLengthCountsGraphemesNeverBytes(): void
    {
        $this->assertSame(4, Length::measure('cafe'));
        $this->assertSame(4, Length::measure("cafe\u{0301}"), 'combining acute');
        $this->assertSame(1, Length::measure("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"), 'family emoji ZWJ sequence');
        $this->assertSame(1, Length::measure("\u{1F1EE}\u{1F1F3}"), 'flag');
        $hindi = 'नमस्ते';
        $this->assertSame(18, strlen($hindi));
        $this->assertSame(3, Length::measure($hindi));
        $arabic = 'مرحبا بالعالم';
        $this->assertSame(13, Length::measure($arabic));
        $this->assertLessThan(strlen($arabic), Length::measure($arabic));

        // RTL cut works on graphemes and adds no direction marks.
        $long = str_repeat('مرحبا بالعالم ', 20);
        $cut = Fitter::shorten($long, 10, 50, Field::DESCRIPTION);
        $this->assertLessThanOrEqual(50, Length::measure($cut));
        $this->assertDoesNotMatchRegularExpression('/[\x{200E}\x{200F}\x{202A}-\x{202E}]/u', $cut);
    }

    /** T22, case 25 */
    public function testTitleBudgetShrinksBySuffix(): void
    {
        $this->settings(['title_suffix' => ' | Example News']);
        $limits = $this->c->validator->limits(null);
        $this->assertSame(60 - 15, $limits['title_max']);
        $this->assertSame(155, $limits['description_max']);
        add_filter('meta_writer_title_suffix', static fn (): string => ' - X');
        $this->assertSame(56, $this->c->validator->limits(null)['title_max']);
    }

    /** T23, case 26 */
    public function testDeterministicShortening(): void
    {
        $title = 'Heavy monsoon rain floods low lying districts across the southern coast today';
        $cut = Fitter::fit($title, 30, 60, Field::TITLE);
        $this->assertTrue($cut->trimmed);
        $this->assertSame('Heavy monsoon rain floods low lying districts across the', $cut->text);
        $this->assertStringNotContainsString("\u{2026}", $cut->text);

        $sentences = 'The monsoon reached Kerala three days early. Farmers in several districts welcomed the heavy rain, and officials opened relief camps.';
        $d = Fitter::fit($sentences, 30, 80, Field::DESCRIPTION);
        $this->assertSame('The monsoon reached Kerala three days early.', $d->text);

        $one = 'Farmers in several districts welcomed the heavy rain, officials, and residents of the coast alike';
        $d = Fitter::fit($one, 30, 60, Field::DESCRIPTION);
        $this->assertLessThanOrEqual(60, $d->length);
        $this->assertStringEndsWith("\u{2026}", $d->text);
        $this->assertSame(1, substr_count($d->text, "\u{2026}"));
        $this->assertDoesNotMatchRegularExpression('/[,;:\-]\x{2026}$/u', $d->text);
        $this->assertSame("Farmers in several districts welcomed the heavy rain\u{2026}", $d->text, 'trailing comma removed before the ellipsis');

        $cjk = '東京で大雨が降り、交通機関に大きな影響が出ています。気象庁は警戒を呼びかけています。住民は注意してください';
        $c = Fitter::fit($cjk, 5, 20, Field::DESCRIPTION);
        $this->assertLessThanOrEqual(20, $c->length);
        $this->assertMatchesRegularExpression('/[、。]\x{2026}?$|\x{2026}$/u', $c->text);
        $this->assertTrue($c->ok);

        // Never cut inside a grapheme cluster.
        $emoji = str_repeat("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", 30);
        $e = Fitter::fit($emoji, 1, 10, Field::TITLE);
        $this->assertSame(10, Length::measure($e->text));
        $this->assertTrue(mb_check_encoding($e->text, 'UTF-8'));
    }

    public function testEmptyAndTooShort(): void
    {
        $this->assertSame(FitResult::EMPTY, Fitter::fit('  ""  ', 10, 60, Field::TITLE)->reason);
        $this->assertSame(FitResult::TOO_SHORT, Fitter::fit('Rain', 10, 60, Field::TITLE)->reason);
        $this->assertTrue(meta_writer_fit('A perfectly ordinary title', 10, 60, 'title')->ok);
        $this->assertSame(3, meta_writer_measure('नमस्ते'));
    }

    /** T53, case 68 */
    public function testWrongScriptFailsValidation(): void
    {
        $this->assertSame(ScriptCheck::DEVANAGARI, ScriptCheck::expectedScript('hi-IN'));
        $this->assertSame(ScriptCheck::LATIN, ScriptCheck::expectedScript('hi-Latn'));
        $this->assertSame(ScriptCheck::ARABIC, ScriptCheck::expectedScript('pa-Arab'));
        $this->assertSame(ScriptCheck::GURMUKHI, ScriptCheck::expectedScript('pa'));
        $this->assertSame(ScriptCheck::CJK, ScriptCheck::expectedScript('zh-Hant'));
        $this->assertNull(ScriptCheck::expectedScript('xx'));
        $v = new Validator($this->c->settings);
        $latin = $v->check(Field::TITLE, 'Monsoon reaches Kerala early this year', null, 'hi');
        $this->assertSame(FitResult::WRONG_SCRIPT, $latin->reason);
        $hindi = $v->check(Field::TITLE, 'मानसून इस साल केरल में जल्दी पहुंचा, किसान खुश', null, 'hi');
        $this->assertTrue($hindi->ok, (string) $hindi->reason);
        foreach (['bn' => 'বর্ষা এ বছর কেরালায় তাড়াতাড়ি পৌঁছেছে, কৃষকরা খুশি', 'ta' => 'பருவமழை இந்த ஆண்டு கேரளாவில் முன்கூட்டியே வந்தது', 'ru' => 'Муссон пришёл в Кералу раньше обычного в этом году'] as $lang => $text) {
            $this->assertTrue($v->check(Field::TITLE, $text, null, $lang)->ok, $lang);
            $this->assertSame(FitResult::WRONG_SCRIPT, $v->check(Field::TITLE, $text, null, 'en')->reason, $lang . ' as en');
        }
    }

    /** T54, case 69 */
    public function testDescriptionEqualToTitleFails(): void
    {
        $v = new Validator($this->c->settings);
        $long = 'Monsoon reaches Kerala three days early this year, say weather officials in Thiruvananthapuram';
        $post = $this->post(1, ['post_title' => $long]);
        $same = $v->check(Field::DESCRIPTION, $long, $post, 'en');
        $this->assertSame(FitResult::SAME_AS_TITLE, $same->reason);
        $generated = 'Early monsoon brings heavy rain to the whole of Kerala and its coast this week';
        $this->assertSame(FitResult::SAME_AS_TITLE, $v->check(Field::DESCRIPTION, $generated . '.', $post, 'en', $generated)->reason);
        $this->assertTrue($v->check(Field::DESCRIPTION, 'The monsoon reached Kerala three days early, bringing heavy rain to several districts and relief to farmers.', $post, 'en')->ok);
    }

    /** T55, case 70 */
    public function testUnsupportedDetailsAreFound(): void
    {
        $input = 'Call the district office on 0471 2345678 or see example.org for details.';
        $this->assertSame([], DetailCheck::unsupported('Details at example.org and on 0471 2345678.', $input));
        $this->assertSame(['https://other.example/path'], DetailCheck::unsupported('See https://other.example/path today.', $input));
        $this->assertSame(['+91 98765 43210'], DetailCheck::unsupported('Ring +91 98765 43210 now.', $input));
        $this->assertSame(['press@news.example'], DetailCheck::unsupported('Write to press@news.example.', $input));
        $this->assertSame([], DetailCheck::unsupported('Rain fell between 2019-2024 in 12 districts.', $input));
    }
}
