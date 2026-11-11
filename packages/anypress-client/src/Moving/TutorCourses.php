<?php

namespace AnyPressClient\Moving;

/**
 * Tutor LMS courses, read from what Tutor LMS keeps in WordPress: courses, topics, lessons, quizzes and assignments as
 * posts (a topic's parent is its course, a lesson's parent its topic), enrolments as tutor_enrolled posts (author the
 * student, parent the course), finished lessons as user meta and completed courses as comments.
 *
 * The outline maps one to one: each topic becomes a section and its lessons that section's lessons.
 */
class TutorCourses extends CourseSource
{
    public function name(): string
    {
        return defined('TUTOR_VERSION') ? 'Tutor LMS' : __('Tutor LMS (switched off, its courses are still here)', 'anypress-client');
    }

    public function found(): bool
    {
        // "courses" is Tutor LMS's post type; its settings or topics tell it apart from another plugin's.
        return self::count_posts('courses') > 0 && (defined('TUTOR_VERSION') || get_option('tutor_option') !== false || self::count_posts('topics') > 0);
    }

    public function extra_counts(): array
    {
        return ['quizzes' => self::count_posts('tutor_quiz'), 'assignments' => self::count_posts('tutor_assignments')];
    }

    public function lost(): array
    {
        return [
            __('Quizzes, assignments and certificates. The lessons around them come across; quizzes and certificates have to be made again.', 'anypress-client'),
            __('Course prices and how access is sold (Tutor LMS\'s own checkout, WooCommerce or EDD). Sell access to the courses with FluentCart instead.', 'anypress-client'),
            __('Content drip, prerequisites, course announcements, Q&A and reviews.', 'anypress-client'),
            __('Lessons\' attachments list and Tutor LMS shortcodes inside lessons: check the lessons that used them.', 'anypress-client'),
        ];
    }

    protected function read(): array
    {
        $plan = [];
        foreach (self::course_posts('courses') as $course) {
            $id = (int) $course->ID;
            $sections = [];
            foreach (self::children('topics', $id) as $topic) {
                $lessons = array_map(fn ($lesson) => self::lesson($lesson, self::video($lesson)), self::children('lesson', $topic));
                if ($lessons) {
                    $sections[] = ['title' => (string) get_the_title($topic), 'lessons' => $lessons];
                }
            }
            // Active enrolments only: a pending one waits on payment, a cancelled one was taken back.
            // Tutor LMS's "completed" status isn't registered with WordPress while Tutor LMS is off, so WordPress can't
            // filter on it: take every enrolment that isn't in the bin and keep the completed ones.
            $enrolments = get_posts(['post_type' => 'tutor_enrolled', 'post_parent' => $id, 'post_status' => 'any', 'numberposts' => -1, 'no_found_rows' => true]);
            $students = self::real_users(array_map(fn ($post) => (int) $post->post_author, array_filter($enrolments, fn ($post) => $post->post_status === 'completed')));
            $completed = [];
            $lesson_ids = self::lesson_ids($sections);
            if ($students && $lesson_ids) {
                // Tutor LMS marks a finished lesson with a user meta key per lesson.
                foreach ($students as $student) {
                    $done = array_values(array_filter($lesson_ids, fn ($lesson) => metadata_exists('user', $student, '_tutor_completed_lesson_id_' . (int) $lesson)));
                    if ($done) {
                        $completed[$student] = $done;
                    }
                }
            }
            // A finished course is a comment of Tutor LMS's own type on it.
            $finished = array_map('intval', array_unique(array_map(fn ($comment) => (int) $comment->user_id, get_comments(['post_id' => $id, 'type' => 'course_completed', 'status' => 'any']))));
            $plan[$id] = self::course_fields($course) + [
                'privacy' => self::open($id) ? 'public' : 'private',
                'sections' => $sections,
                'students' => $students,
                'completed' => $completed,
                'finished' => array_values(array_intersect($students, $finished)),
            ];
        }

        return $plan;
    }

    /** A free or public course: anyone may take it. */
    private static function open(int $course): bool
    {
        return get_post_meta($course, '_tutor_course_price_type', true) !== 'paid' || get_post_meta($course, '_tutor_is_public_course', true) === 'yes';
    }

    /** The lesson's video URL: YouTube, Vimeo, an external file or an uploaded one ('' for none or an embed code). */
    private static function video(int $lesson): string
    {
        $video = maybe_unserialize(get_post_meta($lesson, '_video', true));
        if (!is_array($video)) {
            return '';
        }
        switch ((string) ($video['source'] ?? '')) {
            case 'youtube':
                return (string) ($video['source_youtube'] ?? '');
            case 'vimeo':
                return (string) ($video['source_vimeo'] ?? '');
            case 'external_url':
                return (string) ($video['source_external_url'] ?? '');
            case 'html5':
                return (string) wp_get_attachment_url((int) ($video['source_video_id'] ?? 0));
            default:
                return '';
        }
    }
}
