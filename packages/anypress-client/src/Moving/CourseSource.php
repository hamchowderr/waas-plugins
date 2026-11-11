<?php

namespace AnyPressClient\Moving;

/**
 * One course plugin the course switch reads from (LearnDash, Tutor LMS or LifterLMS), on the same website.
 *
 * Each reads what its plugin leaves in WordPress (posts, post meta, user meta, its own tables) directly, so it works
 * with the plugin switched off, as a community store has it. It never changes that data.
 *
 * A source gives every course as FluentCommunity will hold it:
 * [
 *   'title', 'description', 'details', 'privacy' => public|private, 'status' => published|draft,
 *   'sections' => [['title', 'lessons' => [lesson, ..]], ..],
 *   'students' => [user id, ..],
 *   'completed' => [user id => [source lesson id, ..]],
 *   'finished' => [user id, ..],
 * ]
 * where a lesson is ['post', 'title', 'body', 'has_body', 'status', 'image'] (see lesson()).
 */
abstract class CourseSource
{
    protected const LIVE = "('publish', 'draft', 'pending', 'private', 'future')";

    /** @var array|null Worked out once per request. */
    private ?array $courses = null;

    /** The plugin's name, as shown in the check. */
    abstract public function name(): string;

    /** Whether this website holds courses from the plugin. */
    abstract public function found(): bool;

    /** Every course, keyed by its id in the plugin (see the class comment). */
    abstract protected function read(): array;

    /** What doesn't come across from this plugin, one sentence each. */
    abstract public function lost(): array;

    /** Counts shown in the check besides the ones that come across, such as quizzes. */
    public function extra_counts(): array
    {
        return [];
    }

    public function courses(): array
    {
        if ($this->courses === null) {
            $this->courses = $this->read();
        }

        return $this->courses;
    }

    /** The course's post fields as FluentCommunity's course fields: title, description, details and status. */
    protected static function course_fields(object $course): array
    {
        $text = trim(wp_strip_all_tags(strip_shortcodes((string) $course->post_content)));
        $excerpt = trim(wp_strip_all_tags((string) $course->post_excerpt));

        return [
            'title' => (string) $course->post_title,
            'description' => $excerpt !== '' ? $excerpt : ($text !== '' ? wp_trim_words($text, 55) : (string) $course->post_title),
            'details' => $text,
            'status' => $course->post_status === 'publish' ? 'published' : 'draft',
        ];
    }

    /**
     * One lesson as a FluentCommunity lesson: its title, text (with its video first), status and image.
     *
     * @param string $video A video URL: YouTube, Vimeo and other oEmbed links become an embed, a file a video block.
     */
    protected static function lesson(int $id, string $video = ''): array
    {
        $post = get_post($id);
        $body = $post ? (string) $post->post_content : '';
        $video = filter_var($video, FILTER_VALIDATE_URL) ? esc_url_raw($video) : '';
        $has_body = trim(wp_strip_all_tags($body)) !== '' || $video !== '';
        if ($video !== '' && preg_match('/\.(mp4|m4v|webm|ogv|mov)(\?|$)/i', $video)) {
            $body = '<!-- wp:video -->' . "\n" . '<figure class="wp-block-video"><video controls src="' . esc_url($video) . '"></video></figure>' . "\n<!-- /wp:video -->\n\n" . $body;
        } elseif ($video !== '') {
            $body = '<!-- wp:embed ' . wp_json_encode(['url' => $video]) . " -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\n" . esc_url($video) . "\n</div></figure>\n<!-- /wp:embed -->\n\n" . $body;
        }

        return [
            'post' => $id,
            'title' => $post ? (string) $post->post_title : '',
            'body' => $body,
            'has_body' => $has_body,
            'status' => $post && $post->post_status === 'publish' ? 'published' : 'draft',
            'image' => (int) get_post_thumbnail_id($id),
        ];
    }

    /** Ids of the lessons in these sections, in order. */
    protected static function lesson_ids(array $sections): array
    {
        $ids = [];
        foreach ($sections as $section) {
            foreach ($section['lessons'] as $lesson) {
                $ids[] = (int) $lesson['post'];
            }
        }

        return $ids;
    }

    /**
     * Which of these users still exist, in id order.
     *
     * @return int[]
     */
    protected static function real_users(array $ids): array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        return array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users} WHERE ID IN (" . implode(',', $ids) . ') ORDER BY ID')); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** Ids of posts of this type below $parent (post_parent), not in the bin, in the plugin's order. */
    protected static function children(string $type, int $parent): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status IN " . self::LIVE . ' ORDER BY menu_order, ID', $type, $parent))); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** Courses of this post type, not in the bin, in the plugin's order. */
    protected static function course_posts(string $type): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_content, post_excerpt, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN " . self::LIVE . ' ORDER BY menu_order, ID', $type)); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    protected static function count_posts(string $type): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN " . self::LIVE, $type)); // phpcs:ignore WordPress.DB.PreparedSQL
    }
}
