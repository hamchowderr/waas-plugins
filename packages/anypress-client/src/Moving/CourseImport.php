<?php

namespace AnyPressClient\Moving;

use WP_REST_Request;

/**
 * LearnDash courses into FluentCommunity courses: each course with its lessons and topics, its students and how far
 * each student got.
 *
 * FluentCommunity has no LearnDash importer of its own yet, so this builds the courses through FluentCommunity's own
 * course admin routes (the ones behind its course editor): a course, its sections, its lessons, then its students.
 * Lesson progress comes across as FluentCommunity records it (its Reaction and Activity models), without the
 * "lesson completed" hooks, so old progress doesn't start automations or award anything again.
 *
 * LearnDash doesn't need to be active: a store of the community site type turns it off, and LearnDash keeps its
 * courses as WordPress posts and its enrolments and progress as user meta, so this reads those directly.
 *
 * How the outline maps (FluentCommunity has sections holding lessons; LearnDash has lessons holding topics):
 * - a course with LearnDash section headings: each heading becomes a section, its lessons and their topics its lessons;
 * - otherwise a course whose lessons have topics: each lesson becomes a section, its own text (when it has any) its
 *   first lesson, and its topics the lessons after it;
 * - otherwise: one section holding every lesson.
 * Courses already in FluentCommunity are left alone, and undo removes only the courses this added.
 */
class CourseImport extends NativeImport
{
    private const ROUTE = '/fluent-community/v2/admin/courses';
    private const LIVE = "('publish', 'draft', 'pending', 'private', 'future')";

    /** @var array|null The outline of every LearnDash course, worked out once per request. */
    private ?array $plan = null;

    public function key(): string
    {
        return 'course';
    }

    public function target_name(): string
    {
        return 'FluentCommunity';
    }

    protected function target_file(): string
    {
        return 'fluent-community/fluent-community.php';
    }

    protected function target_version(): string
    {
        return defined('FLUENT_COMMUNITY_PLUGIN_VERSION') && class_exists('\FluentCommunity\Modules\Course\Model\Course') ? (string) FLUENT_COMMUNITY_PLUGIN_VERSION : '';
    }

    protected function sources(): array
    {
        $found = self::count('posts', "post_type = 'sfwd-courses' AND post_status IN " . self::LIVE) > 0;
        $counts = [];
        if ($found) {
            $counts = array_fill_keys(['courses', 'sections', 'lessons', 'students', 'completed_lessons', 'completed_courses'], 0);
            foreach ($this->plan() as $course) {
                $counts['courses']++;
                $counts['sections'] += count($course['sections']);
                foreach ($course['sections'] as $section) {
                    $counts['lessons'] += count($section['lessons']);
                }
                $counts['students'] += count($course['students']);
                $counts['completed_lessons'] += array_sum(array_map('count', $course['completed']));
                $counts['completed_courses'] += count($course['finished']);
            }
            $counts['quizzes'] = self::count('posts', "post_type = 'sfwd-quiz' AND post_status IN " . self::LIVE);
        }
        $name = defined('LEARNDASH_VERSION') ? 'LearnDash' : __('LearnDash (switched off, its courses are still here)', 'anypress-client');

        return ['learndash' => ['name' => $name, 'found' => $found, 'counts' => $counts]];
    }

    protected function target_counts(): array
    {
        global $wpdb;
        $p = $wpdb->prefix;

        return [
            'courses' => self::count('fcom_spaces', "type = 'course'"),
            'sections' => self::count('fcom_posts', "type = 'course_section'"),
            'lessons' => self::count('fcom_posts', "type = 'course_lesson'"),
            'students' => self::table_exists('fcom_space_user') ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fcom_space_user m JOIN {$p}fcom_spaces s ON s.id = m.space_id WHERE s.type = 'course' AND m.role = 'student'") : 0, // phpcs:ignore WordPress.DB.PreparedSQL
            'completed_lessons' => self::count('fcom_post_reactions', "object_type = 'lesson_completed' AND type = 'completed'"),
            'completed_courses' => self::count('fcom_user_activities', "action_name = 'course_completed'"),
        ];
    }

