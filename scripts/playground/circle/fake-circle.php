<?php
/**
 * Test only: a fake Circle Admin API v2 (https://app.circle.so/api/admin/v2/), answered inside WordPress through
 * pre_http_request, with the shapes of Circle's published spec (api-headless.circle.so/api/admin/v2/swagger.yaml):
 * paged lists of { page, per_page, has_next_page, count, page_count, records }. The token is "circle-test-token";
 * any other gets 401, as Circle does. Every call is counted in the circle_test_calls option, and every token sent is
 * kept in circle_test_tokens so the test can check the token is never saved anywhere else.
 *
 * The community: two space groups; a public discussion space (Welcome) with 3 posts, a private hidden one (Insiders)
 * with 120 posts, so the posts list takes two pages, and a chat space that doesn't come across. Four active members:
 * ada@example.com already has a WordPress user on the site; a post and a comment are by someone who left Circle (no
 * email). Comments on the first post: a comment, a reply, a reply to the reply, and a second comment. A private course
 * space (Starter Course) has two sections and three lessons (one a draft), and two members.
 */

const CIRCLE_TEST_TOKEN = 'circle-test-token';

function circle_test_data(): array
{
    $member = fn ($id, $first, $last, $email, $headline = '') => ['id' => $id, 'first_name' => $first, 'last_name' => $last, 'name' => "$first $last", 'email' => $email, 'headline' => $headline, 'active' => true];
    $members = [
        $member(11, 'Ada', 'Lovelace', 'ada@example.com', 'Founder'),
        $member(12, 'Grace', 'Hopper', 'grace@example.com', 'Moderator of Welcome'),
        $member(13, 'Alan', 'Turing', 'alan@example.com'),
        $member(14, 'Katherine', 'Johnson', 'katherine@example.com', 'Insider'),
    ];
    $by_id = array_column($members, null, 'id');
    $spaces = [
        ['id' => 101, 'name' => 'Welcome', 'slug' => 'welcome', 'space_type' => 'basic', 'is_private' => false, 'is_hidden_from_non_members' => false, 'is_post_disabled' => false, 'space_group' => ['id' => 1, 'name' => 'Start Here']],
        ['id' => 102, 'name' => 'Insiders', 'slug' => 'insiders', 'space_type' => 'basic', 'is_private' => true, 'is_hidden_from_non_members' => true, 'is_post_disabled' => true, 'space_group' => ['id' => 2, 'name' => 'Members Only']],
        ['id' => 104, 'name' => 'Starter Course', 'slug' => 'starter-course', 'space_type' => 'course', 'is_private' => true, 'is_hidden_from_non_members' => false, 'is_draft' => false, 'space_group' => ['id' => 2, 'name' => 'Members Only'], 'course_setting' => ['course_type' => 'self_paced']],
        ['id' => 103, 'name' => 'Lounge Chat', 'slug' => 'lounge-chat', 'space_type' => 'chat', 'is_private' => false, 'space_group' => ['id' => 1, 'name' => 'Start Here']],
    ];
    $post = fn ($id, $space, $author, $name, $body, $date, $topics = []) => [
        'id' => $id, 'status' => 'published', 'name' => $name, 'slug' => sanitize_title($name),
        'body' => ['id' => $id, 'body' => $body, 'record_type' => 'Post', 'record_id' => $id],
        'space_id' => $space, 'user_email' => $author ? $by_id[$author]['email'] : null, 'user_name' => $author ? $by_id[$author]['name'] : 'Deleted Member',
        'published_at' => $date, 'created_at' => $date, 'likes_count' => 3, 'comments_count' => 0, 'topics' => $topics,
    ];
    $posts = [
        $post(1001, 101, 11, 'Hello everyone', '<p>Welcome to the <strong>community</strong>! <script>alert(1)</script></p>', '2025-01-02T09:00:00.000Z', [501]),
        $post(1002, 101, 12, 'House rules', '<p>Be kind.</p><ul><li>No spam</li></ul>', '2025-01-03T10:00:00.000Z', [501, 502]),
        $post(1003, 101, 0, 'An old post', '<p>From someone who left.</p>', '2025-01-04T11:00:00.000Z'),
    ];
    for ($n = 1; $n <= 120; $n++) {
        $posts[] = $post(2000 + $n, 102, $n % 2 ? 14 : 11, "Insider note $n", "<p>Note number $n.</p>", sprintf('2025-02-%02dT12:00:00.000Z', 1 + $n % 28), [502]);
    }
    $user = fn ($id) => $id ? ['id' => $id, 'name' => $by_id[$id]['name'], 'email' => $by_id[$id]['email']] : ['id' => null, 'name' => 'Deleted Member', 'email' => null];
    $comment = fn ($id, $parent, $author, $body, $date, $replies = []) => [
        'id' => $id, 'parent_comment_id' => $parent, 'created_at' => $date, 'body' => ['id' => $id, 'body' => $body],
        'user' => $user($author), 'post' => ['id' => 1001, 'name' => 'Hello everyone'], 'space' => ['id' => 101, 'name' => 'Welcome'], 'replies' => $replies, 'replies_count' => count($replies),
    ];
    // As Circle lists them: top-level comments with their replies inside.
    $comments = [
        $comment(3001, null, 12, '<p>Glad to be here.</p>', '2025-01-02T10:00:00.000Z', [
            $comment(3002, 3001, 13, '<p>Same!</p>', '2025-01-02T11:00:00.000Z', [
                $comment(3003, 3002, 11, '<p>Welcome, Alan.</p>', '2025-01-02T12:00:00.000Z'),
            ]),
        ]),
        $comment(3004, null, 0, '<p>A comment from someone who left.</p>', '2025-01-03T08:00:00.000Z'),
    ];
    $space_members = [
        ['space_id' => 101, 'community_member_id' => 11, 'moderator' => false, 'status' => 'active'],
        ['space_id' => 101, 'community_member_id' => 12, 'moderator' => true, 'status' => 'active'],
        ['space_id' => 101, 'community_member_id' => 13, 'moderator' => false, 'status' => 'active'],
        ['space_id' => 102, 'community_member_id' => 11, 'moderator' => true, 'status' => 'active'],
        ['space_id' => 102, 'community_member_id' => 14, 'moderator' => false, 'status' => 'active'],
        ['space_id' => 104, 'community_member_id' => 12, 'moderator' => false, 'status' => 'active'],
        ['space_id' => 104, 'community_member_id' => 14, 'moderator' => false, 'status' => 'active'],
    ];
    foreach ($space_members as $i => $row) {
        $space_members[$i]['id'] = 900 + $i;
        $space_members[$i]['community_member'] = $by_id[$row['community_member_id']];
    }

    return [
        'community_members' => $members,
        'space_groups' => [['id' => 1, 'name' => 'Start Here', 'slug' => 'start-here'], ['id' => 2, 'name' => 'Members Only', 'slug' => 'members-only']],
        'spaces' => $spaces,
        'topics' => [['id' => 501, 'name' => 'Announcements', 'space_ids' => [101]], ['id' => 502, 'name' => 'Tips', 'space_ids' => [101, 102]], ['id' => 503, 'name' => 'Chat only', 'space_ids' => [103]]],
        'space_members' => $space_members,
        'posts' => $posts,
        'comments' => $comments,
        // As Circle lists them (in the course's order): sections, then lessons with their section.
        'course_sections' => [['id' => 701, 'name' => 'Getting Started', 'space_id' => 104], ['id' => 702, 'name' => 'Going Further', 'space_id' => 104]],
        'course_lessons' => [
            ['id' => 801, 'name' => 'Welcome to the course', 'status' => 'published', 'section_id' => 701, 'space_id' => 104, 'body_html' => '<p>Start <em>here</em>.</p><script>alert(1)</script>', 'is_comments_enabled' => true],
            ['id' => 802, 'name' => 'Setting up', 'status' => 'published', 'section_id' => 701, 'space_id' => 104, 'body_html' => '<p>Install the tools.</p>', 'is_comments_enabled' => true],
            ['id' => 803, 'name' => 'Advanced work', 'status' => 'draft', 'section_id' => 702, 'space_id' => 104, 'body_html' => '<p>Coming soon.</p>', 'is_comments_enabled' => false],
        ],
    ];
}

