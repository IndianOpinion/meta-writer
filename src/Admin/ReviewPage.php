<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Admin;

use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Exception\MetaWriterException;
use IndianOpinion\MetaWriter\Exception\TargetUnavailableException;
use IndianOpinion\MetaWriter\Field;
use IndianOpinion\MetaWriter\Status;
use IndianOpinion\MetaWriter\Text\Length;

/**
 * The review screen (case 36): Tools > SEO suggestions.
 */
final class ReviewPage
{
    public const SLUG = 'meta-writer-review';
    public const PER_PAGE = 20;

    public function __construct(private readonly Container $c)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_meta_writer_review', [$this, 'handle']);
    }

    public function menu(): void
    {
        add_management_page(__('SEO suggestions', 'meta-writer'), __('SEO suggestions', 'meta-writer'), $this->c->caps->capability('review'), self::SLUG, [$this, 'render']);
    }

    public function handle(): void
    {
        if (!current_user_can($this->c->caps->capability('review'))) {
            wp_die(esc_html__('You may not review suggestions.', 'meta-writer'), 403);
        }
        check_admin_referer('meta_writer_review');
        $user = get_current_user_id();
        $single = isset($_POST['single']) ? absint(wp_unslash($_POST['single'])) : 0;
        // A row's own Accept button always accepts, whatever the bulk select says.
        $op = $single > 0 ? 'accept' : (isset($_POST['op']) ? sanitize_key((string) wp_unslash($_POST['op'])) : '');
        $ids = $single > 0 ? [$single] : (isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', wp_unslash($_POST['ids'])) : []);
        $edits = isset($_POST['edit']) && is_array($_POST['edit']) ? wp_unslash($_POST['edit']) : [];
        $confirm = isset($_POST['confirm_stale']) && is_array($_POST['confirm_stale']) ? array_map('absint', array_keys(wp_unslash($_POST['confirm_stale']))) : [];
        $messages = [];
        foreach (array_unique(array_filter($ids)) as $id) {
            try {
                if ($op === 'reject') {
                    $this->c->reviewer->reject($id, $user);
                    $messages[] = sprintf('#%d rejected.', $id);
                    continue;
                }
                $e = [];
                if (isset($edits[$id]) && is_array($edits[$id])) {
                    foreach (Field::ALL as $field) {
                        if (isset($edits[$id][$field]) && is_string($edits[$id][$field])) {
                            $e[$field] = $edits[$id][$field];
                        }
                    }
                }
                $r = $this->c->reviewer->accept($id, $e, ['confirm_stale' => in_array($id, $confirm, true) && $single > 0, 'bulk' => $single === 0], $user);
                $messages[] = sprintf('#%d: written %s%s.', $id, implode(', ', $r->written) ?: 'nothing', $r->superseded !== [] ? '; superseded ' . implode(', ', $r->superseded) : '');
            } catch (MetaWriterException $ex) {
                $messages[] = sprintf('#%d: %s', $id, $ex->getMessage());
            }
        }
        set_transient('meta_writer_review_msg_' . $user, $messages, 300);
        $status = isset($_POST['status']) ? sanitize_key((string) wp_unslash($_POST['status'])) : Status::PENDING;
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'status' => $status], admin_url('tools.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can($this->c->caps->capability('review'))) {
            return;
        }
        $status = isset($_GET['status']) ? sanitize_key((string) wp_unslash($_GET['status'])) : Status::PENDING;
        $status = in_array($status, Status::ALL, true) ? $status : Status::PENDING;
        $page = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $result = $this->c->suggestions->query(['status' => $status, 'page' => $page, 'per_page' => self::PER_PAGE]);
        $postIds = array_map(static fn ($s): int => $s->postId, $result['items']);
        if ($postIds !== []) {
            _prime_post_caches($postIds, false, true);
        }
        try {
            $adapter = $this->c->adapters->resolve($this->c->settings->string('target'));
        } catch (TargetUnavailableException $e) {
            $adapter = null;
        }
        $limits = $this->c->validator->limits(null);

        echo '<div class="wrap"><h1>' . esc_html__('SEO suggestions', 'meta-writer') . '</h1>';
        $msgs = get_transient('meta_writer_review_msg_' . get_current_user_id());
        delete_transient('meta_writer_review_msg_' . get_current_user_id());
        if (is_array($msgs) && $msgs !== []) {
            echo '<div class="notice notice-info"><ul>';
            foreach ($msgs as $m) {
                echo '<li>' . esc_html((string) $m) . '</li>';
            }
            echo '</ul></div>';
        }
        if ($adapter === null) {
            echo '<div class="notice notice-error"><p>' . esc_html__('No usable target: accept is blocked until a target is chosen in Settings > meta-writer.', 'meta-writer') . '</p></div>';
        }
        echo '<ul class="subsubsub">';
        foreach (Status::ALL as $st) {
            printf('<li><a href="%s" %s>%s</a> | </li>', esc_url(add_query_arg(['page' => self::SLUG, 'status' => $st], admin_url('tools.php'))), $st === $status ? 'class="current"' : '', esc_html($st));
        }
        echo '</ul><br class="clear" />';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('meta_writer_review');
        printf('<input type="hidden" name="action" value="meta_writer_review" /><input type="hidden" name="status" value="%s" />', esc_attr($status));
        echo '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>Post</th><th>Current</th><th>Suggested title</th><th>Suggested description</th><th>Flags</th><th></th></tr></thead><tbody>';
        if ($result['items'] === []) {
            echo '<tr><td colspan="7">' . esc_html__('Nothing here.', 'meta-writer') . '</td></tr>';
        }
        foreach ($result['items'] as $s) {
            $post = get_post($s->postId);
            $current = [];
            foreach (Field::ALL as $field) {
                $current[] = $field . ': ' . ($adapter !== null ? ($adapter->read($s->postId, $field) ?: '(empty)') : '?');
            }
            $editable = in_array($s->status, [Status::PENDING, Status::STALE], true);
            echo '<tr>';
            printf('<th class="check-column">%s</th>', $editable && $s->status === Status::PENDING ? '<input type="checkbox" name="ids[]" value="' . esc_attr((string) $s->id) . '" />' : '');
            printf(
                '<td><a href="%s">%s</a><br /><small>#%d, %s</small></td>',
                esc_url((string) get_edit_post_link($s->postId)),
                esc_html($post instanceof \WP_Post ? html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8') : '(missing)'),
                $s->id,
                esc_html($s->status . ($s->error !== '' ? ': ' . $s->error : '')),
            );
            echo '<td><small>' . esc_html(implode(' | ', $current)) . '</small></td>';
            foreach (Field::ALL as $field) {
                $text = $s->text($field);
                if (!$s->hasField($field)) {
                    echo '<td><em>' . esc_html__('not wanted', 'meta-writer') . '</em></td>';
                    continue;
                }
                $len = Length::measure($text);
                $ok = $len >= $limits[$field . '_min'] && $len <= $limits[$field . '_max'];
                printf(
                    '<td><textarea name="edit[%d][%s]" rows="%d" style="width:100%%" %s>%s</textarea><br /><small style="color:%s">%d / %d-%d</small></td>',
                    $s->id,
                    esc_attr($field),
                    $field === Field::TITLE ? 2 : 4,
                    $editable ? '' : 'readonly',
                    esc_textarea($text),
                    $ok ? '#007017' : '#b32d2e',
                    $len,
                    $limits[$field . '_min'],
                    $limits[$field . '_max'],
                );
            }
            echo '<td>' . esc_html(implode(', ', $s->flags)) . '</td><td>';
            if ($editable) {
                if ($s->status === Status::STALE) {
                    printf('<label><input type="checkbox" name="confirm_stale[%d]" value="1" /> %s</label><br />', $s->id, esc_html__('I have read it; the post changed', 'meta-writer'));
                }
                printf('<button class="button button-primary" name="single" value="%d">%s</button>', $s->id, esc_html__('Accept', 'meta-writer'));
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p>';
        echo '<select name="op"><option value="accept">' . esc_html__('Accept selected', 'meta-writer') . '</option><option value="reject">' . esc_html__('Reject selected', 'meta-writer') . '</option></select> ';
        submit_button(__('Apply to selected', 'meta-writer'), 'secondary', 'submit', false);
        echo '</p></form>';
        $pages = (int) ceil($result['total'] / self::PER_PAGE);
        if ($pages > 1) {
            echo '<p>' . wp_kses_post((string) paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => $pages])) . '</p>';
        }
        echo '</div>';
    }
}
