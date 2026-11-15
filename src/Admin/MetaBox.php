<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Admin;

use IndianOpinion\MetaWriter\Capabilities;
use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Exception\MetaWriterException;
use IndianOpinion\MetaWriter\Field;
use IndianOpinion\MetaWriter\Status;

/**
 * The post edit box: the opt-out checkbox, the latest suggestion, and
 * buttons to request or accept one. Works in the classic editor and as a
 * meta box in the block editor.
 */
final class MetaBox
{
    public function __construct(private readonly Container $c)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add'], 10, 2);
        add_action('save_post', [$this, 'saveOptOut'], 10, 2);
        add_action('admin_post_meta_writer_post', [$this, 'handle']);
    }

    public function add(string $postType, mixed $post = null): void
    {
        if (!in_array($postType, $this->c->generator->enabledTypes(), true)) {
            return;
        }
        add_meta_box('meta-writer', __('SEO title and description', 'meta-writer'), [$this, 'render'], $postType, 'side', 'low');
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('meta_writer_optout_' . $post->ID, 'meta_writer_optout_nonce');
        printf(
            '<p><label><input type="checkbox" name="meta_writer_skip" value="1" %s /> %s</label></p>',
            checked(get_post_meta($post->ID, '_meta_writer_skip', true), '1', false),
            esc_html__('Never suggest for this post', 'meta-writer'),
        );
        $user = get_current_user_id();
        $s = $this->c->suggestions->latestForPost($post->ID);
        if ($s !== null) {
            echo '<p><strong>' . esc_html(sprintf(__('Latest suggestion (#%1$d, %2$s)', 'meta-writer'), $s->id, $s->status)) . '</strong></p>';
            foreach (Field::ALL as $field) {
                if ($s->hasField($field) && $s->text($field) !== '') {
                    echo '<p><em>' . esc_html($field) . ':</em> ' . esc_html($s->text($field)) . '</p>';
                }
            }
            if ($s->flags !== []) {
                echo '<p><small>' . esc_html(implode(', ', $s->flags)) . '</small></p>';
            }
        }
        $url = admin_url('admin-post.php');
        if ($s !== null && $s->status === Status::PENDING && $this->c->caps->can($user, Capabilities::REVIEW, $post->ID)) {
            printf('<p><a class="button button-primary" href="%s">%s</a></p>', esc_url(wp_nonce_url(add_query_arg(['action' => 'meta_writer_post', 'op' => 'accept', 'id' => $s->id, 'post' => $post->ID], $url), 'meta_writer_post_' . $post->ID)), esc_html__('Accept', 'meta-writer'));
        }
        if ($this->c->caps->can($user, Capabilities::GENERATE, $post->ID)) {
            if ($this->c->kill->isOn()) {
                echo '<p><em>' . esc_html__('Generation is switched off.', 'meta-writer') . '</em></p>';
            } else {
                printf('<p><a class="button" href="%s">%s</a></p>', esc_url(wp_nonce_url(add_query_arg(['action' => 'meta_writer_post', 'op' => 'generate', 'post' => $post->ID], $url), 'meta_writer_post_' . $post->ID)), esc_html__('Suggest now', 'meta-writer'));
            }
        }
    }

    public function saveOptOut(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['meta_writer_optout_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['meta_writer_optout_nonce'])), 'meta_writer_optout_' . $postId)) {
            return;
        }
        if (wp_is_post_revision($postId) !== false || !current_user_can('edit_post', $postId)) {
            return;
        }
        if (!empty($_POST['meta_writer_skip'])) {
            update_post_meta($postId, '_meta_writer_skip', '1');
        } else {
            delete_post_meta($postId, '_meta_writer_skip');
        }
    }

    public function handle(): void
    {
        $postId = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        check_admin_referer('meta_writer_post_' . $postId);
        $op = isset($_GET['op']) ? sanitize_key((string) wp_unslash($_GET['op'])) : '';
        $user = get_current_user_id();
        $message = '';
        try {
            if ($op === 'accept') {
                $r = $this->c->reviewer->accept(isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0, [], [], $user);
                $message = 'written: ' . (implode(', ', $r->written) ?: 'nothing');
            } elseif ($op === 'generate') {
                if (!$this->c->caps->can($user, Capabilities::GENERATE, $postId)) {
                    wp_die(esc_html__('You may not generate suggestions for this post.', 'meta-writer'), 403);
                }
                $o = $this->c->generator->generate($postId, ['dry_run' => false, 'user_id' => $user, 'allow_queue' => false]);
                $message = $o->kind . ($o->reason !== '' ? ': ' . $o->reason : '');
            }
        } catch (MetaWriterException $e) {
            $message = $e->getMessage();
        }
        set_transient('meta_writer_post_msg_' . $user, $message, 120);
        wp_safe_redirect((string) get_edit_post_link($postId, 'url'));
        exit;
    }
}
