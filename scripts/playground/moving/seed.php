<?php
// Seeds the source plugins for moving.mjs: Awesome Support tickets with replies, SliceWP affiliates with visits,
// commissions, customers and a payout, and a BuddyPress community with groups, members, posts and comments.
// Prints one JSON line with what it made.
require '/wordpress/wp-load.php';
wp_set_current_user(1);

// BuddyPress loads a component's code only on a request after it is turned on: turn on groups first, then ask to
// be run again.
if (!bp_is_active('groups')) {
    $components = array_merge((array) bp_get_option('bp-active-components', []), ['groups' => 1, 'activity' => 1, 'xprofile' => 1]);
    bp_update_option('bp-active-components', $components);
    require_once buddypress()->plugin_dir . 'bp-core/admin/bp-core-admin-schema.php';
    bp_core_install($components);
    echo json_encode(['again' => true]);
    return;
}

$errors = [];
$made = ['support' => [], 'affiliate' => [], 'community' => []];
$user = function (string $login, string $role = 'subscriber') {
    $id = username_exists($login);
    return $id ?: wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password(), 'role' => $role, 'first_name' => ucfirst($login), 'last_name' => 'Tester']);
};

// ---- Awesome Support: 3 tickets from 2 customers, 4 replies (two from the admin as agent).
try {
    $role = get_role('wpas_user') ? 'wpas_user' : 'subscriber';
    $ana = $user('ana', $role);
    $ben = $user('ben', $role);
    foreach ([$ana, $ben] as $id) {
        (new WP_User($id))->add_cap('create_ticket');
        (new WP_User($id))->add_cap('reply_ticket');
    }
    $tickets = [];
    foreach ([[$ana, 'Order never arrived', 'My order 1001 has not arrived.'], [$ana, 'Wrong size', 'I got a medium, not a large.'], [$ben, 'Invoice please', 'Can I have an invoice for order 1002?']] as [$author, $title, $content]) {
        $id = wpas_insert_ticket(['post_title' => $title, 'post_content' => $content, 'post_author' => $author], false, 1);
        if (!$id || is_wp_error($id)) {
            $errors[] = 'ticket ' . $title;
            continue;
        }
        $tickets[] = $id;
    }
    $replies = 0;
    foreach ([[0, 1, 'Sorry about that, it ships today.'], [0, $ana, 'Thank you!'], [1, 1, 'A large is on its way.'], [2, $ben, 'Any news?']] as [$t, $author, $content]) {
        if (isset($tickets[$t]) && wpas_add_reply(['post_content' => $content], $tickets[$t], $author)) {
            $replies++;
        }
    }
    $made['support'] = ['tickets' => count($tickets), 'replies' => $replies];
} catch (Throwable $e) {
    $errors[] = 'support: ' . $e->getMessage();
}