    protected function target_blocker(array $counts): string
    {
        if (class_exists('\FluentCommunity\App\Services\Helper') && !\FluentCommunity\App\Services\Helper::isFeatureEnabled('course_module')) {
            return __('Courses are turned off in FluentCommunity. Turn on Courses in FluentCommunity\'s settings (Features), then run this again.', 'anypress-client');
        }

        return '';
    }

    protected function target_warnings(): array
    {
        return [
            __('Students are enrolled the way FluentCommunity enrols them, so an automation set to start when someone joins a course (in FluentCRM or AutomatorWP) starts for each of them. Pause such automations first if they shouldn\'t.', 'anypress-client'),
        ];
    }

    protected function compare_keys(): array
    {
        $keys = ['courses', 'sections', 'lessons', 'students', 'completed_lessons', 'completed_courses'];

        return array_combine($keys, $keys);
    }

    protected function import(string $source, array $before): array
    {
        $state = array_merge($this->state(), ['courses' => [], 'profiles_before' => self::profile_ids(), 'skipped' => 0]);
        update_option($this->option(), $state, false);
        $skipped = 0;
        foreach ($this->plan() as $ld_id => $course) {
            $made = self::call('POST', '', [
                'title' => $course['title'],
                'description' => $course['description'],
                'privacy' => $course['privacy'],
                'course_type' => 'self_paced',
                'status' => $course['status'],
                'settings' => ['course_details' => $course['details']],
            ]);
            $course_id = (int) ($made['course']['id'] ?? 0);
            if (!$course_id) {
                throw new \RuntimeException(sprintf('FluentCommunity made no course for "%s".', esc_html($course['title'])));
            }
            // Recorded at once, so undo finds the course even if a later step stops the run.
            $state['courses'][$ld_id] = $course_id;
            update_option($this->option(), $state, false);

            $lessons = self::build_outline($course_id, $course['sections']);
            // Students after the lessons, so publishing a lesson notifies nobody.
            foreach ($course['students'] as $user_id) {
                if (!self::call('POST', "/{$course_id}/students", ['user_id' => $user_id], false)) {
                    $skipped++;
                }
            }
            self::restore_progress($course_id, $lessons, $course['completed'], $course['finished']);
        }
        $state['skipped'] = $skipped;

        return $state;
    }

    /**
     * Makes the course's sections and lessons, in order.
     *
     * @return array LearnDash lesson or topic id => FluentCommunity lesson id.
     */
    private static function build_outline(int $course_id, array $sections): array
    {
        $lessons = [];
        foreach ($sections as $section) {
            $made = self::call('POST', "/{$course_id}/sections", ['title' => $section['title']]);
            $section_id = (int) ($made['section']['id'] ?? 0);
            foreach ($section['lessons'] as $step) {
                $made = self::call('POST', "/{$course_id}/lessons", ['title' => $step['title'], 'section_id' => $section_id]);
                $lesson_id = (int) ($made['lesson']['id'] ?? 0);
                $meta = ['enable_comments' => 'yes'];
                if ($step['image']) {
                    $meta['featured_image_id'] = $step['image'];
                }
                self::call('PUT', "/{$course_id}/lessons/{$lesson_id}", ['lesson' => [
                    'title' => $step['title'],
                    'parent_id' => $section_id,
                    'status' => $step['status'],
                    'message' => $step['body'],
                    'meta' => $meta,
                ]]);
                $lessons[$step['post']] = $lesson_id;
            }
        }

        return $lessons;
    }

