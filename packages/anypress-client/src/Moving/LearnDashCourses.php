<?php

namespace AnyPressClient\Moving;

/**
 * LearnDash courses, read from what LearnDash keeps in WordPress: courses, lessons, topics and quizzes as posts, the
 * course builder's steps and section headings as post meta, enrolments, group access and progress as user meta (and
 * its activity table).
 *
 * How the outline maps (FluentCommunity has sections holding lessons; LearnDash has lessons holding topics):
 * - a course with LearnDash section headings: each heading becomes a section, its lessons and their topics its lessons;
 * - otherwise a course whose lessons have topics: each lesson becomes a section, its own text (when it has any) its
 *   first lesson, and its topics the lessons after it;
 * - otherwise: one section holding every lesson.
 */
class LearnDashCourses extends CourseSource
{
    public function name(): string
    {
        return defined('LEARNDASH_VERSION') ? 'LearnDash' : __('LearnDash (switched off, its courses are still here)', 'anypress-client');
    }

    public function found(): bool
    {
        return self::count_posts('sfwd-courses') > 0;
    }

    public function extra_counts(): array
    {
        return ['quizzes' => self::count_posts('sfwd-quiz')];
    }

    public function lost(): array
    {
        return [
            __('Quizzes, assignments and certificates. Their lessons come across; the quizzes and certificates have to be made again.', 'anypress-client'),
            __('Course prices and how access is sold. Sell access to the courses with FluentCart instead.', 'anypress-client'),
            __('Drip schedules (lessons that open after a number of days) and prerequisites.', 'anypress-client'),
            __('LearnDash groups as such: their members come across as students of the group\'s courses.', 'anypress-client'),
            __('LearnDash shortcodes and blocks inside lessons show as plain text: check the lessons that used them.', 'anypress-client'),
        ];
    }

    protected function read(): array
    {
        $plan = [];
        $courses = self::course_posts('sfwd-courses');
        if (!$courses) {
            return $plan;
        }
        $progress = self::progress();
        foreach ($courses as $course) {
            $id = (int) $course->ID;
            $open = self::price_type($id) === 'open';
            $students = self::students($id, $open ? array_keys($progress['steps'][$id] ?? []) : []);
            $sections = self::sections($id);
            $steps = self::lesson_ids($sections);
            $completed = [];
            foreach ($students as $user_id) {
                $done = array_values(array_intersect($steps, $progress['steps'][$id][$user_id] ?? []));
                if ($done) {
                    $completed[$user_id] = $done;
                }
            }
            $plan[$id] = self::course_fields($course) + [
                'privacy' => $open ? 'public' : 'private',
                'sections' => $sections,
                'students' => $students,
                'completed' => $completed,
                'finished' => array_values(array_intersect($students, $progress['finished'][$id] ?? [])),
            ];
        }

        return $plan;
    }

    /** The course's sections, each ['title', 'lessons' => [step, ..]], in LearnDash's order (see the class comment). */
    private static function sections(int $course): array
    {
        $outline = self::outline($course);
        $lessons = array_values(array_filter($outline, fn ($item) => isset($item['lesson'])));
        $headings = count($lessons) < count($outline);
        $sections = [];
        if ($headings) {
            $current = null;
            foreach ($outline as $item) {
                if (isset($item['heading'])) {
                    $sections[] = ['title' => $item['heading'], 'lessons' => []];
                    $current = count($sections) - 1;
                    continue;
                }
                if ($current === null) {
                    $sections[] = ['title' => __('Lessons', 'anypress-client'), 'lessons' => []];
                    $current = 0;
                }
                $sections[$current]['lessons'][] = self::step($item['lesson']);
                foreach ($item['topics'] as $topic) {
                    $sections[$current]['lessons'][] = self::step($topic);
                }
            }
        } elseif (array_filter(array_column($lessons, 'topics'))) {
            foreach ($lessons as $item) {
                $lesson = self::step($item['lesson']);
                $section = ['title' => $lesson['title'], 'lessons' => []];
                if (!$item['topics'] || $lesson['has_body']) {
                    $section['lessons'][] = $lesson;
                }
                foreach ($item['topics'] as $topic) {
                    $section['lessons'][] = self::step($topic);
                }
                $sections[] = $section;
            }
        } elseif ($lessons) {
            $sections[] = ['title' => __('Lessons', 'anypress-client'), 'lessons' => array_map(fn ($item) => self::step($item['lesson']), $lessons)];
        }

        return array_values(array_filter($sections, fn ($section) => $section['lessons']));
    }

