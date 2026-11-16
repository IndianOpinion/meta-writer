<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Unit;

use IndianOpinion\MetaWriter\Adapter\AdapterRegistry;
use IndianOpinion\MetaWriter\Adapter\MetaKeyAdapter;
use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Exception\TargetUnavailableException;
use IndianOpinion\MetaWriter\Frontend\FallbackOutput;
use IndianOpinion\MetaWriter\KillSwitch;
use IndianOpinion\MetaWriter\Reason;
use IndianOpinion\MetaWriter\Settings;
use IndianOpinion\MetaWriter\Tests\Support\TestCase;
use IndianOpinion\MetaWriter\Uninstaller;

final class AdapterTest extends TestCase
{
    /** @param array<string, bool> $active */
    private function registry(array $active): AdapterRegistry
    {
        $shipped = AdapterRegistry::shipped();
        $out = [];
        foreach ($shipped as $id => $a) {
            $on = $active[$id] ?? ($id === 'fallback');
            $out[$id] = new MetaKeyAdapter($id, $a->label(), $a->key('title'), $a->key('description'), static fn (): bool => $on, $a->isExperimental());
        }

        return new AdapterRegistry($out);
    }

    /** T46, case 59 */
    public function testAutoPicksOneBlocksOnTwoAndFallsBackOnNone(): void
    {
        $this->assertSame('fallback', $this->registry([])->resolve('auto')->id());
        $this->assertSame('rankmath', $this->registry(['rankmath' => true])->resolve('auto')->id());
        try {
            $this->registry(['yoast' => true, 'seopress' => true])->resolve('auto');
            $this->fail('expected ambiguous');
        } catch (TargetUnavailableException $e) {
            $this->assertSame('ambiguous', $e->reason());
        }
        $this->assertSame('yoast', $this->registry(['yoast' => true, 'seopress' => true])->resolve('yoast')->id());

        $this->c = new Container($this->store, $this->counters, $this->source, $this->clock, $this->registry(['yoast' => true, 'rankmath' => true]));
        Container::set($this->c);
        $this->post(1);
        $this->assertSame(Reason::TARGET_UNAVAILABLE, $this->c->generator->generate(1, ['dry_run' => false])->reason);
    }

    /** T48, case 61, ruling 1 */
    public function testEachAdapterWritesItsDocumentedKeysThroughTheMetaApi(): void
    {
        $expected = [
            'yoast' => ['_yoast_wpseo_title', '_yoast_wpseo_metadesc'],
            'rankmath' => ['rank_math_title', 'rank_math_description'],
            'seopress' => ['_seopress_titles_title', '_seopress_titles_desc'],
            'aioseo' => ['_aioseo_title', '_aioseo_description'],
            'fallback' => ['_meta_writer_title', '_meta_writer_description'],
        ];
        $this->post(1);
        foreach (AdapterRegistry::shipped() as $id => $adapter) {
            \WpState::$metaWrites = [];
            $adapter->write(1, 'title', 'T "quoted" \\ back');
            $adapter->write(1, 'description', 'D');
            $this->assertSame(['1:' . $expected[$id][0], '1:' . $expected[$id][1]], \WpState::$metaWrites, $id);
            $this->assertSame('T "quoted" \\ back', $adapter->read(1, 'title'), $id . ' round trip survives unslashing');
            $adapter->clear(1, 'title');
            $this->assertSame('', $adapter->read(1, 'title'));
        }
        $this->assertTrue(AdapterRegistry::shipped()['aioseo']->isExperimental());
        $this->assertStringContainsString('experimental', AdapterRegistry::shipped()['aioseo']->label());
        foreach (['yoast', 'rankmath', 'seopress', 'fallback'] as $id) {
            $this->assertFalse(AdapterRegistry::shipped()[$id]->isExperimental());
        }
    }

    /** T49, case 62 */
    public function testWhitespaceOnlyTargetIsEmpty(): void
    {
        $this->post(1);
        update_post_meta(1, '_meta_writer_title', "  \t ");
        update_post_meta(1, '_meta_writer_description', ' ');
        $s = $this->suggestFor(1);
        $this->assertSame(['title', 'description'], $s->fields);
    }