    /**
     * Lessons each student finished, and courses they completed, recorded as FluentCommunity records them.
     *
     * @param array $lessons   LearnDash step id => FluentCommunity lesson id.
     * @param array $completed User id => LearnDash step ids they finished.
     * @param array $finished  User ids who completed the course.
     */
    private static function restore_progress(int $course_id, array $lessons, array $completed, array $finished): void
    {
        foreach ($completed as $user_id => $steps) {
            foreach ($steps as $step) {
                if (isset($lessons[$step])) {
                    \FluentCommunity\App\Models\Reaction::create([
                        'user_id' => $user_id,
                        'object_id' => $lessons[$step],
                        'object_type' => 'lesson_completed',
                        'parent_id' => $course_id,
                        'type' => 'completed',
                    ]);
                }
            }
        }
        foreach ($finished as $user_id) {
            \FluentCommunity\App\Models\Activity::create(['feed_id' => $course_id, 'user_id' => $user_id, 'action_name' => 'course_completed']);
        }
    }

    /**
     * Calls one of FluentCommunity's course admin routes as the signed-in administrator.
     *
     * @param bool $must Throw when the route refuses; otherwise return [] for it.
     */
    private static function call(string $method, string $path, array $body = [], bool $must = true): array
    {
        $request = new WP_REST_Request($method, self::ROUTE . $path);
        if ($body) {
            $request->set_body_params($body);
        }
        $response = rest_do_request($request);
        $data = (array) rest_get_server()->response_to_data($response, false);
        if ($response->is_error()) {
            if (!$must) {
                return [];
            }
            throw new \RuntimeException(sprintf('FluentCommunity refused %s %s: %s', esc_html($method), esc_html($path !== '' ? $path : '/'), esc_html((string) wp_json_encode($data))));
        }

        return json_decode((string) wp_json_encode($data), true) ?: ['ok' => true];
    }