    /**
     * The course's lessons with their topics, and its section headings, in order: ['heading' => title] or
     * ['lesson' => id, 'topics' => [ids]]. From the course builder's saved steps when there are any, else from the
     * lessons' and topics' own course and lesson settings.
     */
    private static function outline(int $course): array
    {
        $items = [];
        $steps = maybe_unserialize(get_post_meta($course, 'ld_course_steps', true));
        $tree = is_array($steps) ? ($steps['steps']['h']['sfwd-lessons'] ?? null) : null;
        if (is_array($tree) && $tree) {
            $live = self::live_ids(array_merge(array_keys($tree), ...array_map(fn ($lesson) => array_keys((array) ($lesson['sfwd-topic'] ?? [])), array_values($tree))));
            foreach ($tree as $lesson => $children) {
                if (isset($live[(int) $lesson])) {
                    $topics = array_values(array_filter(array_map('intval', array_keys((array) ($children['sfwd-topic'] ?? []))), fn ($id) => isset($live[$id])));
                    $items[] = ['lesson' => (int) $lesson, 'topics' => $topics];
                }
            }
        } else {
            $lessons = array_map('intval', self::posts(['post_type' => 'sfwd-lessons', 'meta_key' => 'course_id', 'meta_value' => $course, 'fields' => 'ids'])); // phpcs:ignore WordPress.DB.SlowDBQuery -- one admin run.
            foreach ($lessons as $lesson) {
                $topics = array_map('intval', self::posts([
                    'post_type' => 'sfwd-topic',
                    'meta_query' => [['key' => 'lesson_id', 'value' => $lesson], ['key' => 'course_id', 'value' => $course]], // phpcs:ignore WordPress.DB.SlowDBQuery -- one admin run.
                    'fields' => 'ids',
                ]));
                $items[] = ['lesson' => $lesson, 'topics' => $topics];
            }
        }
        // Section headings sit between lessons; "order" is the heading's place among lessons and headings together.
        $headings = json_decode((string) get_post_meta($course, 'course_sections', true), true);
        $headings = is_array($headings) ? array_filter($headings, fn ($h) => is_array($h) && isset($h['order'], $h['post_title'])) : [];
        usort($headings, fn ($a, $b) => (int) $a['order'] <=> (int) $b['order']);
        $outline = [];
        $placed = 0;
        foreach ($headings as $heading) {
            while ($items && $placed < (int) $heading['order']) {
                $outline[] = array_shift($items);
                $placed++;
            }
            $placed++;
            $outline[] = ['heading' => sanitize_text_field((string) $heading['post_title'])];
        }

        return array_merge($outline, $items);
    }

    /** @return array<int, true> Which of these posts exist and aren't in the bin. */
    private static function live_ids(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $found = self::posts(['post__in' => $ids, 'post_type' => ['sfwd-lessons', 'sfwd-topic'], 'fields' => 'ids']);

        return array_fill_keys(array_map('intval', $found), true);
    }

    /** One lesson or topic, with its LearnDash video (when switched on) first. */
    private static function step(int $id): array
    {
        $type = get_post_type($id) ?: 'sfwd-lessons';
        $settings = (array) maybe_unserialize(get_post_meta($id, '_' . $type, true));
        $video = ($settings[$type . '_lesson_video_enabled'] ?? '') === 'on' ? (string) ($settings[$type . '_lesson_video_url'] ?? '') : '';

        return self::lesson($id, $video);
    }

    /** open, free, paynow, subscribe or closed. */
    private static function price_type(int $course): string
    {
        $type = (string) get_post_meta($course, '_ld_price_type', true);
        if ($type === '') {
            $settings = (array) maybe_unserialize(get_post_meta($course, '_sfwd-courses', true));
            $type = (string) ($settings['sfwd-courses_course_price_type'] ?? 'open');
        }

        return $type !== '' ? $type : 'open';
    }

