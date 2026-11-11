<?php

namespace AnyPressClient\Moving;

/**
 * LifterLMS courses, read from what LifterLMS keeps in WordPress: courses, sections, lessons and quizzes as posts (a
 * section points at its course and a lesson at its section through post meta, each with its place in _llms_order), and
 * enrolments and progress in its own lifterlms_user_postmeta table, where the newest row for a user and a post counts.
 *
 * The outline maps one to one: each LifterLMS section becomes a section and its lessons that section's lessons.
 */
class LifterCourses extends CourseSource
{
    public function name(): string
    {
        return defined('LLMS_VERSION') ? 'LifterLMS' : __('LifterLMS (switched off, its courses are still here)', 'anypress-client');
    }

    public function found(): bool
    {
        // "course" is also another plugin's post type; LifterLMS's own table tells them apart.
        return NativeImport::table_exists('lifterlms_user_postmeta') && self::count_posts('course') > 0;
    }

    public function extra_counts(): array
    {
        return ['quizzes' => self::count_posts('llms_quiz'), 'memberships' => self::count_posts('llms_membership')];
    }

    public function lost(): array
    {
        return [
            __('Quizzes, assignments, certificates and achievements. The lessons around them come across; quizzes and certificates have to be made again.', 'anypress-client'),
            __('Access plans, prices and memberships. Students enrolled through a membership come across only where LifterLMS enrolled them in the course itself. Sell access to the courses with FluentCart instead.', 'anypress-client'),
            __('Drip schedules, prerequisites and course tracks.', 'anypress-client'),
            __('LifterLMS shortcodes and blocks inside lessons: check the lessons that used them.', 'anypress-client'),
        ];
    }

    protected function read(): array
    {
        $plan = [];
        $courses = self::course_posts('course');
        if (!$courses) {
            return $plan;
        }
        $enrolled = self::latest('_status', 'enrolled');
        $complete = self::latest('_is_complete', 'yes');
        foreach ($courses as $course) {
            $id = (int) $course->ID;
            $sections = [];
            foreach (self::ordered('section', '_llms_parent_course', $id) as $section) {
                $lessons = array_map(fn ($lesson) => self::lesson($lesson, (string) get_post_meta($lesson, '_llms_video_embed', true)), self::ordered('lesson', '_llms_parent_section', $section));
                if ($lessons) {
                    $sections[] = ['title' => (string) get_the_title($section), 'lessons' => $lessons];
                }
            }
            $students = self::real_users($enrolled[$id] ?? []);
            $completed = [];
            foreach (self::lesson_ids($sections) as $lesson) {
                foreach (array_intersect($students, $complete[$lesson] ?? []) as $user_id) {
                    $completed[$user_id][] = $lesson;
                }
            }
            ksort($completed);
            $plan[$id] = self::course_fields($course) + [
                'privacy' => self::free($id) ? 'public' : 'private',
                'sections' => $sections,
                'students' => $students,
                'completed' => $completed,
                'finished' => array_values(array_intersect($students, $complete[$id] ?? [])),
            ];
        }

        return $plan;
    }

    /** Posts of this type pointing at $parent through $key, not in the bin, by their _llms_order. */
    private static function ordered(string $type, string $key, int $parent): array
    {
        global $wpdb;
        $p = $wpdb->prefix;

        return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$p}posts p JOIN {$p}postmeta m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %d LEFT JOIN {$p}postmeta o ON o.post_id = p.ID AND o.meta_key = '_llms_order' WHERE p.post_type = %s AND p.post_status IN " . self::LIVE . ' ORDER BY CAST(o.meta_value AS UNSIGNED), p.menu_order, p.ID', $key, $parent, $type))); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /**
     * Users whose newest $key row for a post holds $value.
     *
     * @return array<int, int[]> post id => user ids.
     */
    private static function latest(string $key, string $value): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, post_id, meta_value FROM {$wpdb->prefix}lifterlms_user_postmeta WHERE meta_key = %s ORDER BY updated_date, meta_id", $key)); // phpcs:ignore WordPress.DB.PreparedSQL
        $last = [];
        foreach ((array) $rows as $row) {
            $last[(int) $row->post_id][(int) $row->user_id] = (string) $row->meta_value;
        }
        $out = [];
        foreach ($last as $post => $users) {
            $out[$post] = array_keys(array_filter($users, fn ($v) => $v === $value));
        }

        return $out;
    }

    /** Whether the course has a free access plan that's on sale: anyone may take it. */
    private static function free(int $course): bool
    {
        global $wpdb;
        $p = $wpdb->prefix;

        return (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}posts a JOIN {$p}postmeta c ON c.post_id = a.ID AND c.meta_key = '_llms_product_id' AND c.meta_value = %d JOIN {$p}postmeta f ON f.post_id = a.ID AND f.meta_key = '_llms_is_free' AND f.meta_value = 'yes' WHERE a.post_type = 'llms_access_plan' AND a.post_status = 'publish'", $course)); // phpcs:ignore WordPress.DB.PreparedSQL
    }
}