    protected function remove(array $state, bool $dry_run): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $removed = array_fill_keys(['courses', 'sections', 'lessons', 'students', 'completed_lessons', 'completed_courses'], 0);
        foreach (array_map('intval', (array) ($state['courses'] ?? [])) as $course_id) {
            if (!self::count('fcom_spaces', "id = %d AND type = 'course'", [$course_id])) {
                continue;
            }
            $removed['courses']++;
            $removed['sections'] += self::count('fcom_posts', "space_id = %d AND type = 'course_section'", [$course_id]);
            $removed['lessons'] += self::count('fcom_posts', "space_id = %d AND type = 'course_lesson'", [$course_id]);
            $removed['students'] += self::count('fcom_space_user', "space_id = %d AND role = 'student'", [$course_id]);
            $removed['completed_lessons'] += self::count('fcom_post_reactions', "parent_id = %d AND object_type = 'lesson_completed'", [$course_id]);
            $removed['completed_courses'] += self::count('fcom_user_activities', "feed_id = %d AND action_name = 'course_completed'", [$course_id]);
            if ($dry_run) {
                continue;
            }
            // Progress first (FluentCommunity's course delete leaves it), then the course with its outline and students.
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}fcom_post_reactions WHERE parent_id = %d AND object_type = 'lesson_completed'", $course_id)); // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}fcom_user_activities WHERE feed_id = %d AND action_name = 'course_completed'", $course_id)); // phpcs:ignore WordPress.DB.PreparedSQL
            self::call('DELETE', "/{$course_id}");
        }
        // Profiles FluentCommunity made for the enrolled students; ones that were there before stay.
        if (isset($state['profiles_before'])) {
            $made = array_diff(self::profile_ids(), array_map('intval', (array) $state['profiles_before']));
            $removed['profiles'] = self::delete_in('fcom_xprofile', 'user_id', $made, $dry_run);
        }

        return $removed;
    }

    private static function profile_ids(): array
    {
        global $wpdb;
        if (!self::table_exists('fcom_xprofile')) {
            return [];
        }

        return array_map('intval', $wpdb->get_col("SELECT user_id FROM {$wpdb->prefix}fcom_xprofile")); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    protected function lost(string $source): array
    {
        return [
            __('Quizzes, assignments and certificates. Their lessons come across; the quizzes and certificates have to be made again.', 'anypress-client'),
            __('Course prices and how access is sold. Sell access to the courses with FluentCart instead.', 'anypress-client'),
            __('Drip schedules (lessons that open after a number of days) and prerequisites.', 'anypress-client'),
            __('LearnDash groups as such: their members come across as students of the group\'s courses.', 'anypress-client'),
            __('LearnDash shortcodes and blocks inside lessons show as plain text: check the lessons that used them.', 'anypress-client'),
        ];
    }

    /**
     * Every LearnDash course as FluentCommunity will hold it: course fields, sections with their lessons, students,
     * and progress.
     */
    private function plan(): array
    {
        if ($this->plan !== null) {
            return $this->plan;
        }
        global $wpdb;
        $p = $wpdb->prefix;
        $this->plan = [];
        $courses = $wpdb->get_results("SELECT ID, post_title, post_content, post_excerpt, post_status FROM {$p}posts WHERE post_type = 'sfwd-courses' AND post_status IN " . self::LIVE . ' ORDER BY menu_order, ID'); // phpcs:ignore WordPress.DB.PreparedSQL
        if (!$courses) {
            return $this->plan;
        }
        $progress = self::progress();
        foreach ($courses as $course) {
            $id = (int) $course->ID;
            $open = self::price_type($id) === 'open';
            $students = self::students($id, $open ? array_keys($progress['steps'][$id] ?? []) : []);
            $sections = self::sections($id, (string) $course->post_title);
            $steps = [];
            foreach ($sections as $section) {
                foreach ($section['lessons'] as $step) {
                    $steps[] = $step['post'];
                }
            }
            $completed = [];
            foreach ($students as $user_id) {
                $done = array_values(array_intersect($steps, $progress['steps'][$id][$user_id] ?? []));
                if ($done) {
                    $completed[$user_id] = $done;
                }
            }
            $text = trim(wp_strip_all_tags(strip_shortcodes((string) $course->post_content)));
            $excerpt = trim(wp_strip_all_tags((string) $course->post_excerpt));
            $this->plan[$id] = [
                'title' => (string) $course->post_title,
                'description' => $excerpt !== '' ? $excerpt : ($text !== '' ? wp_trim_words($text, 55) : (string) $course->post_title),
                'details' => $text,
                'privacy' => $open ? 'public' : 'private',
                'status' => $course->post_status === 'publish' ? 'published' : 'draft',
                'sections' => $sections,
                'students' => $students,
                'completed' => $completed,
                'finished' => array_values(array_intersect($students, $progress['finished'][$id] ?? [])),
            ];
        }

        return $this->plan;
    }

    /** The course's sections, each ['title', 'lessons' => [step, ..]], in LearnDash's order (see the class comment). */
    private static function sections(int $course, string $title): array
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
        global $wpdb;
        $p = $wpdb->prefix;
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
            $lessons = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$p}posts p JOIN {$p}postmeta m ON m.post_id = p.ID AND m.meta_key = 'course_id' AND m.meta_value = %d WHERE p.post_type = 'sfwd-lessons' AND p.post_status IN " . self::LIVE . ' ORDER BY p.menu_order, p.ID', $course))); // phpcs:ignore WordPress.DB.PreparedSQL
            foreach ($lessons as $lesson) {
                $topics = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$p}posts p JOIN {$p}postmeta l ON l.post_id = p.ID AND l.meta_key = 'lesson_id' AND l.meta_value = %d JOIN {$p}postmeta c ON c.post_id = p.ID AND c.meta_key = 'course_id' AND c.meta_value = %d WHERE p.post_type = 'sfwd-topic' AND p.post_status IN " . self::LIVE . ' ORDER BY p.menu_order, p.ID', $lesson, $course))); // phpcs:ignore WordPress.DB.PreparedSQL
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
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $found = $wpdb->get_col("SELECT ID FROM {$wpdb->prefix}posts WHERE ID IN (" . implode(',', $ids) . ') AND post_status IN ' . self::LIVE); // phpcs:ignore WordPress.DB.PreparedSQL

        return array_fill_keys(array_map('intval', $found), true);
    }

    /** One lesson or topic as a FluentCommunity lesson: its title, text (video first), status and image. */
    private static function step(int $id): array
    {
        $post = get_post($id);
        $type = $post ? $post->post_type : 'sfwd-lessons';
        $settings = (array) maybe_unserialize(get_post_meta($id, '_' . $type, true));
        $video = (string) ($settings[$type . '_lesson_video_url'] ?? '');
        $video_on = ($settings[$type . '_lesson_video_enabled'] ?? '') === 'on';
        $body = $post ? (string) $post->post_content : '';
        $has_body = trim(wp_strip_all_tags($body)) !== '' || ($video_on && $video !== '');
        if ($video_on && filter_var($video, FILTER_VALIDATE_URL)) {
            $url = esc_url_raw($video);
            $body = '<!-- wp:embed ' . wp_json_encode(['url' => $url]) . " -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\n" . esc_url($url) . "\n</div></figure>\n<!-- /wp:embed -->\n\n" . $body;
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
        global $wpdb;
        $p = $wpdb->prefix;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$p}usermeta WHERE meta_key = %s", 'course_' . $course . '_access_from')); // phpcs:ignore WordPress.DB.PreparedSQL
        $groups = $wpdb->get_col($wpdb->prepare("SELECT SUBSTRING(meta_key, 26) FROM {$p}postmeta WHERE post_id = %d AND meta_key LIKE %s", $course, $wpdb->esc_like('learndash_group_enrolled_') . '%')); // phpcs:ignore WordPress.DB.PreparedSQL
        foreach (array_filter(array_map('intval', $groups)) as $group) {
            $ids = array_merge($ids, $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$p}usermeta WHERE meta_key = %s", 'learndash_group_users_' . $group))); // phpcs:ignore WordPress.DB.PreparedSQL
        }
        $settings = (array) maybe_unserialize(get_post_meta($course, '_sfwd-courses', true));
        $ids = array_merge($ids, explode(',', (string) ($settings['sfwd-courses_course_access_list'] ?? '')), $started);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $real = array_map('intval', $wpdb->get_col("SELECT ID FROM {$p}users WHERE ID IN (" . implode(',', $ids) . ') ORDER BY ID')); // phpcs:ignore WordPress.DB.PreparedSQL

        return $real;
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
        global $wpdb;
        $p = $wpdb->prefix;
        $steps = [];
        $finished = [];
        $rows = $wpdb->get_results("SELECT user_id, meta_value FROM {$p}usermeta WHERE meta_key = '_sfwd-course_progress'"); // phpcs:ignore WordPress.DB.PreparedSQL
        foreach ((array) $rows as $row) {
            $user = (int) $row->user_id;
            foreach ((array) maybe_unserialize($row->meta_value) as $course => $done) {
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
        if (self::table_exists('learndash_user_activity')) {
            $rows = $wpdb->get_results("SELECT user_id, post_id, course_id, activity_type FROM {$p}learndash_user_activity WHERE activity_status = 1 AND activity_type IN ('lesson', 'topic', 'course')"); // phpcs:ignore WordPress.DB.PreparedSQL
            foreach ((array) $rows as $row) {
                $course = (int) ($row->activity_type === 'course' ? $row->post_id : $row->course_id);
                if ($row->activity_type === 'course') {
                    $finished[$course][] = (int) $row->user_id;
                } else {
                    $steps[$course][(int) $row->user_id][] = (int) $row->post_id;
                }
            }
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_key FROM {$p}usermeta WHERE meta_key LIKE %s AND meta_value > 0", $wpdb->esc_like('course_completed_') . '%')); // phpcs:ignore WordPress.DB.PreparedSQL
        foreach ((array) $rows as $row) {
            $finished[(int) substr($row->meta_key, 17)][] = (int) $row->user_id;
        }
        foreach ($steps as $course => $users) {
            foreach ($users as $user => $ids) {
                $steps[$course][$user] = array_values(array_unique($ids));
            }
        }

        return ['steps' => $steps, 'finished' => array_map(fn ($ids) => array_values(array_unique($ids)), $finished)];
    }
}