    /**
     * Users with access to the course: enrolled one by one, through a LearnDash group, or on the course's old access
     * list; for an open course (anyone may take it), everyone who started it.
     *
     * @param int[] $started Users with progress in the course.
     * @return int[]
     */
    private static function students(int $course, array $started): array
    {
        $ids = self::users_with('course_' . $course . '_access_from');
        foreach (array_keys((array) get_post_meta($course)) as $key) {
            $group = str_starts_with((string) $key, 'learndash_group_enrolled_') ? (int) substr((string) $key, 25) : 0;
            if ($group > 0) {
                $ids = array_merge($ids, self::users_with('learndash_group_users_' . $group));
            }
        }
        $settings = (array) maybe_unserialize(get_post_meta($course, '_sfwd-courses', true));
        $ids = array_merge($ids, explode(',', (string) ($settings['sfwd-courses_course_access_list'] ?? '')), $started);

        return self::real_users($ids);
    }

    /**
     * What LearnDash recorded each user finished: lessons and topics from their course progress (user meta) and
     * LearnDash's activity table, and courses they completed.
     *
     * @return array{steps: array<int, array<int, int[]>>, finished: array<int, int[]>} course => user => step ids;
     *     course => user ids.
     */
    private static function progress(): array
    {
        $steps = [];
        $finished = [];
        foreach (self::users_with('_sfwd-course_progress') as $user) {
            foreach ((array) get_user_meta($user, '_sfwd-course_progress', true) as $course => $done) {
                $course = (int) $course;
                $steps[$course][$user] = $steps[$course][$user] ?? [];
                foreach ((array) ($done['lessons'] ?? []) as $lesson => $complete) {
                    if ((int) $complete === 1) {
                        $steps[$course][$user][] = (int) $lesson;
                    }
                }
                foreach ((array) ($done['topics'] ?? []) as $topics) {
                    foreach ((array) $topics as $topic => $complete) {
                        if ((int) $complete === 1) {
                            $steps[$course][$user][] = (int) $topic;
                        }
                    }
                }
            }
        }
        if (self::has_table('learndash_user_activity')) {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare('SELECT user_id, post_id, course_id, activity_type FROM %i WHERE activity_status = 1 AND activity_type IN (%s, %s, %s)', $wpdb->prefix . 'learndash_user_activity', 'lesson', 'topic', 'course')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnDash's own table, read with LearnDash off (see CourseSource::has_table()).
            foreach ((array) $rows as $row) {
                $course = (int) ($row->activity_type === 'course' ? $row->post_id : $row->course_id);
                if ($row->activity_type === 'course') {
                    $finished[$course][] = (int) $row->user_id;
                } else {
                    $steps[$course][(int) $row->user_id][] = (int) $row->post_id;
                }
            }
        }
        // A finished course is a course_completed_<course id> user meta key holding when.
        $completers = get_users(['blog_id' => 0, 'fields' => 'ID', 'meta_query' => [['key' => 'course_completed_', 'compare_key' => 'LIKE']]]); // phpcs:ignore WordPress.DB.SlowDBQuery -- one admin run.
        foreach (array_map('intval', $completers) as $user) {
            foreach ((array) get_user_meta($user) as $key => $values) {
                if (str_starts_with((string) $key, 'course_completed_') && (int) ($values[0] ?? 0) > 0) {
                    $finished[(int) substr((string) $key, 17)][] = $user;
                }
            }
        }
        foreach ($steps as $course => $users) {
            foreach ($users as $user => $ids) {
                $steps[$course][$user] = array_values(array_unique($ids));
            }
        }

        return ['steps' => $steps, 'finished' => array_map(fn ($ids) => array_values(array_unique($ids)), $finished)];
    }

    /** @return int[] Users that have this user meta key. */
    private static function users_with(string $key): array
    {
        return array_map('intval', get_users(['blog_id' => 0, 'fields' => 'ID', 'meta_key' => $key])); // phpcs:ignore WordPress.DB.SlowDBQuery -- one admin run.
    }
}
