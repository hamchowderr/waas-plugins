<?php

namespace AnyPressClient\Moving;

use WP_REST_Request;

/**
 * Courses from LearnDash, Tutor LMS or LifterLMS into FluentCommunity courses: each course with its outline, its
 * students and how far each student got.
 *
 * FluentCommunity has no importer of its own for these, so this builds the courses through FluentCommunity's own
 * course admin routes (the ones behind its course editor): a course, its sections, its lessons, then its students.
 * Lesson progress comes across as FluentCommunity records it (its Reaction and Activity models), without the
 * "lesson completed" hooks, so old progress doesn't start automations or award anything again.
 *
 * The course plugin doesn't need to be active: a store of the community site type turns it off, and each source (see
 * CourseSource) reads what its plugin leaves in WordPress directly. Courses already in FluentCommunity are left
 * alone, and undo removes only the courses this added.
 */
class CourseImport extends NativeImport
{
    private const ROUTE = '/fluent-community/v2/admin/courses';

    /** @var CourseSource[] slug => source. */
    private array $readers;

    public function __construct()
    {
        $this->readers = ['learndash' => new LearnDashCourses(), 'tutor' => new TutorCourses(), 'lifterlms' => new LifterCourses()];
    }

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
        $out = [];
        foreach ($this->readers as $slug => $reader) {
            $found = $reader->found();
            $counts = [];
            if ($found) {
                $counts = array_fill_keys(['courses', 'sections', 'lessons', 'students', 'completed_lessons', 'completed_courses'], 0);
                foreach ($reader->courses() as $course) {
                    $counts['courses']++;
                    $counts['sections'] += count($course['sections']);
                    foreach ($course['sections'] as $section) {
                        $counts['lessons'] += count($section['lessons']);
                    }
                    $counts['students'] += count($course['students']);
                    $counts['completed_lessons'] += array_sum(array_map('count', $course['completed']));
                    $counts['completed_courses'] += count($course['finished']);
                }
                $counts += $reader->extra_counts();
            }
            $out[$slug] = ['name' => $reader->name(), 'found' => $found, 'counts' => $counts];
        }

        return $out;
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
        foreach ($this->readers[$source]->courses() as $source_id => $course) {
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
            $state['courses'][$source_id] = $course_id;
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
     * @return array Source lesson id => FluentCommunity lesson id.
     */
    public static function build_outline(int $course_id, array $sections): array
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
     * @param array $lessons   Source lesson id => FluentCommunity lesson id.
     * @param array $completed User id => source lesson ids they finished.
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
    public static function call(string $method, string $path, array $body = [], bool $must = true): array
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
        return isset($this->readers[$source]) ? $this->readers[$source]->lost() : [];
    }
}