add_filter('pre_http_request', function ($pre, $args, $url) {
    $base = 'https://app.circle.so/api/admin/v2/';
    if (strpos($url, $base) !== 0) {
        return $pre;
    }
    update_option('circle_test_calls', (int) get_option('circle_test_calls', 0) + 1, false);
    $auth = (string) ($args['headers']['Authorization'] ?? '');
    $tokens = (array) get_option('circle_test_tokens', []);
    $tokens[] = $auth;
    update_option('circle_test_tokens', array_values(array_unique($tokens)), false);
    $answer = fn ($code, $body) => ['headers' => ['content-type' => 'application/json'], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    if ($auth !== 'Bearer ' . CIRCLE_TEST_TOKEN) {
        return $answer(401, ['message' => 'Your account could not be authenticated.']);
    }
    if (strtoupper((string) ($args['method'] ?? 'GET')) !== 'GET') {
        return $answer(405, ['message' => 'The test API only reads.']);
    }
    $parts = wp_parse_url($url);
    $path = trim(substr($parts['path'], strlen('/api/admin/v2/')), '/');
    parse_str($parts['query'] ?? '', $query);
    $data = circle_test_data();
    if (!isset($data[$path])) {
        return $answer(404, ['message' => 'Not found']);
    }
    $records = $data[$path];
    if (isset($query['space_id'])) {
        $space = (int) $query['space_id'];
        $records = array_values(array_filter($records, fn ($r) => (int) ($r['space_id'] ?? $r['space']['id'] ?? 0) === $space));
    }
    $per_page = min(100, max(1, (int) ($query['per_page'] ?? 10)));
    $page = max(1, (int) ($query['page'] ?? 1));
    $count = count($records);

    return $answer(200, [
        'page' => $page,
        'per_page' => $per_page,
        'has_next_page' => $page * $per_page < $count,
        'count' => $count,
        'page_count' => (int) ceil($count / $per_page),
        'records' => array_slice($records, ($page - 1) * $per_page, $per_page),
    ]);
}, 10, 3);