    /** T50, case 63 */
    public function testFallbackOutputAndMigration(): void
    {
        $this->post(1);
        update_post_meta(1, '_meta_writer_title', 'Fallback <title> & more');
        update_post_meta(1, '_meta_writer_description', 'A "quoted" description <script>');
        $out = new FallbackOutput($this->c);

        \WpState::$singular = false;
        ob_start();
        $out->printDescription();
        $this->assertSame('', ob_get_clean());
        $this->assertSame('orig', $out->filterTitle('orig'));

        \WpState::$singular = true;
        \WpState::$queried = 1;
        ob_start();
        $out->printDescription();
        $html = (string) ob_get_clean();
        $this->assertSame(1, substr_count($html, '<meta name="description"'));
        $this->assertStringContainsString('&quot;quoted&quot; description &lt;script&gt;', $html);
        $this->assertSame('Fallback &lt;title&gt; &amp; more', $out->filterTitle('orig'));

        \WpState::$queried = 2;
        ob_start();
        $out->printDescription();
        $this->assertSame('', ob_get_clean(), 'nothing when the value is empty');

        // An SEO plugin appears: output switches off.
        $this->c = new Container($this->store, $this->counters, $this->source, $this->clock, $this->registry(['yoast' => true]));
        $out = new FallbackOutput($this->c);
        \WpState::$queried = 1;
        ob_start();
        $out->printDescription();
        $this->assertSame('', ob_get_clean());

        // migrate copies only into empty fields.
        $this->post(3);
        update_post_meta(3, '_meta_writer_title', 'Fallback title three');
        update_post_meta(3, '_yoast_wpseo_title', 'Manual Yoast title');
        $yoast = $this->c->adapters->get('yoast');
        $plan = $this->c->reviewer->migrate($this->c->adapters->get('fallback'), $yoast, [1, 3], false, self::ADMIN);
        $this->assertSame('', $yoast->read(1, 'title'), 'dry by default');
        $this->c->reviewer->migrate($this->c->adapters->get('fallback'), $yoast, [1, 3], true, self::ADMIN);
        $this->assertSame('Fallback <title> & more', $yoast->read(1, 'title'));
        $this->assertSame('Manual Yoast title', $yoast->read(3, 'title'));
        $this->assertContains(['post_id' => 3, 'field' => 'title', 'action' => 'kept_manual'], $plan);
    }

    /** T59, case 76 */
    public function testMultisiteIsPerSiteAndTheNetworkSwitchBlocksAll(): void
    {
        \WpState::$multisite = true;
        $this->settings(['daily_cap' => 1]);
        $this->post(1);
        $this->suggestFor(1);
        $this->assertSame(0, $this->c->cap->remaining());

        switch_to_blog(2);
        update_option(Settings::OPTION, ['model' => 'm', 'api_key' => 'key-0123456789', 'daily_cap' => 1]);
        $this->rebuild();
        $this->post(1);
        $this->assertSame(1, $this->c->cap->remaining(), 'cap is per site');
        $this->c->kill->set(true, 't');
        $this->assertTrue($this->c->kill->isOn());
        restore_current_blog();
        $this->rebuild();
        $this->assertFalse($this->c->kill->isOn(), 'kill switch is per site');

        update_site_option(KillSwitch::NETWORK_OPTION, '1');
        $this->assertTrue($this->c->kill->isOn());
        $this->assertSame('network', $this->c->kill->source());
        switch_to_blog(2);
        $this->rebuild();
        $this->assertSame(Reason::DISABLED, $this->c->generator->generate(1, ['dry_run' => false])->reason);
        restore_current_blog();
    }

    /** T62, section 6.5 */
    public function testUninstallRemovesOwnDataAndLeavesSeoKeys(): void
    {
        $this->post(1);
        update_post_meta(1, '_yoast_wpseo_metadesc', 'Written by meta-writer into Yoast');
        update_post_meta(1, '_meta_writer_written', '{}');
        update_post_meta(1, '_meta_writer_skip', '1');
        update_post_meta(1, '_meta_writer_description', 'Fallback live value');
        update_option('meta_writer_log', [['x']]);
        update_option('meta_writer_kill', '0');
        Uninstaller::site();
        $this->assertSame('Written by meta-writer into Yoast', $this->meta(1, '_yoast_wpseo_metadesc'));
        $this->assertSame('', $this->meta(1, '_meta_writer_written'));
        $this->assertSame('', $this->meta(1, '_meta_writer_skip'));
        $this->assertSame('Fallback live value', $this->meta(1, '_meta_writer_description'), 'kept unless ticked');
        $this->assertFalse(get_option('meta_writer_log'));
        $this->assertFalse(get_option(Settings::OPTION));

        update_option(Settings::OPTION, ['remove_fallback_on_uninstall' => true]);
        Uninstaller::site();
        $this->assertSame('', $this->meta(1, '_meta_writer_description'));
    }
}