// ---- SliceWP: 3 affiliates, 4 visits, 2 customers, 3 commissions and 1 payout with its payment.
try {
    $now = gmdate('Y-m-d H:i:s');
    $affiliates = [];
    foreach (['cara', 'dev', 'eli'] as $login) {
        $affiliates[$login] = slicewp_insert_affiliate(['user_id' => $user($login), 'date_created' => $now, 'date_modified' => $now, 'payment_email' => $login . '@example.com', 'status' => 'active']);
    }
    $visits = [];
    foreach (['cara', 'cara', 'dev', 'eli'] as $i => $login) {
        $visits[] = slicewp_insert_visit(['affiliate_id' => $affiliates[$login], 'ip_address' => '10.0.0.' . ($i + 1), 'landing_url' => home_url('/?aff=' . $affiliates[$login]), 'referrer_url' => 'https://example.org/', 'date_created' => $now, 'date_modified' => $now]);
    }
    $customers = [];
    foreach (['fay', 'gus'] as $login) {
        $customers[] = slicewp_insert_customer(['email' => $login . '@example.com', 'first_name' => ucfirst($login), 'last_name' => 'Buyer', 'affiliate_id' => $affiliates['cara'], 'date_created' => $now, 'date_modified' => $now]);
    }
    $commissions = [];
    foreach ([['cara', 0, 12.5, 'paid', 1001], ['cara', 1, 7.25, 'unpaid', 1002], ['dev', 2, 20, 'unpaid', 1003]] as [$login, $v, $amount, $status, $ref]) {
        $commissions[] = slicewp_insert_commission(['affiliate_id' => $affiliates[$login], 'visit_id' => $visits[$v], 'customer_id' => $customers[0] ?? 0, 'date_created' => $now, 'date_modified' => $now, 'type' => 'sale', 'status' => $status, 'reference' => (string) $ref, 'origin' => 'manual', 'amount' => $amount, 'currency' => 'USD']);
    }
    $payout = slicewp_insert_payout(['date_created' => $now, 'date_modified' => $now, 'admin_id' => 1, 'amount' => 12.5, 'status' => 'completed']);
    $payment = slicewp_insert_payment(['affiliate_id' => $affiliates['cara'], 'amount' => 12.5, 'currency' => 'USD', 'payout_id' => $payout, 'status' => 'paid', 'commission_ids' => (string) $commissions[0], 'date_created' => $now, 'date_modified' => $now]);
    $made['affiliate'] = ['affiliates' => count(array_filter($affiliates)), 'visits' => count(array_filter($visits)), 'customers' => count(array_filter($customers)), 'commissions' => count(array_filter($commissions)), 'payouts' => $payout ? 1 : 0, 'payments' => $payment ? 1 : 0];
} catch (Throwable $e) {
    $errors[] = 'affiliate: ' . $e->getMessage();
}

// ---- BuddyPress: 2 groups, 3 memberships (one moderator) plus the creators', a banned member, a request, 3 posts (2 in groups), 2 comments.
try {
    $hana = $user('hana');
    $ivo = $user('ivo');
    $g1 = groups_create_group(['creator_id' => 1, 'name' => 'Gardeners', 'slug' => 'gardeners', 'description' => 'Grow things.', 'status' => 'public', 'enable_forum' => 0]);
    $g2 = groups_create_group(['creator_id' => 1, 'name' => 'Bakers', 'slug' => 'bakers', 'description' => 'Bake things.', 'status' => 'public', 'enable_forum' => 0]);
    groups_join_group($g1, $hana);
    groups_join_group($g1, $ivo);
    groups_join_group($g2, $hana);
    (new BP_Groups_Member($ivo, $g1))->promote('mod');
    // A banned member and an unanswered membership request in Bakers.
    $jo = $user('jo');
    $kim = $user('kim');
    groups_join_group($g2, $jo);
    (new BP_Groups_Member($jo, $g2))->ban();
    $request = new BP_Groups_Member();
    $request->group_id = $g2;
    $request->user_id = $kim;
    $request->inviter_id = 0;
    $request->is_confirmed = 0;
    $request->date_modified = bp_core_current_time();
    $request->save();
    $p1 = groups_post_update(['content' => 'First tomatoes of the year!', 'group_id' => $g1, 'user_id' => $hana]);
    $p2 = groups_post_update(['content' => 'Sourdough tips?', 'group_id' => $g2, 'user_id' => $hana]);
    $p3 = bp_activity_post_update(['content' => 'Hello everyone.', 'user_id' => $ivo]);
    $c1 = bp_activity_new_comment(['activity_id' => $p1, 'content' => 'They look great.', 'user_id' => $ivo]);
    $c2 = bp_activity_new_comment(['activity_id' => $p2, 'content' => 'Feed it twice a day.', 'user_id' => 1]);
    foreach ([1, $hana, $ivo] as $uid) {
        xprofile_set_field_data(1, $uid, get_userdata($uid)->display_name);
    }
    global $wpdb;
    $made['community'] = [
        'groups' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bp_groups"),
        'members' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bp_groups_members"),
        'moderators' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bp_groups_members WHERE is_mod = 1"),
        'banned' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bp_groups_members WHERE is_banned = 1"),
        'requests' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bp_groups_members WHERE is_confirmed = 0"),
        'posts' => count(array_filter([$p1, $p2, $p3])),
        'comments' => count(array_filter([$c1, $c2])),
    ];
} catch (Throwable $e) {
    $errors[] = 'community: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
}

echo json_encode(['seed' => $made, 'errors' => $errors]);
