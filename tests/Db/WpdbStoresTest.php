<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Db;

use IndianOpinion\MetaWriter\Status;
use IndianOpinion\MetaWriter\Store\PostSource;
use IndianOpinion\MetaWriter\Store\WpdbCounterStore;
use IndianOpinion\MetaWriter\Store\WpdbPostSource;
use IndianOpinion\MetaWriter\Store\WpdbSuggestionStore;

final class WpdbStoresTest extends DbTestCase
{
    /** T39 against SQL: two connections race for the last calls of a cap of 3. */
    public function testCounterIsAtomicAcrossTwoConnections(): void
    {
        $a = new WpdbCounterStore($this->db);
        $second = self::connect();
        $this->assertNotNull($second);
        $b = new WpdbCounterStore(new \wpdb($second, 'wp_'));
        $taken = 0;
        for ($i = 0; $i < 5; $i++) {
            $taken += $a->increment('2026-11-14', 3) ? 1 : 0;
            $taken += $b->increment('2026-11-14', 3) ? 1 : 0;
        }
        $this->assertSame(3, $taken);
        $this->assertSame(3, $a->get('2026-11-14'));
        $this->assertSame(0, $a->get('2026-11-15'));
        $this->assertFalse($a->increment('2026-11-15', 0), 'cap 0 takes nothing');
        $this->assertSame('off', $this->db->get_var("SELECT autoload FROM wp_options WHERE option_name = 'meta_writer_calls_2026-11-14'"));
    }

    /** T60: the counter is a database row; nothing in a cache can reset it. */
    public function testCounterSurvivesACacheFlushAndOldDaysArePurged(): void
    {
        $s = new WpdbCounterStore($this->db);
        $s->increment('2026-11-01', 10);
        $s->increment('2026-11-14', 10);
        $fresh = new WpdbCounterStore($this->db);
        $this->assertSame(1, $fresh->get('2026-11-14'));
        $this->assertSame(1, $fresh->purgeBefore('2026-11-07'));
        $this->assertSame(0, $fresh->get('2026-11-01'));
        $this->assertSame(1, $fresh->get('2026-11-14'));
        $this->db->query("INSERT INTO wp_options (option_name, option_value) VALUES ('meta_writer_kill', '1')");
        $this->assertSame('1', $fresh->freshOption('meta_writer_kill'));
        $this->assertNull($fresh->freshOption('missing_option'));
    }

    /** T8 against SQL: keyset paging, no gaps, no repeats; password posts excluded. */
    public function testPostSourcePagesByCursor(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db->query(sprintf("INSERT INTO wp_posts (post_title, post_date, post_status, post_password, post_type) VALUES ('p%d', '2026-01-%02d 10:00:00', '%s', '%s', '%s')", $i, ($i % 5) + 1, $i === 7 ? 'draft' : 'publish', $i === 8 ? 'pw' : '', $i === 9 ? 'page' : 'post'));
        }
        $src = new WpdbPostSource($this->db);
        $seen = [];
        $cursor = null;
        while (($page = $src->next(['post'], ['publish'], $cursor, 10, PostSource::ORDER_OLDEST)) !== []) {
            foreach ($page as $row) {
                $seen[] = $row['id'];
                $cursor = $row['cursor'];
            }
        }
        $this->assertSame(array_values(array_diff(range(1, 25), [7, 8, 9])), $seen);

        $newest = [];
        $cursor = null;
        while (($page = $src->next(['post', 'page'], ['publish'], $cursor, 4, PostSource::ORDER_NEWEST)) !== []) {
            foreach ($page as $row) {
                $newest[] = $row['id'];
                $cursor = $row['cursor'];
            }
        }
        $this->assertCount(23, $newest);
        $this->assertSame($newest, array_values(array_unique($newest)));
    }

    /** T30, T52, T25, T51 against SQL. */
    public function testSuggestionTableRoundTripCompareAndSetAndSchemaCheck(): void
    {
        $store = new WpdbSuggestionStore($this->db);
        $store->install();
        $this->assertSame([], $store->missingColumns());
        $this->db->query("INSERT INTO wp_posts (ID, post_title) VALUES (5, 'x'), (6, 'y')");

        $id = $store->insert(['post_id' => 5, 'fields' => 'title,description', 'title' => 'Tïtle नमस्ते', 'description' => 'Desc', 'title_len' => 12, 'description_len' => 4, 'flags' => 'trimmed,duplicate', 'status' => Status::PENDING, 'input_hash' => str_repeat('a', 64), 'route' => 'r', 'prompt_version' => 'v', 'description_hash' => str_repeat('b', 64), 'created_at' => 1_780_000_000, 'created_by' => 2]);
        $s = $store->find($id);
        $this->assertNotNull($s);
        $this->assertSame('Tïtle नमस्ते', $s->title);
        $this->assertSame(['title', 'description'], $s->fields);
        $this->assertSame(['trimmed', 'duplicate'], $s->flags);
        $this->assertSame(1_780_000_000, $s->createdAt);
        $this->assertSame(1, $store->query(['flag' => 'duplicate'])['total']);

        $this->assertTrue($store->descriptionHashExists(str_repeat('b', 64), 6));
        $this->assertFalse($store->descriptionHashExists(str_repeat('b', 64), 5));

        $this->assertTrue($store->transition($id, [Status::PENDING], Status::ACCEPTED, ['decided_by' => 3, 'decided_at' => 1_780_000_100]));
        $this->assertFalse($store->transition($id, [Status::PENDING], Status::ACCEPTED), 'second accept loses');
        $this->assertSame(3, $store->find($id)?->decidedBy);

        $this->db->query("UPDATE wp_posts SET post_status = 'trash' WHERE ID = 5");
        $this->assertSame(0, $store->query([])['total']);
        $this->assertSame(1, $store->query(['include_trashed' => true])['total']);
        $this->assertSame([], $store->countByStatus());

        $failed = $store->insert(['post_id' => 6, 'status' => Status::FAILED, 'title' => '', 'description' => '', 'raw' => 'raw text', 'created_at' => 1_000]);
        $this->assertSame(1, $store->clearRaw(2_000));
        $this->assertNull($store->find($failed)?->raw);
        $this->assertSame(1, $store->purge(2_000, [Status::FAILED]));
        $this->assertSame(1, $store->deleteForPost(5));

        $this->db->query('ALTER TABLE wp_meta_writer_suggestions DROP COLUMN raw');
        $this->assertSame(['raw'], $store->missingColumns());
        $store->drop();
        $this->assertNull($this->db->get_var("SHOW TABLES LIKE 'wp_meta_writer_suggestions'"));
    }
}
